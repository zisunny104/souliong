<?php
// 點位遷移共用邏輯（純函式，無副作用，可安全被多處 require）：把專案作者寫的靜態點位底稿
// （meta.json 的 points 檔名，例如 chairs.json／points.json）併入 spots.jsonl，讓點位資料收斂成
// spots.jsonl 單一真相來源——見 assets/js/viewer.core.js 的 effectiveSpots() 與 api/editspot.php，
// 兩處都已經只認 spots.jsonl 裡「有 num、無 edit_of」的紀錄當起點，遷移前的靜態底稿它們讀不到。
// 兩個呼叫端：api/spotmigrate.php（後台手動觸發、看得到遷移報告）與 pages/view.php
// （自動觸發：開頁時發現有底稿還沒併入就地補上，不必等人記得去後台按）。
require_once __DIR__ . '/store.php';

/**
 * 遷移前整包備份：把專案目錄現況打包成 ZIP，存在 projects/<proj>/_backup/ 底下——比 store_backup()
 * 單一滾動快照更完整（含靜態底稿、照片、其他 jsonl），遷移邏輯萬一有誤也能整個還原，不只回復
 * spots.jsonl 一個檔案。做法比照 api/manager.php 既有的 backup=project 匯出（同樣用 zip.php 打包
 * 整個 project_dir()、跳過 .rate 快取），差別只在這裡是寫進磁碟留存，不是串流下載給使用者。
 *
 * $overrides：['相對路徑' => 暫存檔絕對路徑]，用來取代該相對路徑原本會讀到的即時檔案內容——
 * 呼叫端如果對某個檔案持有 flock(LOCK_EX)，讓這裡再開一次同一個檔案讀，在 Windows 上會撞鎖
 * （LockFileEx 連同一行程的第二個控制代碼都擋，不像 POSIX 的 flock() 只擋有另外呼叫 flock()
 * 的讀者），讀到的內容會是空的、CRC 對不上。呼叫端應把鎖住那份檔案「鎖定當下的既有內容」先
 * 寫成暫存檔，用這個參數指定改讀暫存檔。
 */
function spotmigrate_backup_project(array $cfg, string $proj, array $overrides = []): ?string {
    require_once __DIR__ . '/zip.php';
    $absDir = project_dir($cfg, $proj);
    if (!is_dir($absDir)) return null;
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absDir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if (!$f->isFile()) continue;
        $path = $f->getPathname();
        if (strpos($path, DIRECTORY_SEPARATOR . '.rate' . DIRECTORY_SEPARATOR) !== false) continue;
        if (strpos($path, DIRECTORY_SEPARATOR . '_backup' . DIRECTORY_SEPARATOR) !== false) continue;
        $rel = ltrim(str_replace('\\', '/', substr($path, strlen($absDir))), '/');
        $files[$proj . '/' . $rel] = $overrides[$rel] ?? $path;
    }
    $backupDir = $absDir . '/_backup';
    if (!is_dir($backupDir)) @mkdir($backupDir, 0775, true);
    $out = $backupDir . '/pre-spotmigrate-' . date('Ymd-His') . '.zip';
    return zip_pack($out, $files) ? $out : null;
}

/** 這筆紀錄拿來給人看的名稱：不同專案的靜態底稿命名欄位不一樣（100chairs 用 theme，一般地圖用
 *  title），只是遷移報告要顯示的標籤，不是欄位規範本身——欄位規範仍是各專案自己的底稿決定。 */
function spotmigrate_label(array $r): string {
    foreach (['theme', 'title', 'chair', 'name'] as $k) {
        if (!empty($r[$k]) && is_string($r[$k])) return $r[$k];
    }
    return '#' . (string)($r['num'] ?? '?');
}

/**
 * 輕量、不加鎖（沿用 _store_read_lines() 的 LOCK_SH）預檢：這個專案的靜態底稿是否還有 num
 * 沒出現在 spots.jsonl 的起點紀錄裡。用來決定值不值得呼叫 spotmigrate_run()（它會開檔加
 * LOCK_EX 改寫）——已經遷移過的專案是絕大多數請求，不必為了確認「沒事要做」去搶獨佔鎖。
 */
function spotmigrate_needed(array $cfg, string $proj): bool {
    $dir = project_dir($cfg, $proj);
    $meta = json_decode((string)@file_get_contents($dir . '/meta.json'), true);
    if (!is_array($meta)) $meta = [];
    $staticF = $dir . '/' . (string)($meta['points'] ?? 'points.json');
    if (!is_file($staticF)) return false;
    $staticPoints = json_decode((string)file_get_contents($staticF), true);
    if (!is_array($staticPoints) || !$staticPoints) return false;

    $existingNums = [];
    foreach (_store_read_lines(store_file($cfg, $proj, 'spot')) as $r) {
        if (empty($r['edit_of']) && isset($r['num'])) $existingNums[(int)$r['num']] = true;
    }
    foreach ($staticPoints as $p) {
        if (is_array($p) && isset($p['num']) && !isset($existingNums[(int)$p['num']])) return true;
    }
    return false;
}

