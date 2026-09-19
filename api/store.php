<?php
/**
 * 純 PHP 檔案儲存（零擴充依賴，取代 SQLite）。
 * 每個專案兩個 JSON-Lines 檔：projects/<project>/spots.jsonl（kind:'spot'，點位本身）與
 * projects/<project>/entries.jsonl（其餘所有投稿），一行一筆記錄，依 kind 分流見 store_file()。
 * 寫入用 LOCK_EX 附加、讀取用 LOCK_SH，append-only。
 *
 * 舊專案目錄下可能還留著遷移前的 data.jsonl：那是遷移腳本讀完之後刻意留下的唯讀存底，
 * 從這裡開始的所有函式都不會再讀寫它。
 *
 * 已淘汰、只留給舊資料相容用的舊機制：data.jsonl 本身、kind 值 point／newpoint（已併入 spot，
 * 見 store_file()）、primaryKind（已由 spot 記錄的 content 欄位取代）。退場判準與流程見
 * docs/EXTENDING.md「舊機制淘汰與退場」一節，判準腳本見 tools/retirecheck.php。
 */

function project_dir(array $cfg, string $project): string {
    return rtrim($cfg['projects_dir'], '/\\') . '/' . $project;
}
function store_file(array $cfg, string $project, ?string $kind = null): string {
    $name = $kind === 'spot' ? 'spots.jsonl' : 'entries.jsonl';
    return project_dir($cfg, $project) . '/' . $name;
}

/** 把 jsonl 裡存的 "<project>/<檔名>" 解析成照片實際檔案路徑；格式不符回 null */
function photo_abs_path(array $cfg, string $photoRel): ?string {
    if (!preg_match('#^([a-z0-9_-]+)/([A-Za-z0-9_.-]+)$#', $photoRel, $m)) return null;
    return project_dir($cfg, $m[1]) . '/photos/' . $m[2];
}

/**
 * 同 photo_abs_path()，但指向 media/（影片與音訊）。
 * 影音沒有跟照片共用 photos/ 目錄，是為了讓既有的照片工具（exiffix.php／thumbfix.php、
 * 以及所有把 photos/ 當「全都是圖檔」在掃的程式）不用學會忽略非圖檔，見 features.php
 * 的 souliong_kinds() 說明。
 */
function media_abs_path(array $cfg, string $mediaRel): ?string {
    if (!preg_match('#^([a-z0-9_-]+)/([A-Za-z0-9_.-]+)$#', $mediaRel, $m)) return null;
    return project_dir($cfg, $m[1]) . '/media/' . $m[2];
}

/**
 * 刪掉一筆記錄附帶的檔案（主檔 ＋ 縮圖）。刪投稿的地方有三處（delete.php、manager.php 的
 * 單筆刪除與整批刪某身分），影音上線後每一處都要多記得清 media/ 一次；集中在這裡，
 * 之後再加新的檔案欄位也只有這一個地方要改。
 * 縮圖一律是 <主檔名>_t.<ext>（photo.php 自動產生的、上傳附帶的、影片抽幀的都同一套命名）。
 */
function store_purge_files(array $cfg, ?array $record): void {
    if (!$record) return;
    $paths = [];
    if (!empty($record['photo'])) $paths[] = photo_abs_path($cfg, (string)$record['photo']);
    if (!empty($record['media'])) $paths[] = media_abs_path($cfg, (string)$record['media']);
    foreach ($paths as $abs) {
        if (!$abs) continue;
        @unlink($abs);
        $base = preg_replace('/\.[A-Za-z0-9]+$/', '', $abs);
        foreach (['webp', 'jpg', 'png'] as $te) @unlink($base . '_t.' . $te);
    }
}