/**
 * 對單一專案執行遷移，回傳結果供頁面／AJAX 顯示，也可以是自動觸發時的內部呼叫（呼叫端不理會
 * 回傳值）。冪等：num 已存在於某筆起點紀錄就跳過；孤兒編輯只有在剛好對到這次新合成的起點時才
 * 會被改寫，已經有 edit_of 的紀錄不會再被碰。
 */
function spotmigrate_run(array $cfg, string $proj): array {
    $dir  = project_dir($cfg, $proj);
    $meta = json_decode((string)@file_get_contents($dir . '/meta.json'), true);
    if (!is_array($meta)) $meta = [];
    $staticName = (string)($meta['points'] ?? 'points.json');
    $staticF = $dir . '/' . $staticName;
    $staticPoints = is_file($staticF) ? json_decode((string)file_get_contents($staticF), true) : null;
    if (!is_array($staticPoints) || !$staticPoints) {
        return ['project' => $proj, 'skipped' => true, 'reason' => 'no_static', 'static_file' => $staticName, 'added' => [], 'repointed' => [], 'backup' => null];
    }

    $f = store_file($cfg, $proj, 'spot');
    $fp = fopen($f, 'c+b'); // c+：不存在就建、存在也不截斷（理由同 store_append_locked()）
    if (!$fp) throw new RuntimeException('cannot open store file');
    flock($fp, LOCK_EX);
    $added = [];
    $repointed = [];
    try {
        $lines = [];
        $records = [];
        rewind($fp);
        while (($line = fgets($fp)) !== false) {
            $line = trim($line);
            if ($line === '') continue;
            $rec = json_decode($line, true);
            if (is_array($rec)) { $lines[] = $line; $records[] = $rec; }
        }

        $existingNums = [];
        foreach ($records as $r) {
            if (empty($r['edit_of']) && isset($r['num'])) $existingNums[(int)$r['num']] = true;
        }

        $now = gmdate('c');
        $addedIdByNum = [];
        $newRecords = [];
        foreach ($staticPoints as $p) {
            if (!is_array($p) || !isset($p['num'])) continue;
            $num = (int)$p['num'];
            if (isset($existingNums[$num])) continue;   // 已遷移過，跳過
            $rec = $p;
            $rec['id']         = bin2hex(random_bytes(8));
            $rec['project']    = $proj;
            $rec['kind']       = 'spot';
            $rec['num']        = $num;
            $rec['item_num']   = $num;
            $rec['created_at'] = $now;
            unset($rec['edit_of']);
            $newRecords[] = $rec;
            $addedIdByNum[$num] = $rec['id'];
            $added[] = ['num' => $num, 'id' => $rec['id'], 'label' => spotmigrate_label($rec)];
        }

        // 孤兒編輯：edit_of 空、沒有 num（不是起點）、item_num 剛好對到這次新合成的起點
        $newLines = [];
        foreach ($records as $i => $r) {
            $itemNum = isset($r['item_num']) ? (int)$r['item_num'] : null;
            if (empty($r['edit_of']) && !isset($r['num']) && $itemNum !== null && isset($addedIdByNum[$itemNum])) {
                $r['edit_of'] = $addedIdByNum[$itemNum];
                $repointed[] = ['item_num' => $itemNum, 'id' => (string)($r['id'] ?? '')];
                $newLines[] = json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                $newLines[] = $lines[$i];
            }
        }

        $backupZip = null;
        if ($added || $repointed) {
            // 先整包備份舊專案，備份失敗就不寫入——寧可這次遷移沒發生，也不要留下改壞又救不回的狀態。
            // spots.jsonl 這份自己正鎖著，讓備份改讀「鎖定當下內容」的暫存檔，見
            // spotmigrate_backup_project() 開頭註解——不能讓它直接開同一個檔案第二次讀。
            $spotSnap = tempnam(sys_get_temp_dir(), 'skspot');
            file_put_contents($spotSnap, $lines ? implode("\n", $lines) . "\n" : '');
            $backupZip = spotmigrate_backup_project($cfg, $proj, ['spots.jsonl' => $spotSnap]);
            @unlink($spotSnap);
            if ($backupZip === null) {
                throw new RuntimeException('spot migration backup failed, aborting write for project ' . $proj);
            }
            if ($repointed) {
                store_backup($f);
            }
            foreach ($newRecords as $rec) {
                $newLines[] = json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            ftruncate($fp, 0);
            rewind($fp);
            foreach ($newLines as $l) fwrite($fp, $l . "\n");
            fflush($fp);
        }
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    return ['project' => $proj, 'skipped' => false, 'reason' => null, 'static_file' => $staticName, 'added' => $added, 'repointed' => $repointed, 'backup' => $backupZip];
}