/** 讀單一 jsonl 檔案的全部記錄（LOCK_SH）；檔案不存在回空陣列。 */
function _store_read_lines(string $f): array {
    if (!is_file($f)) return [];
    $fp = fopen($f, 'rb');
    if (!$fp) return [];
    flock($fp, LOCK_SH);
    $out = [];
    while (($line = fgets($fp)) !== false) {
        $line = trim($line);
        if ($line === '') continue;
        $rec = json_decode($line, true);
        if (is_array($rec)) $out[] = $rec;
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $out;
}

function store_all(array $cfg, string $project): array {
    return array_merge(
        _store_read_lines(store_file($cfg, $project, 'spot')),
        _store_read_lines(store_file($cfg, $project, null))
    );
}

function store_append(array $cfg, string $project, array $record): array {
    $f = store_file($cfg, $project, $record['kind'] ?? null);
    $fp = fopen($f, 'ab');
    if (!$fp) throw new RuntimeException('cannot open store file');
    flock($fp, LOCK_EX);
    fwrite($fp, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $record;
}

/**
 * 「要先看過現有資料才能決定寫什麼」的附加（例如建立地點要配一個沒被用過的 num）。
 *
 * 不能用 store_all() + store_append() 兜出來：那是兩段各自上鎖的區間，兩個人同時建點會
 * 各自讀到同一個 max num、配出重複號碼。這裡把讀與寫包在同一個 LOCK_EX 區間裡，
 * 後到的那個一定看得到先到的那筆。
 *
 * $build(array $records): array —— 收到目前檔案裡的全部記錄，回傳要附加的那一筆。
 * 想中止就在 $build 裡丟例外（此時什麼都不會寫入）。
 * $kind 決定鎖的是哪個實體檔案（見 store_file()）；$build 收到的 $records 只有該檔案裡的記錄。
 */
function store_append_locked(array $cfg, string $project, callable $build, string $kind): array {
    $f = store_file($cfg, $project, $kind);
    $fp = fopen($f, 'c+b'); // c+：不存在就建、存在也不截斷（'a+' 在部分平台讀取位置不可靠）
    if (!$fp) throw new RuntimeException('cannot open store file');
    flock($fp, LOCK_EX);
    try {
        $records = [];
        rewind($fp);
        while (($line = fgets($fp)) !== false) {
            $line = trim($line);
            if ($line === '') continue;
            $rec = json_decode($line, true);
            if (is_array($rec)) $records[] = $rec;
        }
        $record = $build($records);
        fseek($fp, 0, SEEK_END);
        fwrite($fp, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        fflush($fp);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
    return $record;
}

/** 在毀滅性覆寫（刪除重寫、ZIP 還原）前，把即將被改的檔案複製成同名 .bak（單一滾動快照，覆蓋上一份）。 */
function store_backup(string $path): void {
    if (is_file($path)) @copy($path, $path . '.bak');
}

/**
 * 整檔重寫、跳過符合 $shouldRemove() 的那些行（store_delete()／store_delete_by() 共用）。
 * 回傳被移除的記錄陣列；檔案不存在就什麼都不做。
 */
function _store_rewrite(string $path, callable $shouldRemove): array {
    if (!is_file($path)) return [];
    $fp = fopen($path, 'c+b');
    if (!$fp) return [];
    flock($fp, LOCK_EX);
    $keep = [];
    $removed = [];
    while (($line = fgets($fp)) !== false) {
        $t = trim($line);
        if ($t === '') continue;
        $rec = json_decode($t, true);
        if (is_array($rec) && $shouldRemove($rec)) { $removed[] = $rec; continue; }
        $keep[] = $t;
    }
    if ($removed) {
        store_backup($path);
        ftruncate($fp, 0);
        rewind($fp);
        foreach ($keep as $l) fwrite($fp, $l . "\n");
        fflush($fp);
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $removed;
}

/** 依 id 找一筆記錄；不論它落在 spots.jsonl 或 entries.jsonl 都找得到。 */
function store_find(array $cfg, string $project, string $id): ?array {
    foreach (store_all($cfg, $project) as $r) {
        if ((string)($r['id'] ?? '') === $id) return $r;
    }
    return null;
}

function store_delete(array $cfg, string $project, string $id): ?array {
    foreach ([store_file($cfg, $project, 'spot'), store_file($cfg, $project, null)] as $f) {
        $removed = _store_rewrite($f, fn($rec) => (string)($rec['id'] ?? '') === (string)$id);
        if ($removed) return $removed[0];
    }
    return null;
}

/**
 * 依欄位值批次刪除（例如某個 contrib_id 或 owner_hash 的全部投稿）；回傳被刪除的記錄陣列供呼叫端清照片檔。
 * $excludeKinds：即使符合條件也不刪、留在檔案裡不動的 kind 清單（見 manager.php 的 edit_spots 分流）。
 */
function store_delete_by(array $cfg, string $project, string $field, string $value, array $excludeKinds = []): array {
    if ($value === '') return [];
    $shouldRemove = function ($rec) use ($field, $value, $excludeKinds) {
        if ((string)($rec[$field] ?? '') !== $value) return false;
        return !in_array($rec['kind'] ?? null, $excludeKinds, true);
    };
    return array_merge(
        _store_rewrite(store_file($cfg, $project, 'spot'), $shouldRemove),
        _store_rewrite(store_file($cfg, $project, null), $shouldRemove)
    );
}

/**
 * 就地修補單筆記錄的指定欄位（唯一打破 append-only 的例外，僅供資料修復工具（如 exiffix.php）使用，
 * 例如補救誤存為 null 的欄位；一般編輯一律走 store_append 版本化，不要用這個）。
 */
function store_patch(array $cfg, string $project, string $id, array $fields): ?array {
    $f = store_file($cfg, $project);
    if (!is_file($f)) return null;
    $fp = fopen($f, 'c+b');
    if (!$fp) return null;
    flock($fp, LOCK_EX);
    $lines = [];
    $patched = null;
    while (($line = fgets($fp)) !== false) {
        $t = trim($line);
        if ($t === '') continue;
        $rec = json_decode($t, true);
        if (is_array($rec) && (string)($rec['id'] ?? '') === $id) {
            $rec = array_merge($rec, $fields);
            $patched = $rec;
            $t = json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $lines[] = $t;
    }
    if ($patched !== null) {
        ftruncate($fp, 0);
        rewind($fp);
        foreach ($lines as $l) fwrite($fp, $l . "\n");
        fflush($fp);
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $patched;
}

function store_projects(array $cfg): array {
    $dir = rtrim($cfg['projects_dir'], '/\\');
    $out = [];
    foreach ((array)@scandir($dir) as $name) {
        if ($name === '.' || $name === '..') continue;
        if (is_dir($dir . '/' . $name)) $out[] = $name;
    }
    return $out;
}

/**
 * 目錄底下所有檔案大小遞迴加總。跟 manager.php 的 backup=all 用的 $addDir closure
 * 同一套 RecursiveIteratorIterator 寫法，但只加總不收集檔案清單。
 */
function souliong_dir_bytes(string $dir): int {
    if ($dir === '' || !is_dir($dir)) {
        return 0;
    }
    $sum = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile() && !$f->isLink()) {
            $sum += $f->getSize();
        }
    }
    return $sum;
}

function souliong_storage_cache_path(array $cfg): string {
    return rtrim($cfg['state_dir'], '/\\') . '/storage.json';
}

/** 讀取容量快取；沒算過或格式壞掉回 null，呼叫端要能分辨「還沒算」跟「算出來是 0」。 */
function souliong_storage_cache(array $cfg): ?array {
    $f = souliong_storage_cache_path($cfg);
    if (!is_file($f)) {
        return null;
    }
    $data = json_decode((string)@file_get_contents($f), true);
    return is_array($data) ? $data : null;
}

/**
 * 全站容量遞迴掃描的實際運算，不含寫檔——寫進 state/storage.json 是呼叫端（manager.php 的
 * action=storagerecalc）的事，這裡只負責算出數字。刻意不在頁面每次載入時呼叫這個函式：
 * manager.php 後台頁一次把所有分頁 render 出來，前端只是切換顯示，即時算會讓每次載入都
 * 遞迴掃過所有專案的圖磚金字塔，太重。
 */
function souliong_storage_compute(array $cfg): array {
    $layers = ['' => []];
    foreach (souliong_layer_list($cfg) as $id => $info) {
        if (!souliong_layer_is_local($info)) continue;
        $layers[''][$id] = souliong_dir_bytes(souliong_layer_dir($cfg, $id));
    }
    $packs = ['' => []];
    foreach (souliong_pack_list($cfg) as $id => $info) {
        $packs[''][$id] = souliong_dir_bytes(souliong_pack_dir($cfg, $id));
    }
    $uploads = [];
    foreach (store_projects($cfg) as $p) {
        $pdir = project_dir($cfg, $p);
        $uploads[$p] = [
            'photos' => souliong_dir_bytes($pdir . '/photos'),
            'media'  => souliong_dir_bytes($pdir . '/media'),
        ];
        $layers[$p] = [];
        foreach (souliong_layer_list($cfg, $p) as $id => $info) {
            if (($info['scope'] ?? '') !== 'project' || !souliong_layer_is_local($info)) continue;
            $bytes = souliong_dir_bytes(souliong_layer_dir($cfg, $id, $p));
            $bytes += souliong_layersrc_bytes(souliong_layersrc_dir($cfg, $p, $id));
            $layers[$p][$id] = $bytes;
        }
        $packs[$p] = [];
        foreach (souliong_pack_list($cfg, $p) as $id => $info) {
            if (($info['scope'] ?? '') !== 'project') continue;
            $packs[$p][$id] = souliong_dir_bytes(souliong_pack_dir($cfg, $id, $p));
        }
    }
    return ['computed_at' => time(), 'layers' => $layers, 'packs' => $packs, 'uploads' => $uploads];
}

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
