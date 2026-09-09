<?php
// 常駐工具：把一張自繪插畫切成圖磚金字塔，落地成一個「專案層」（projects/<proj>/layers/<id>/）。
//
// 為什麼切磚而不是直接用一張 ImageOverlay：單張圖在放大時整張都要下載且會糊掉，A1 尺寸的手繪
// 稿動輒數十 MB；切成金字塔後瀏覽器只抓看得到的那幾格，還能逐級換解析度。缺點是檔案數量多，
// 而這正是「專案層不進版控」（projects/* 本來就 gitignore）要解決的問題。
//
// ── 為什麼切割放在瀏覽器 ──
// 跟 thumbfix.php 同一個理由：PHP 端要吃下一張上億像素的來源圖，memory_limit 與 max_execution_time
// 都會先倒下，而且主機不一定編了 WebP。瀏覽器的 canvas 沒有這些限制，還天然支援 SVG 來源。
// PHP 這邊只負責「收下已經切好的 256×256 小圖並放到正確路徑」，每一張都重新驗過型別與座標。
//
// ── 稀疏 ──
// 全透明的磚根本不上傳。缺磚由 layerfile.php 回 68 bytes 的透明 PNG（見該檔），所以「沒畫到的
// 地方」既不佔硬碟也不會在主控台印錯誤。layer.json 再寫入 bounds，Leaflet 連範圍外的請求都不發。
//
// ── 幾何 ──
// 影像四角在 Web Mercator 投影空間線性對應，與 Leaflet 的 L.imageOverlay 一致——這是刻意的：
// 同一張圖「切磚前用 ImageOverlay 預覽」與「切磚後用 tileLayer 顯示」必須長得一模一樣，
// 否則對位工具就白做了。
require __DIR__ . '/store.php';
require __DIR__ . '/security.php';
require __DIR__ . '/i18n.php';
require_once __DIR__ . '/routes.php';   // 網址表：後台網址只有這一份定義（見 api/routes.php）
require_once __DIR__ . '/layers.php';
$cfg = require __DIR__ . '/config.php';
rate_limit($cfg, 'admin');
[$LANG, $DICT] = i18n_init();
$t  = fn(string $key, array $vars = []): string => htmlspecialchars(i18n_t($DICT, $key, $vars), ENT_QUOTES);
$tr = fn(string $key, array $vars = []): string => i18n_t($DICT, $key, $vars);
$esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// 「回後台」連結一律由 Route 產生（理由同 thumbfix.php：相對網址會被路由誤判）
$backProject = preg_replace('/[^a-z0-9_-]/', '', $_GET['project'] ?? '');
$adminUrl = $esc(Route::abs(Route::manager($backProject, 'tools')));

// 這支寫的是專案層，所以權限跟著專案走（比照 manager.php 的 layerimport）：主要管理者通吃，
// 專案管理者只能動自己那張地圖。thumbfix 之類的全站維護工具是 primary only，這支不是。
$primary = admin_authed($cfg);
$canProj = function (string $p) use ($cfg, $primary): bool {
    return $p !== '' && preg_match('/^[a-z0-9_-]+$/', $p) === 1
        && is_dir(project_dir($cfg, $p))
        && ($primary || admin_can($cfg, $p));
};
$auditWho = fn(string $p) => $primary ? 'primary' : (($acc = account_current($cfg)) !== null ? 'acct:' . $acc['id'] : 'pin:' . (string)padm_pin_id($cfg, $p));
// 依身份派生 CSRF token：primary 用 admin_derived()，帳號用 account_derived()，專案 PIN 用
// padm_derived()。與 manager.php 574-576 行同一套規則。
$csrfFor = function (string $p) use ($cfg, $primary): string {
    if ($primary) {
        return admin_derived($cfg);
    }
    $acc = account_current($cfg);
    return $acc !== null ? account_derived($cfg, (string)$acc['id']) : padm_derived($cfg, $p, (string)padm_pin_id($cfg, $p));
};

/** 這個圖層在磁碟上的位置；專案不合法或 projects_dir 沒設回 null。 */
function tilecut_dir(array $cfg, string $project, string $id): ?string
{
    $root = souliong_layer_roots($cfg, $project)['project'] ?? '';
    if ($root === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $id)) {
        return null;
    }
    return rtrim($root, '/\\') . '/' . $id;
}

// ── 讀回原稿（GET）：這是「保留原稿」唯一的出口 ──
// layerfile.php 走不到 layersrc/（不同的母目錄，見 souliong_layersrc_dir() 的說明），所以沒有
// 「網址猜對就拿得到高解析手稿」這種事。這條路查的是專案管理權，跟寫入端同一套。
if (($_GET['action'] ?? '') === 'srcfile') {
    $project = preg_replace('/[^a-z0-9_-]/', '', $_GET['project'] ?? '');
    $id   = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['id'] ?? ''));
    $file = (string)($_GET['file'] ?? '');
    $sdir = souliong_layersrc_dir($cfg, $project, $id);
    // 檔名不是「使用者取的那個」而是工具自己編的 p<idx>.<ext>，所以這裡可以整個寫死成一條規則
    if (!$canProj($project) || $sdir === null || !preg_match('/^p\d{1,2}\.(png|webp|jpg|jpeg|svg)$/', $file)) {
        http_response_code(404);
        exit;
    }
    $path = $sdir . '/' . $file;
    if (is_link($path) || !is_file($path)) {
        http_response_code(404);
        exit;
    }
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header('Content-Type: ' . souliong_layer_mimes()[$ext]);
    header('Content-Length: ' . (string)filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');   // 原稿不該躺在任何共用快取裡
    if ($ext === 'svg') {
        // 同 layerfile.php：SVG 可能內嵌 <script>，靠 CSP 讓它什麼都做不了
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
    }
    readfile($path);
    exit;
}

// ── 開工：建立（或清空）圖層資料夾（POST，JSON 回應） ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'begin') {
    $project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
    if (!hash_equals($csrfFor($project), (string)($_POST['csrf'] ?? ''))) {
        json_out(['error' => $tr('csrf_invalid_ajax_msg')], 403);
    }
    if (!$canProj($project)) {
        json_out(['error' => $tr('no_permission_title')], 403);
    }
    $id  = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '', $_POST['id'] ?? ''));
    $dir = tilecut_dir($cfg, $project, $id);
    if ($dir === null) {
        json_out(['error' => $tr('tilecut_bad_id_msg')], 400);
    }
    $existed = is_dir($dir);
    if ($existed && empty($_POST['overwrite'])) {
        json_out(['error' => $tr('tilecut_exists_msg', ['id' => $id])], 409);
    }
    // 清不乾淨就不往下走：舊磚留著會變成擦不掉的殘影，寧可當場說失敗，也不要交出一張半新半舊的圖層。
    if ($existed && is_dir($dir . '/tiles') && !souliong_layer_rmtree($dir . '/tiles', $dir)) {
        json_out(['error' => $tr('tilecut_clear_failed_msg')], 500);
    }
    // 原稿一樣要清。留著上一版的圖片，「載回重編」就會拿到一疊跟現在的圖磚對不起來的東西；
    // 這一版到底有沒有要留原稿，由接下來有沒有 srcput 決定——沒有就是真的沒有了（UI 有說明）。
    $sdir = souliong_layersrc_dir($cfg, $project, $id);
    if ($sdir !== null && is_dir($sdir) && !souliong_layer_rmtree($sdir, dirname($sdir))) {
        json_out(['error' => $tr('tilecut_clear_failed_msg')], 500);
    }
    // 上一版若是「保持向量」，這一版改切磚時，vector.svg 不會被上面兩段清到，會變成孤兒檔：
    // layer.json 已經改指向 tiles/，但它還留在資料夾裡，得在這裡順手清掉。
    if ($existed && is_file($dir . '/vector.svg') && !@unlink($dir . '/vector.svg')) {
        json_out(['error' => $tr('tilecut_clear_failed_msg')], 500);
    }
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        json_out(['error' => $tr('tilecut_mkdir_failed_msg')], 500);
    }
    json_out(['ok' => true, 'replaced' => $existed]);
}

// ── 收磚：一次一批（POST，JSON 回應） ──
// 欄位名就是座標：tiles[<z>_<x>_<y>]。不用另外傳一份對照表，省掉「檔案與座標對不上」這種錯。
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'tile') {
    $project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
    if (!hash_equals($csrfFor($project), (string)($_POST['csrf'] ?? ''))) {
        json_out(['error' => $tr('csrf_invalid_ajax_msg')], 403);
    }
    if (!$canProj($project)) {
        json_out(['error' => $tr('no_permission_title')], 403);
    }
    $id  = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '', $_POST['id'] ?? ''));
    $dir = tilecut_dir($cfg, $project, $id);
    if ($dir === null || !is_dir($dir)) {
        json_out(['error' => $tr('layer_not_found_msg')], 404);
    }
    $accept = ['image/png' => 'png', 'image/webp' => 'webp'];
    $saved = 0;
    $rejected = 0;
    foreach ((array)($_FILES['tiles']['tmp_name'] ?? []) as $key => $tmp) {
        // 每一張都獨立驗過：座標形狀、該 zoom 的合法範圍、大小、真實型別、像素尺寸。
        // 「客戶端剛剛才產生這些檔」不構成信任的理由——這是一支公開端點。
        if (($_FILES['tiles']['error'][$key] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !preg_match('/^(\d{1,2})_(\d{1,9})_(\d{1,9})$/', (string)$key, $mm)) {
            $rejected++;
            continue;
        }
        [, $z, $x, $y] = array_map('intval', $mm);
        $span = 1 << $z;
        $info = ($z <= 24 && $x < $span && $y < $span
            && ($_FILES['tiles']['size'][$key] ?? 0) <= 512 * 1024
            && is_uploaded_file($tmp)) ? @getimagesize($tmp) : false;
        $ext = is_array($info) ? ($accept[$info['mime'] ?? ''] ?? null) : null;
        if ($ext === null || $info[0] > 512 || $info[1] > 512) {
            $rejected++;
            continue;
        }
        $sub = $dir . '/tiles/' . $z . '/' . $x;
        if (!is_dir($sub)) {
            @mkdir($sub, 0775, true);
        }
        if (move_uploaded_file($tmp, $sub . '/' . $y . '.' . $ext)) {
            $saved++;
        } else {
            $rejected++;
        }
    }
    json_out(['ok' => true, 'saved' => $saved, 'rejected' => $rejected]);
}

// ── 收原稿：分塊上傳（POST，JSON 回應） ──
//
// 為什麼要分塊：原稿動輒數十 MB，而 upload_max_filesize 常見的預設值是 2M，虛擬主機又多半不
// 給改。與其要求使用者去動 php.ini，不如切成「伺服器一定吞得下」的大小送——每一塊多大由
// 這一頁自己讀 ini 算出來告訴前端（見 $srcChunk）。
//
// 每一塊都帶 offset，伺服器比對現有長度符合才接：重送一塊已經落地的資料不會變成接兩次，
// 這是分塊上傳唯一真正麻煩的地方（限流重試時一定會發生）。
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'srcput') {
    $project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
    if (!hash_equals($csrfFor($project), (string)($_POST['csrf'] ?? ''))) {
        json_out(['error' => $tr('csrf_invalid_ajax_msg')], 403);
    }
    if (!$canProj($project)) {
        json_out(['error' => $tr('no_permission_title')], 403);
    }
    $id  = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '', $_POST['id'] ?? ''));
    $dir = tilecut_dir($cfg, $project, $id);
    $sdir = souliong_layersrc_dir($cfg, $project, $id);
    if ($dir === null || $sdir === null || !is_dir($dir)) {
        json_out(['error' => $tr('layer_not_found_msg')], 404);   // begin 沒跑過就不該有原稿
    }
    $idx = (int)($_POST['idx'] ?? -1);
    $off = (int)($_POST['offset'] ?? -1);
    $tmp = (string)($_FILES['chunk']['tmp_name'] ?? '');
    if ($idx < 0 || $idx > 63 || $off < 0
        || ($_FILES['chunk']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
        json_out(['error' => $tr('tilecut_src_chunk_failed_msg')], 400);
    }
    if (!is_dir($sdir) && !@mkdir($sdir, 0775, true)) {
        json_out(['error' => $tr('tilecut_mkdir_failed_msg')], 500);
    }
    $lim  = souliong_layersrc_limits($cfg);
    $size = (int)($_FILES['chunk']['size'] ?? 0);
    // 半成品先叫 .p<idx>：驗過型別才改名成 p<idx>.<ext>，中途斷線留下的殘骸不會被當成原稿
    $part = $sdir . '/.p' . $idx;
    if ($off === 0) {
        @unlink($part);
    }
    $have = is_file($part) ? (int)filesize($part) : 0;
    if ($have !== $off) {
        json_out(['error' => $tr('tilecut_src_resend_msg'), 'have' => $have], 409);
    }
    if ($off + $size > $lim['file'] || souliong_layersrc_bytes($sdir) + $size > $lim['total']) {
        json_out(['error' => $tr('tilecut_src_too_big_msg', [
            'file'  => (string)(int)round($lim['file'] / 1048576),
            'total' => (string)(int)round($lim['total'] / 1048576),
        ])], 413);
    }
    $in  = @fopen($tmp, 'rb');
    $out = $in ? @fopen($part, $off === 0 ? 'wb' : 'ab') : false;
    $ok  = $in && $out && stream_copy_to_stream($in, $out) !== false;
    if ($in) {
        fclose($in);
    }
    if ($out) {
        fclose($out);
    }
    if (!$ok) {
        json_out(['error' => $tr('tilecut_src_chunk_failed_msg')], 500);
    }
    if (empty($_POST['last'])) {
        json_out(['ok' => true, 'have' => (int)filesize($part)]);
    }
    // 最後一塊到齊，整個檔在手上了才驗型別——分塊送到一半的檔案本來就認不出是什麼
    $info = @getimagesize($part);
    $ext  = null;
    if (is_array($info)) {
        $ext = ['image/png' => 'png', 'image/webp' => 'webp', 'image/jpeg' => 'jpg'][$info['mime'] ?? ''] ?? null;
        if ($ext !== null && ((int)$info[0] > 65500 || (int)$info[1] > 65500)) {
            $ext = null;   // 邊長超過這個數的圖，瀏覽器自己也畫不出來
        }
    } elseif (stripos((string)@file_get_contents($part, false, null, 0, 1024), '<svg') !== false) {
        // getimagesize() 不認 SVG。內容不細看：送出去的那條路（srcfile）本來就掛 CSP sandbox，
        // 跟 layerfile.php 對匯入的 SVG 是同一套處理。
        $ext = 'svg';
    }
    if ($ext === null) {
        @unlink($part);
        json_out(['error' => $tr('tilecut_src_bad_type_msg')], 415);
    }
    foreach (array_keys(souliong_layer_mimes()) as $e2) {
        @unlink($sdir . '/p' . $idx . '.' . $e2);   // 同一格換過格式時，舊副檔名的那個要跟著走
    }
    if (!@rename($part, $sdir . '/p' . $idx . '.' . $ext)) {
        json_out(['error' => $tr('tilecut_mkdir_failed_msg')], 500);
    }
    json_out(['ok' => true, 'file' => 'p' . $idx . '.' . $ext]);
}

// ── 收尾：寫 layer.json（POST，JSON 回應） ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'finish') {
    $project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
    if (!hash_equals($csrfFor($project), (string)($_POST['csrf'] ?? ''))) {
        json_out(['error' => $tr('csrf_invalid_ajax_msg')], 403);
    }
    if (!$canProj($project)) {
        json_out(['error' => $tr('no_permission_title')], 403);
    }
    $id  = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '', $_POST['id'] ?? ''));
    $dir = tilecut_dir($cfg, $project, $id);
    if ($dir === null || !is_dir($dir)) {
        json_out(['error' => $tr('layer_not_found_msg')], 404);
    }
    $num = fn($k, $d = 0.0) => is_numeric($_POST[$k] ?? null) ? (float)$_POST[$k] : $d;
    $s = $num('south');
    $w = $num('west');
    $n = $num('north');
    $e = $num('east');
    // 邊界的合理範圍由 souliong_layer_bounds_valid() 定義（Web Mercator 在南北極附近發散），
    // 後台的就地編輯用同一支，兩邊對「畫得出來」的定義才會一致。
    if (!souliong_layer_bounds_valid($s, $w, $n, $e)) {
        json_out(['error' => $tr('tilecut_bad_bounds_msg')], 400);
    }
    $pane = in_array($_POST['pane'] ?? '', souliong_layer_panes(), true) ? $_POST['pane'] : 'art';
    $label = trim((string)($_POST['label'] ?? ''));
    $opacity = max(0.0, min(1.0, $num('opacity', 1.0)));
    $attr = trim((string)($_POST['attribution'] ?? ''));
    $sdir = souliong_layersrc_dir($cfg, $project, $id);
    $edit = json_decode((string)($_POST['edit'] ?? ''), true);
    $editList = is_array($edit) && is_array($edit['pieces'] ?? null) ? $edit['pieces'] : [];
    $isVector = !empty($_POST['vector']);

    if ($isVector) {
        // 保持向量：只吃「剛好一張、而且是 SVG」的原稿——多張要合成、非 SVG 要點陣化，
        // 兩種都超出「直接把來源當圖層本體」這條路能做的事，UI 本來就只在單張 SVG 時才會送這個旗標，
        // 這裡再驗一次是因為端點不信任前端剛剛送了什麼。
        if ($sdir === null || !is_dir($sdir) || count($editList) !== 1) {
            json_out(['error' => $tr('tilecut_vector_bad_source_msg')], 400);
        }
        $pc = $editList[0];
        $file = is_array($pc) ? (string)($pc['file'] ?? '') : '';
        if (!preg_match('/^p\d{1,2}\.svg$/', $file) || !is_file($sdir . '/' . $file)) {
            json_out(['error' => $tr('tilecut_vector_bad_source_msg')], 400);
        }
        // 複製而非搬移：原稿留在 layersrc，「重新編輯」才吃得到它。
        if (!@copy($sdir . '/' . $file, $dir . '/vector.svg')) {
            json_out(['error' => $tr('tilecut_mkdir_failed_msg')], 500);
        }
        $pieceOpacity = max(0.0, min(1.0, is_numeric($pc['opacity'] ?? null) ? (float)$pc['opacity'] : 1.0));
        $manifest = [
            'label' => mb_substr($label !== '' ? $label : $id, 0, 60),
            'desc'  => $tr('tilecut_vector_manifest_desc', ['at' => gmdate('Y-m-d H:i') . ' UTC']),
            'type'  => 'image',
            'pane'  => $pane,
            'url'   => 'vector.svg',
            'bounds' => [[$s, $w], [$n, $e]],
            'opacity' => max(0.0, min(1.0, $opacity * $pieceOpacity)),
        ];
        if ($attr !== '') {
            $manifest['attribution'] = mb_substr($attr, 0, 500);
        }
        $manifest['generated'] = ['tool' => 'tilecut', 'at' => gmdate('c'), 'mode' => 'vector'];
    } else {
        $z0 = max(0, min(24, (int)$num('minZoom')));
        $z1 = max(0, min(24, (int)$num('maxZoom')));
        if ($z0 > $z1) {
            json_out(['error' => $tr('tilecut_bad_bounds_msg')], 400);
        }
        $ext = in_array($_POST['ext'] ?? '', ['png', 'webp'], true) ? $_POST['ext'] : 'png';
        $count = max(0, (int)$num('count'));
        $manifest = [
            'label' => mb_substr($label !== '' ? $label : $id, 0, 60),
            'desc'  => $tr('tilecut_manifest_desc', ['at' => gmdate('Y-m-d H:i') . ' UTC', 'tiles' => $count]),
            'type'  => 'raster',
            'pane'  => $pane,
            'url'   => 'tiles/{z}/{x}/{y}.' . $ext,
            'bounds' => [[$s, $w], [$n, $e]],
            'minZoom' => $z0,
            // 切到哪一級就 maxNativeZoom 到哪一級，maxZoom 再放寬：再放大時 Leaflet 會把最後一級
            // 拉伸上去，總比整層消失好（手繪稿放大本來就是糊的，使用者預期得到）。
            'maxNativeZoom' => $z1,
            'maxZoom' => min(24, $z1 + 4),
            'opacity' => $opacity,
        ];
        if ($attr !== '') {
            $manifest['attribution'] = mb_substr($attr, 0, 500);
        }
        // 這一段純粹是留給人看的來歷：哪支工具、什麼時候、幾張磚。程式不讀它。
        $manifest['generated'] = ['tool' => 'tilecut', 'at' => gmdate('c'), 'tiles' => $count, 'pieces' => max(1, (int)$num('pieces', 1))];
    }

    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($dir . '/layer.json', $json, LOCK_EX) === false) {
        json_out(['error' => $tr('tilecut_mkdir_failed_msg')], 500);
    }
    // ── 保留原稿：把整疊的位置與設定寫成 edit.json ──
    // 圖磚（或向量輸出）是壓平後的結果，壓平是不可逆的：少了這一份，下次想把某一張往東挪三公尺就只能整套重來。
    // 只有原稿真的落地了才寫——沒有圖片的 edit.json 只會讓後台長出一顆按了沒東西的「重新編輯」。
    if ($sdir !== null && is_dir($sdir) && $editList) {
        $names = [];
        foreach (scandir($sdir) ?: [] as $e2) {
            if (preg_match('/^\.p\d{1,2}$/', $e2)) {
                @unlink($sdir . '/' . $e2);   // 中斷留下的半截檔，收尾時順手清掉
            } elseif (preg_match('/^p\d{1,2}\.[a-z]+$/', $e2)) {
                $names[$e2] = true;
            }
        }
        // 逐欄重建，不直接把對方送來的 JSON 寫進去：這份檔案下次會被讀回來當成工具的狀態，
        // 原封不動存起來等於把「使用者送什麼就吃什麼」延後到下一次開啟才發作。
        $ps = [];
        foreach ($editList as $pc) {
            $f = is_array($pc) ? (string)($pc['file'] ?? '') : '';
            if (!isset($names[$f])) {
                continue;   // 檔案沒落地就不記，免得載回來是一排破圖
            }
            $bb = is_array($pc['bounds'] ?? null) ? $pc['bounds'] : [];
            $g  = fn(string $k): float => is_numeric($bb[$k] ?? null) ? (float)$bb[$k] : 0.0;
            if (!souliong_layer_bounds_valid($g('s'), $g('w'), $g('n'), $g('e'))) {
                continue;
            }
            $ps[] = [
                'file'    => $f,
                'name'    => mb_substr(preg_replace('/[[:cntrl:]]/', '', (string)($pc['name'] ?? $f)), 0, 120),
                'w'       => max(1, min(200000, (int)($pc['w'] ?? 1))),
                'h'       => max(1, min(200000, (int)($pc['h'] ?? 1))),
                'bounds'  => ['n' => $g('n'), 's' => $g('s'), 'w' => $g('w'), 'e' => $g('e')],
                'opacity' => max(0.0, min(1.0, is_numeric($pc['opacity'] ?? null) ? (float)$pc['opacity'] : 1.0)),
                'on'      => !empty($pc['on']),
            ];
        }
        if ($ps) {
            $layerEdit = [
                'label'       => $manifest['label'],
                'pane'        => $pane,
                'opacity'     => $opacity,
                'attribution' => $manifest['attribution'] ?? '',
            ];
            if (!$isVector) {
                $layerEdit['ext']     = $ext;
                $layerEdit['minZoom'] = $z0;
                $layerEdit['maxZoom'] = $z1;
            }
            $doc = [
                'v'      => 1,
                'tool'   => 'tilecut',
                'at'     => gmdate('c'),
                'layer'  => $layerEdit,
                'pieces' => $ps,
            ];
            @file_put_contents(
                $sdir . '/edit.json',
                json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                LOCK_EX
            );
        }
    }
    audit_log($cfg, $auditWho($project), 'layer_tilecut', $project, $id . ($isVector ? ' vector' : ' x' . $count));
    json_out(['ok' => true, 'id' => $id]);
}

// ── 頁面 ──
$allProjects = array_values(array_filter(store_projects($cfg), fn($p) => $primary || admin_can($cfg, $p)));
if (!$primary && !$allProjects) {
    http_response_code(401);
    header('Content-Type: text/html; charset=utf-8');
    echo '<p>' . $tr('tilecut_login_required_msg', ['url' => $adminUrl]) . '</p>';
    exit;
}

// ── 說明書（GET ?help=1）：冗長的操作說明搬進 docs/TILECUT.md，工具本身的畫面維持乾淨 ──
if (($_GET['help'] ?? '') !== '') {
    require_once __DIR__ . '/markdown.php';
    $helpMd = file_get_contents(__DIR__ . '/../docs/TILECUT.md') ?: '';
    $helpMarker = '<!-- site:content -->';
    $helpPos = strpos($helpMd, $helpMarker);
    $helpBody = Markdown::toHtml($helpPos !== false ? substr($helpMd, $helpPos + strlen($helpMarker)) : $helpMd, ['heading_ids' => false]);
    $toolUrl = $esc(Route::tool('tilecut', $backProject));
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="zh-Hant">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex">
  <title><?= $t('tilecut_help_title') ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <style>
    :root {
      color-scheme: light dark;
      --bg: #fafafa;
      --fg: #1b1b1d;
      --muted: #6b6b70;
      --line: #e7e7ea;
      --card: #fff;
      --accent: #1b1b1d;
      --r: 1.25rem
    }

    @media(prefers-color-scheme:dark) {
      :root {
        --bg: #141416;
        --fg: #f1f1f3;
        --muted: #9c9ca3;
        --line: #2e2e31;
        --card: #1d1d20;
        --accent: #f1f1f3
      }
    }

    * {
      box-sizing: border-box
    }

    body {
      margin: 0;
      font-family: system-ui, sans-serif;
      background: var(--bg);
      color: var(--fg);
      line-height: 1.75;
      -webkit-font-smoothing: antialiased
    }

    .wrap {
      max-width: 42rem;
      margin: 0 auto;
      padding: 2.5rem 1.25rem 4rem
    }

    a {
      color: inherit
    }

    h1 {
      font-size: 1.4rem;
      font-weight: 800;
      margin: 0 0 1.5rem
    }

    h2 {
      font-size: 1.05rem;
      font-weight: 800;
      margin: 2rem 0 .5rem
    }

    p {
      margin: .75rem 0
    }

    ul,
    ol {
      margin: .4rem 0;
      padding-left: 1.2rem
    }

    li {
      margin: .3rem 0
    }

    strong {
      font-weight: 700
    }

    code {
      font-family: ui-monospace, Consolas, monospace;
      font-size: .85em;
      background: var(--line);
      padding: .1em .35em;
      border-radius: .3em
    }

    .back {
      display: inline-block;
      margin-bottom: 1.5rem;
      font-size: .9rem;
      color: var(--muted);
      text-decoration: none
    }
  </style>
</head>

<body>
  <div class="wrap">
    <a class="back" href="<?= $toolUrl ?>"><i class="fa-solid fa-arrow-left"></i> <?= $t('tilecut_help_back_btn') ?></a>
    <h1><i class="fa-solid fa-scissors"></i> <?= $t('tilecut_h1') ?></h1>
    <?= $helpBody ?>
  </div>
</body>

</html>
<?php
    exit;
}

$reqProject = in_array($backProject, $allProjects, true) ? $backProject : ($allProjects[0] ?? '');
$csrf = $csrfFor($reqProject);

// 分塊多大：取 upload_max_filesize 與 post_max_size 的較小者再留兩成給表單其他欄位。
// 寫死一個「安全值」的話，設定寬鬆的主機會白白多送幾十趟。
$iniBytes = function (string $k): int {
    $v = trim((string)ini_get($k));
    $mul = ['k' => 1024, 'm' => 1048576, 'g' => 1073741824][strtolower(substr($v, -1))] ?? 1;
    $n = (int)((float)$v * $mul);
    return $n > 0 ? $n : PHP_INT_MAX;   // 0 或空值在 PHP 裡是「不限制」
};
$srcChunk = max(256 * 1024, min(4 * 1024 * 1024, (int)(min($iniBytes('upload_max_filesize'), $iniBytes('post_max_size')) * 0.8)));

// 「重新編輯」：帶 load=<id> 進來時把上次存的 edit.json 讀出來，前端據此把整疊重建回去。
// 圖片本身不塞進頁面（可能上百 MB），前端再逐張用 action=srcfile 抓。
$EDIT = null;
$loadId = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['load'] ?? ''));
if ($loadId !== '' && $reqProject !== '') {
    $sdir = souliong_layersrc_dir($cfg, $reqProject, $loadId);
    $doc = $sdir !== null && is_file($sdir . '/edit.json')
        ? json_decode((string)@file_get_contents($sdir . '/edit.json'), true) : null;
    if (is_array($doc) && !empty($doc['pieces']) && is_array($doc['pieces'])) {
        $EDIT = [
            'id'     => $loadId,
            'layer'  => (array)($doc['layer'] ?? []),
            'pieces' => array_values($doc['pieces']),
        ];
    }
}

// 沒留原稿：退而求其次，看看圖磚本身還在不在——在的話可以拼回一張圖當新來源重切，
// 但那是壓平後的結果，分層資訊回不去了。只有一般切磚模式（type:raster）有圖磚金字塔可拼，
// 保持向量模式（type:image）本體就是那張 SVG，沒有磚可拼、也不需要。
$RECON = null;
if ($EDIT === null && $loadId !== '' && $reqProject !== '') {
    $tdir = tilecut_dir($cfg, $reqProject, $loadId);
    $ldoc = $tdir !== null && is_file($tdir . '/layer.json')
        ? json_decode((string)@file_get_contents($tdir . '/layer.json'), true) : null;
    if (
        is_array($ldoc) && ($ldoc['type'] ?? '') === 'raster'
        && is_array($ldoc['bounds'] ?? null) && count($ldoc['bounds']) === 2
        && isset($ldoc['maxNativeZoom']) && preg_match('/\.([a-z0-9]+)$/i', (string)($ldoc['url'] ?? ''), $extM)
    ) {
        $RECON = [
            'id'          => $loadId,
            'ext'         => strtolower($extM[1]),
            'z'           => (int)$ldoc['maxNativeZoom'],
            'bounds'      => $ldoc['bounds'],
            'label'       => (string)($ldoc['label'] ?? ''),
            'pane'        => (string)($ldoc['pane'] ?? ''),
            'opacity'     => isset($ldoc['opacity']) ? (float)$ldoc['opacity'] : 1.0,
            'attribution' => (string)($ldoc['attribution'] ?? ''),
        ];
    }
}
?>
<!doctype html>
<html lang="<?= $LANG === 'en' ? 'en' : 'zh-Hant' ?>">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex">
  <title><?= $t('tilecut_title') ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <style>
    :root {
      --bg: #f6f5f2;
      --fg: #1c1a17;
      --muted: #7a756c;
      --line: #e2ddd3;
      --card: #fff;
      --accent: #b5482e;
      --accent-fg: #fff;
      --sp-1: 0.25rem;
      --sp-2: 0.5rem;
      --sp-3: 0.75rem;
      --sp-4: 1rem;
      --sp-5: 1.5rem;
      --tap: 1.75rem
    }

    @media (prefers-color-scheme:dark) {
      :root {
        --bg: #17140f;
        --fg: #f1ede6;
        --muted: #a69d8e;
        --line: #322c22;
        --card: #211c15;
        --accent: #e0663f;
        --accent-fg: #1c1a17
      }
    }

    * {
      box-sizing: border-box
    }

    body {
      margin: 0;
      background: var(--bg);
      color: var(--fg);
      font: 0.9375rem/1.6 system-ui, -apple-system, "Noto Sans TC", sans-serif;
      padding: var(--sp-4) var(--sp-4) 3.75rem
    }

    .wrap {
      max-width: 52rem;
      margin: 0 auto
    }

    .langsw {
      max-width: 52rem;
      margin: 0 auto var(--sp-2);
      display: flex;
      justify-content: flex-end;
      gap: var(--sp-1);
      font-size: 0.75rem
    }

    .langsw a {
      display: inline-flex;
      align-items: center;
      min-height: var(--tap);
      color: var(--muted);
      text-decoration: none;
      padding: 0 var(--sp-3);
      border-radius: 999px;
      border: 1px solid transparent
    }

    .langsw a.on {
      color: var(--fg);
      font-weight: 700;
      background: var(--card);
      border-color: var(--line)
    }

    h1 {
      font-size: 1.125rem;
      line-height: 1.4;
      margin: 0 0 var(--sp-4);
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 0 var(--sp-2)
    }

    .helplink {
      font-size: 0.8125rem;
      font-weight: 400;
      color: var(--muted);
      text-decoration: none
    }

    h2 {
      font-size: 0.875rem;
      margin: 0 0 var(--sp-2);
      display: flex;
      align-items: center;
      gap: var(--sp-2)
    }

    .warn {
      border: 1px solid var(--accent);
      color: var(--accent);
      border-radius: var(--sp-3);
      padding: var(--sp-3) var(--sp-4);
      font-size: 0.8125rem;
      margin-bottom: var(--sp-4)
    }

    .card {
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: 0.875rem;
      padding: var(--sp-4) var(--sp-5);
      margin-bottom: var(--sp-4)
    }

    label {
      display: block;
      font-size: 0.8125rem;
      color: var(--muted);
      margin-bottom: var(--sp-1)
    }

    select,
    input[type=text],
    input[type=number] {
      width: 100%;
      border: 1px solid var(--line);
      border-radius: 0.625rem;
      background: var(--bg);
      color: var(--fg);
      padding: var(--sp-2) var(--sp-3);
      font-size: 0.875rem;
      min-height: 2.25rem
    }

    input[type=range] {
      width: 100%;
      accent-color: var(--accent);
      min-height: 2.25rem
    }

    label span {
      font-weight: 700;
      color: var(--fg)
    }

    /* 兩欄以上的欄位排成流動格線，窄螢幕自動疊成一欄 */
    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr));
      gap: var(--sp-3);
      margin-bottom: var(--sp-3)
    }

    .grid>div {
      min-width: 0
    }

    button {
      border: none;
      background: var(--accent);
      color: var(--accent-fg);
      border-radius: 0.625rem;
      padding: var(--sp-2) var(--sp-4);
      min-height: 2.25rem;
      font-size: 0.875rem;
      font-weight: 700;
      cursor: pointer
    }

    button.ghost {
      background: var(--card);
      color: var(--fg);
      border: 1px solid var(--line);
      font-weight: 600
    }

    button:disabled {
      opacity: .5;
      cursor: default
    }

    .row {
      display: flex;
      flex-wrap: wrap;
      gap: var(--sp-2);
      align-items: center
    }

    .hint {
      font-size: 0.75rem;
      color: var(--muted)
    }

    .hint:empty {
      display: none
    }

    .filebtn {
      display: inline-flex;
      align-items: center;
      gap: var(--sp-2);
      cursor: pointer;
      margin: 0
    }

    .chk {
      display: flex;
      align-items: center;
      gap: var(--sp-2);
      font-size: 0.8125rem;
      color: var(--fg);
      margin: 0
    }

    .chk input {
      width: auto;
      min-height: 0
    }

    #map {
      height: 22rem;
      border-radius: 0.75rem;
      border: 1px solid var(--line);
      margin-bottom: var(--sp-3);
      background: var(--bg)
    }

    /* 進度條：外框固定、內條寬度由 JS 給，避免每格磚都動到版面 */
    .bar {
      height: 0.5rem;
      border-radius: 999px;
      background: var(--line);
      overflow: hidden;
      margin: var(--sp-3) 0 var(--sp-2)
    }

    .bar i {
      display: block;
      height: 100%;
      width: 0;
      background: var(--accent);
      transition: width .2s
    }

    .mono {
      font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
      font-size: 0.75rem
    }

    .ok {
      color: #2a8a4a
    }

    .no {
      color: var(--accent)
    }

    a {
      color: var(--accent)
    }

    .backlink {
      margin-top: var(--sp-5)
    }

    .backlink a {
      display: inline-flex;
      align-items: center;
      gap: var(--sp-2);
      min-height: 2.25rem;
      padding: 0 var(--sp-4);
      border: 1px solid var(--line);
      border-radius: 999px;
      background: var(--card);
      color: var(--fg);
      font-size: 0.8125rem;
      font-weight: 600;
      text-decoration: none
    }

    .backlink a:hover {
      border-color: var(--accent);
      color: var(--accent)
    }

    /* ── 圖片清單：一列一張來源圖，由上而下＝由頂層到底層（比照後台的圖層挑選器） ── */
    .pclist {
      border: 1px solid var(--line);
      border-radius: 0.75rem;
      overflow: hidden
    }

    .pcrow {
      display: flex;
      align-items: center;
      gap: var(--sp-2);
      padding: var(--sp-2) var(--sp-3);
      border-top: 1px solid var(--line)
    }

    .pcrow:first-child {
      border-top: none
    }

    .pcrow.on {
      box-shadow: inset 3px 0 0 var(--accent)
    }

    .pcrow.on .pcname {
      color: var(--accent)
    }

    .pcord {
      display: flex;
      flex-direction: column;
      flex: none
    }

    .pcbtn {
      background: none;
      color: var(--muted);
      border: none;
      padding: 0 var(--sp-2);
      min-height: 0.875rem;
      font-size: 0.6875rem;
      line-height: 1
    }

    .pcbtn:hover:not(:disabled) {
      color: var(--accent)
    }

    .pcbtn.pcdel {
      min-height: 1.75rem;
      font-size: 0.8125rem
    }

    .pcpick {
      flex: 1 1 auto;
      min-width: 0;
      background: none;
      color: var(--fg);
      text-align: left;
      font-weight: 400;
      padding: var(--sp-1) 0;
      display: flex;
      align-items: baseline;
      gap: var(--sp-2);
      flex-wrap: wrap
    }

    .pcname {
      font-weight: 600;
      overflow-wrap: anywhere
    }

    .pcsize {
      color: var(--muted)
    }

    .pcvis {
      margin: 0;
      flex: none;
      display: flex;
      align-items: center
    }

    .pcvis input {
      width: auto;
      min-height: 0;
      margin: 0
    }

    .pcempty {
      padding: var(--sp-3);
      font-size: 0.75rem;
      color: var(--muted)
    }

    /* 沒選取任何一張時整組關掉：能點卻不知道在改哪一張，比關掉更糟 */
    .pcdisabled {
      opacity: .45;
      pointer-events: none
    }

    /* 地圖上的把手：角落方塊縮放、中央圓鈕整張移動 */
    .pchandle {
      width: 0.875rem;
      height: 0.875rem;
      background: var(--card);
      border: 2px solid var(--accent);
      border-radius: 3px;
      cursor: nwse-resize;
      box-shadow: 0 1px 3px rgba(0, 0, 0, .35)
    }

    .pchandle.pcmove {
      width: 1.625rem;
      height: 1.625rem;
      border-radius: 999px;
      cursor: move;
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--accent);
      font-size: 0.75rem
    }

    a:focus-visible,
    button:focus-visible,
    select:focus-visible,
    input:focus-visible {
      outline: 2px solid var(--accent);
      outline-offset: 2px
    }
  </style>
</head>

<body>
  <div class="langsw">
    <a href="<?= $esc(Route::tool('tilecut', $backProject, ['lang' => 'zh_TW'])) ?>" class="<?= $LANG === 'zh_TW' ? 'on' : '' ?>">中文</a>
    <a href="<?= $esc(Route::tool('tilecut', $backProject, ['lang' => 'en'])) ?>" class="<?= $LANG === 'en' ? 'on' : '' ?>">English</a>
  </div>
  <div class="wrap">
    <h1><i class="fa-solid fa-scissors"></i> <?= $t('tilecut_h1') ?> <a class="helplink" href="<?= $esc(Route::tool('tilecut', $backProject, ['help' => 1])) ?>" title="<?= $t('tilecut_help_btn') ?>"><i class="fa-solid fa-circle-question"></i></a></h1>
    <div class="warn"><?= $t('tilecut_warn') ?></div>

    <div class="card">
      <h2><i class="fa-solid fa-1"></i> <?= $t('tilecut_step_source') ?></h2>
      <div class="grid">
        <div>
          <label for="project"><?= $t('tool_select_project_label') ?></label>
          <select id="project">
            <?php foreach ($allProjects as $p): ?><option value="<?= $esc($p) ?>" <?= $p === $reqProject ? 'selected' : '' ?>><?= $esc($p) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="lid"><?= $t('tilecut_layer_id_label') ?></label>
          <input type="text" id="lid" value="<?= $esc($EDIT !== null ? $EDIT['id'] : ($RECON !== null ? $RECON['id'] : 'artwork')) ?>" pattern="[a-z0-9][a-z0-9_\-]*" maxlength="32" spellcheck="false">
        </div>
        <div>
          <label for="llabel"><?= $t('tilecut_label_label') ?></label>
          <input type="text" id="llabel" maxlength="60">
        </div>
      </div>
      <div class="hint" style="margin-bottom:var(--sp-3)"><?= $t('tilecut_layer_id_hint') ?></div>
      <?php if ($EDIT !== null): ?>
      <div class="hint ok" style="margin-bottom:var(--sp-3)"><i class="fa-solid fa-rotate-left"></i> <?= $t('tilecut_loaded_msg', ['id' => $EDIT['id']]) ?></div>
      <?php elseif ($RECON !== null): ?>
      <div class="hint" style="margin-bottom:var(--sp-3)">
        <i class="fa-solid fa-triangle-exclamation"></i> <?= $t('tilecut_recon_hint', ['id' => $RECON['id']]) ?>
        <button type="button" class="ghost" id="reconbtn" style="margin-left:.5rem"><i class="fa-solid fa-shapes"></i> <?= $t('tilecut_recon_btn') ?></button>
      </div>
      <?php endif; ?>
      <div class="row">
        <label class="filebtn"><span class="ghost" style="display:inline-flex;align-items:center;gap:.5rem;border:1px solid var(--line);border-radius:.625rem;padding:.5rem 1rem;background:var(--card)"><i class="fa-solid fa-images"></i> <?= $t('tilecut_choose_image_btn') ?></span>
          <input type="file" id="src" accept="image/png,image/webp,image/jpeg,image/svg+xml" multiple hidden></label>
        <span class="hint" id="srcinfo"></span>
      </div>
      <div class="hint" style="margin-top:var(--sp-2)"><?= $t('tilecut_image_hint') ?></div>

      <h2 style="margin-top:var(--sp-5)"><i class="fa-solid fa-layer-group"></i> <?= $t('tilecut_pieces_heading') ?></h2>
      <div class="hint" style="margin-bottom:var(--sp-3)"><?= $t('tilecut_pieces_hint') ?></div>
      <div class="pclist" id="pclist"></div>
    </div>

    <div class="card">
      <h2><i class="fa-solid fa-2"></i> <?= $t('tilecut_step_place') ?></h2>
      <div class="hint" style="margin-bottom:var(--sp-3)"><?= $t('tilecut_place_hint') ?></div>
      <div id="map"></div>
      <div class="row" style="margin-bottom:var(--sp-3)">
        <button type="button" class="ghost" id="fitov"><i class="fa-solid fa-magnifying-glass-location"></i> <?= $t('tilecut_fit_btn') ?></button>
        <label class="chk"><input type="checkbox" id="ghost" checked> <?= $t('tilecut_ghost_label') ?></label>
      </div>
      <div class="hint" id="placemsg" style="margin-bottom:var(--sp-3)"><?= $t('tilecut_select_piece_msg') ?></div>
      <div id="placeui" class="pcdisabled">
        <div class="row" style="margin-bottom:var(--sp-3)">
          <button type="button" class="ghost" id="useview"><i class="fa-solid fa-crop-simple"></i> <?= $t('tilecut_use_view_btn') ?></button>
          <label class="chk"><input type="checkbox" id="lockar" checked> <?= $t('tilecut_lock_aspect_label') ?></label>
        </div>
        <div class="grid">
          <div><label for="north"><?= $t('tilecut_north') ?></label><input type="number" id="north" step="0.000001"></div>
          <div><label for="south"><?= $t('tilecut_south') ?></label><input type="number" id="south" step="0.000001"></div>
          <div><label for="west"><?= $t('tilecut_west') ?></label><input type="number" id="west" step="0.000001"></div>
          <div><label for="east"><?= $t('tilecut_east') ?></label><input type="number" id="east" step="0.000001"></div>
          <div><label for="popacity"><?= $t('tilecut_piece_opacity') ?> <span id="popacityval">100%</span></label><input type="range" id="popacity" min="0" max="1" step="0.01" value="1"></div>
        </div>
      </div>
    </div>

    <div class="card">
      <h2><i class="fa-solid fa-3"></i> <?= $t('tilecut_step_output') ?></h2>
      <div class="row" id="vecrow" style="display:none;margin-bottom:var(--sp-3)">
        <label class="chk"><input type="checkbox" id="vecmode"> <?= $t('tilecut_vector_label') ?></label>
      </div>
      <div class="hint" id="vechint" style="display:none;margin-bottom:var(--sp-3)"><?= $t('tilecut_vector_hint') ?></div>
      <div class="row" id="zoomtoolsrow" style="margin-bottom:var(--sp-3)">
        <button type="button" class="ghost" id="usenativez"><i class="fa-solid fa-wand-magic-sparkles"></i> <?= $t('tilecut_zoom_use_native_btn') ?></button>
      </div>
      <div class="grid" id="zoomgrid">
        <div><label for="zmin"><?= $t('tilecut_zoom_min') ?></label><input type="number" id="zmin" min="0" max="22" step="1" value="12"></div>
        <div><label for="zmax"><?= $t('tilecut_zoom_max') ?></label><input type="number" id="zmax" min="0" max="22" step="1" value="17"></div>
      </div>
      <div class="grid">
        <div>
          <label for="pane"><?= $t('tilecut_pane_label') ?></label>
          <select id="pane">
            <option value="art" selected><?= $t('tilecut_pane_art') ?></option>
            <option value="road"><?= $t('tilecut_pane_road') ?></option>
            <option value="paper"><?= $t('tilecut_pane_paper') ?></option>
            <option value="base"><?= $t('tilecut_pane_base') ?></option>
          </select>
        </div>
        <div><label for="opacity"><?= $t('tilecut_opacity_label') ?> <span id="opacityval">100%</span></label><input type="range" id="opacity" min="0" max="1" step="0.01" value="1"></div>
      </div>
      <div>
        <label for="attr"><?= $t('tilecut_attr_label') ?></label>
        <input type="text" id="attr" maxlength="500">
      </div>
      <div class="hint" id="zoomhint" style="margin-top:var(--sp-2)"><?= $t('tilecut_zoom_hint') ?></div>
      <div class="hint" id="estimate" style="margin-top:var(--sp-3)"></div>
      <div class="row" style="margin-top:var(--sp-3)">
        <label class="chk"><input type="checkbox" id="overwrite" <?= ($EDIT !== null || $RECON !== null) ? 'checked' : '' ?>> <?= $t('tilecut_overwrite_label') ?></label>
      </div>
      <div class="row" id="keepsrcrow" style="margin-top:var(--sp-2)">
        <label class="chk"><input type="checkbox" id="keepsrc" checked> <?= $t('tilecut_keepsrc_label') ?></label>
      </div>
      <div class="hint" id="keepsrchint" style="margin-top:var(--sp-2)"><?= $t('tilecut_keepsrc_hint') ?></div>
      <div class="row" style="margin-top:var(--sp-3)">
        <button id="go"><i class="fa-solid fa-scissors"></i> <?= $t('tilecut_start_btn') ?></button>
        <button id="stop" class="ghost" disabled><i class="fa-solid fa-stop"></i> <?= $t('tilecut_stop_btn') ?></button>
      </div>
      <div class="bar"><i id="barfill"></i></div>
      <div class="hint" id="status"></div>
      <div class="hint" id="done" style="margin-top:var(--sp-2)"></div>
      <p class="hint" style="margin-top:var(--sp-3)"><?= $t('tilecut_keep_open_hint') ?></p>
    </div>

    <p class="backlink"><a href="<?= $adminUrl ?>"><i class="fa-solid fa-arrow-left"></i> <?= $t("back_to_admin") ?></a></p>
  </div>

  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script>
    // 未套變數的原始字串（含 {var} 佔位符），前端用 fmt() 自行代換——進度與計數只有 JS 迴圈裡才知道
    const I18N = <?= json_encode([
      'need_image'   => i18n_t($DICT, 'tilecut_need_image_msg'),
      'need_id'      => i18n_t($DICT, 'tilecut_need_id_msg'),
      'bad_bounds'   => i18n_t($DICT, 'tilecut_bad_bounds_msg'),
      'too_many'     => i18n_t($DICT, 'tilecut_too_many_msg'),
      'estimate'     => i18n_t($DICT, 'tilecut_estimate_msg'),
      'native_hint'  => i18n_t($DICT, 'tilecut_native_hint'),
      'cutting'      => i18n_t($DICT, 'tilecut_cutting_msg'),
      'uploading'    => i18n_t($DICT, 'tilecut_uploading_msg'),
      'rate_limited' => i18n_t($DICT, 'tilecut_rate_limited_msg'),
      'stopped'      => i18n_t($DICT, 'tilecut_stopped_msg'),
      'complete'     => i18n_t($DICT, 'tilecut_complete_msg'),
      'next_step'    => i18n_t($DICT, 'tilecut_next_step_msg'),
      'rejected'     => i18n_t($DICT, 'tilecut_rejected_msg'),
      'error_prefix' => i18n_t($DICT, 'error_prefix_label'),
      'conn_failed'  => i18n_t($DICT, 'connection_failed_retry_msg'),
      'img_failed'   => i18n_t($DICT, 'tilecut_image_load_failed'),
      'no_pieces'    => i18n_t($DICT, 'tilecut_no_pieces_msg'),
      'piece_count'  => i18n_t($DICT, 'tilecut_piece_count_msg'),
      'piece_hide'   => i18n_t($DICT, 'tilecut_piece_hide_title'),
      'no_visible'   => i18n_t($DICT, 'tilecut_no_visible_msg'),
      'move_up'      => i18n_t($DICT, 'layer_move_up_aria'),
      'move_down'    => i18n_t($DICT, 'layer_move_down_aria'),
      'remove'       => i18n_t($DICT, 'remove_title'),
      'src_size'     => i18n_t($DICT, 'tilecut_src_size_msg'),
      'src_upload'   => i18n_t($DICT, 'tilecut_src_uploading_msg'),
      'load_failed'  => i18n_t($DICT, 'tilecut_load_failed_msg'),
      'start_cut'    => i18n_t($DICT, 'tilecut_start_btn'),
      'start_vector' => i18n_t($DICT, 'tilecut_vector_start_btn'),
      'vector_complete'   => i18n_t($DICT, 'tilecut_vector_complete_msg'),
      'vector_bad_source' => i18n_t($DICT, 'tilecut_vector_bad_source_msg'),
      'recon_progress'    => i18n_t($DICT, 'tilecut_recon_progress_msg'),
      'recon_done'        => i18n_t($DICT, 'tilecut_recon_done_msg'),
    ], JSON_UNESCAPED_UNICODE) ?>;
    const fmt = (str, vars) => str.replace(/\{(\w+)\}/g, (_, k) => (vars[k] != null ? vars[k] : ''));
    const csrf = <?= json_encode($csrf) ?>;
    // 跨端點請求一律用絕對 base（同 thumbfix.php：這頁可能從 <base>/tilecut 進來，相對 ?api= 會被路徑路由搶走）
    const BASE = <?= json_encode(Route::abs(Route::base()), JSON_UNESCAPED_SLASHES) ?>;
    // 「重新編輯」帶進來的上一次狀態；null＝這是全新的一層。圖片本身不在裡面，逐張去 srcfile 抓。
    const EDIT = <?= json_encode($EDIT, JSON_UNESCAPED_UNICODE) ?>;
    // 沒留原稿、但圖磚還在時的降級重建資訊；null＝沒有可拼回去的圖磚。
    const RECON = <?= json_encode($RECON, JSON_UNESCAPED_UNICODE) ?>;
    // 原稿分塊多大，由伺服器的 upload_max_filesize／post_max_size 算出來
    const SRCCHUNK = <?= (int)$srcChunk ?>;

    const TILE = 256;         // 標準圖磚邊長；Leaflet 預設也是 256
    const BATCH = 16;         // 一次 POST 幾張。PHP 的 max_file_uploads 常見上限是 20，留些餘裕
    const MAX_TILES = 20000;  // 超過就不讓開始：再多就該考慮降一級 zoom，而不是讓人等半小時

    // ── Web Mercator：經緯度 ↔ 單位世界座標 [0,1] ──
    // 圖磚系統與 Leaflet 的 ImageOverlay 都在這個空間裡線性運作，所以整支工具只需要這四個函式。
    const wx = lng => (lng + 180) / 360;
    const wy = lat => { const s = Math.sin(lat * Math.PI / 180); return 0.5 - Math.log((1 + s) / (1 - s)) / (4 * Math.PI); };
    const lngOf = x => x * 360 - 180;
    const latOf = y => Math.atan(Math.sinh(Math.PI * (1 - 2 * y))) * 180 / Math.PI;

    const $ = id => document.getElementById(id);
    const statusEl = $('status'), doneEl = $('done'), estEl = $('estimate'), barEl = $('barfill');
    const listEl = $('pclist'), infoEl = $('srcinfo');

    let running = false, aborted = false;

    // ── 地圖 ──
    const map = L.map('map', { center: [23.95, 120.69], zoom: 14 });
    L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',
      { subdomains: 'abcd', detectRetina: true, maxZoom: 20, attribution: '&copy; OpenStreetMap, CARTO' }).addTo(map);

    /** 四個邊界值合不合理。與後端的 souliong_layer_bounds_valid() 同一套判準。 */
    function validBounds(b) {
      return !!b && [b.n, b.s, b.w, b.e].every(v => isFinite(v))
        && b.s < b.n && b.w < b.e
        && Math.abs(b.n) <= 85.0511 && Math.abs(b.s) <= 85.0511
        && Math.abs(b.w) <= 180 && Math.abs(b.e) <= 180;
    }

    /**
     * 讀一張圖回來量尺寸。SVG 沒有像素尺寸的保證，createImageBitmap 對它的支援也不一致；
     * 一律走 <img> 取 naturalWidth／naturalHeight，SVG 會用它自己宣告的 width／height。
     */
    function loadImage(url) {
      return new Promise((res, rej) => {
        const i = new Image();
        i.onload = () => {
          // 沒有內建尺寸的 SVG（只給 viewBox、不給 width/height）量不出長寬比，也就無從對位
          if (!(i.naturalWidth || i.width) || !(i.naturalHeight || i.height)) rej(new Error('no intrinsic size'));
          else res(i);
        };
        i.onerror = () => rej(new Error('decode'));
        i.src = url;
      });
    }

    /**
     * 一張來源圖片。清單裡的每一列就是一個 Piece，切磚時由下往上疊成一張。
     * 位置與不透明度刻意都是純值（bounds / opacity / on），寫進 edit.json「保留原稿」時
     * 直接就是這個形狀，載回來也照這個形狀還原，不必兩邊各翻譯一次。
     */
    class Piece {
      constructor(name, img, url, blob) {
        this.name = name;
        this.img = img;
        this.url = url;                          // objectURL；移除時要 revoke，否則整張圖留在記憶體裡
        this.blob = blob || null;                // 原稿的位元組。使用者選的 File 背後是磁碟，留著不佔記憶體
        this.file = '';                          // 伺服器上的檔名（p0.png…）；載回來的一開始就有
        this.w = img.naturalWidth || img.width;
        this.h = img.naturalHeight || img.height;
        this.bounds = null;                      // {n,s,w,e}
        this.opacity = 1;
        this.on = true;
        this.overlay = null;
      }

      /** 四邊在 Web Mercator 單位世界座標裡的位置；對位與切磚都在這個空間算。 */
      proj() {
        return { x0: wx(this.bounds.w), x1: wx(this.bounds.e), y0: wy(this.bounds.n), y1: wy(this.bounds.s) };
      }

      latLngBounds() {
        return L.latLngBounds([[this.bounds.s, this.bounds.w], [this.bounds.n, this.bounds.e]]);
      }

      /** 地圖上的預覽。ghost 是「預覽半透明」那顆開關，只影響畫面，不影響切出來的磚。 */
      draw(ghost) {
        if (!validBounds(this.bounds)) return;
        const ll = this.latLngBounds();
        if (!this.overlay) this.overlay = L.imageOverlay(this.url, ll, { interactive: false });
        else this.overlay.setBounds(ll);
        this.overlay.setOpacity(this.opacity * (ghost ? 0.7 : 1));
        if (this.on) this.overlay.addTo(map);
        else map.removeLayer(this.overlay);
      }

      destroy() {
        if (this.overlay) map.removeLayer(this.overlay);
        URL.revokeObjectURL(this.url);
      }
    }

    // 陣列前端＝最上層，與畫面上的清單同方向（比照後台的圖層挑選器與繪圖軟體）
    let pieces = [];
    let sel = -1;

    // ── 對位把手 ──
    // 三顆：兩個角落縮放、中央一顆整張平移。只服務「目前選取的那一張」——
    // 每張圖都長出一組把手的話，五張圖就有十五顆，誰也拖不準。
    let swM = null, neM = null, mvM = null, selRect = null;

    function ensureHandles() {
      if (swM) return;
      const sq = () => L.divIcon({ className: 'pchandle', iconSize: [14, 14], iconAnchor: [7, 7] });
      swM = L.marker([0, 0], { draggable: true, keyboard: false, icon: sq() });
      neM = L.marker([0, 0], { draggable: true, keyboard: false, icon: sq() });
      mvM = L.marker([0, 0], {
        draggable: true, keyboard: false, icon: L.divIcon({
          className: 'pchandle pcmove', iconSize: [26, 26], iconAnchor: [13, 13],
          html: '<i class="fa-solid fa-arrows-up-down-left-right"></i>'
        })
      });
      selRect = L.rectangle([[0, 0], [0, 0]], { color: '#b5482e', weight: 1, fill: false, dashArray: '4 3' });

      // 拖曳中只跟著畫，放開才套長寬比並把把手校正回去（live=true 就是拖曳中那一段）
      const corner = (m, anchor, live) => () => {
        const p = pieces[sel];
        if (!p) return;
        const a = swM.getLatLng(), b = neM.getLatLng();
        let nb = { s: Math.min(a.lat, b.lat), n: Math.max(a.lat, b.lat), w: Math.min(a.lng, b.lng), e: Math.max(a.lng, b.lng) };
        if (!live) nb = applyAspect(nb, anchor, p);
        commit(p, nb, { skip: live ? m : null });
      };
      // 拖西南角時以西北角為支點（南緣由長寬比算出來），拖東北角時以西南角為支點
      swM.on('drag', corner(swM, 'nw', true)).on('dragend', corner(swM, 'nw', false));
      neM.on('drag', corner(neM, 'sw', true)).on('dragend', corner(neM, 'sw', false));

      // 平移：位移算在投影空間，整張的形狀才不會隨著往南北走而變形
      const move = live => () => {
        const p = pieces[sel];
        if (!p || !validBounds(p.bounds)) return;
        const pr = p.proj();
        const dx = pr.x1 - pr.x0, dy = pr.y1 - pr.y0;
        if (!(dx > 0 && dx <= 1 && dy > 0 && dy <= 1)) return;   // 比整個世界還大的圖沒有「平移」可言
        const ll = mvM.getLatLng();
        const cx = Math.min(1 - dx / 2, Math.max(dx / 2, wx(ll.lng)));
        const cy = Math.min(1 - dy / 2, Math.max(dy / 2, wy(ll.lat)));
        commit(p, {
          w: lngOf(cx - dx / 2), e: lngOf(cx + dx / 2),
          n: latOf(cy - dy / 2), s: latOf(cy + dy / 2)
        }, { skip: live ? mvM : null });
      };
      mvM.on('drag', move(true)).on('dragend', move(false));
    }

    /** 把手歸位。skip 是正在被拖的那一顆——重設它的位置會跟滑鼠搶。 */
    function placeHandles(ll, skip) {
      if (skip !== swM) swM.setLatLng(ll.getSouthWest());
      if (skip !== neM) neM.setLatLng(ll.getNorthEast());
      // 中央把手放在投影中心，不是經緯度中心——後者在南北向會偏，拖起來手感不對
      if (skip !== mvM) mvM.setLatLng([latOf((wy(ll.getNorth()) + wy(ll.getSouth())) / 2), ll.getCenter().lng]);
    }

    function showHandles(ll) {
      ensureHandles();
      selRect.setBounds(ll);
      placeHandles(ll);
      [selRect, swM, neM, mvM].forEach(l => l.addTo(map));
    }

    function hideHandles() {
      if (!swM) return;
      [selRect, swM, neM, mvM].forEach(l => map.removeLayer(l));
    }

    // ── 數字框 ──
    function readFields() {
      const b = {
        n: parseFloat($('north').value), s: parseFloat($('south').value),
        w: parseFloat($('west').value), e: parseFloat($('east').value)
      };
      return validBounds(b) ? b : null;
    }
    function writeFields(b) {
      $('north').value = b.n.toFixed(6); $('south').value = b.s.toFixed(6);
      $('west').value = b.w.toFixed(6); $('east').value = b.e.toFixed(6);
    }

    /**
     * 把新的邊界落到選取的那張圖上：疊圖、外框、把手、數字框一起跟上。
     * 這是拖曳中每一次 mousemove 都會跑的路徑，所以刻意不重建清單 DOM——
     * 清單上顯示的東西（檔名、尺寸、不透明度）本來就跟位置無關。
     * opt.fields=false：來源就是數字框本身，寫回去會把使用者正在打的字蓋掉。
     * opt.skip：正在被拖的那顆把手。
     */
    function commit(p, nb, opt) {
      opt = opt || {};
      if (!validBounds(nb)) return;
      p.bounds = nb;
      if (opt.fields !== false) writeFields(nb);
      p.draw($('ghost').checked);
      const ll = p.latLngBounds();
      ensureHandles();
      selRect.setBounds(ll);
      placeHandles(ll, opt.skip);
      estimate();
    }

    /**
     * 鎖長寬比：以某一角為支點，把另一角拉到「與來源圖同比例」的位置。
     * 比例要在投影空間算而不是經緯度——同一塊經緯度矩形在不同緯度的實際形狀不同，
     * 用經緯度算出來的圖在台灣會被壓扁約 8%。
     */
    function applyAspect(b, anchor, p) {
      if (!p || !p.w || !p.h || !$('lockar').checked) return b;
      const x0 = wx(b.w), x1 = wx(b.e), y0 = wy(b.n), y1 = wy(b.s);
      const need = (x1 - x0) * p.h / p.w;   // 應有的世界縱向跨距
      if (!(need > 0) || !isFinite(need)) return b;
      return (anchor === 'sw')
        ? { w: b.w, s: b.s, e: b.e, n: latOf(y1 - need) }    // 西南固定，調北緣
        : { w: b.w, n: b.n, e: b.e, s: latOf(y0 + need) };   // 西北固定，調南緣
    }

    // ── 整疊的幾何 ──
    /** 會切進圖磚的那幾張的聯集，也就是 layer.json 要寫的 bounds。 */
    function unionBounds() {
      let u = null;
      for (const p of pieces) {
        if (!p.on || p.opacity <= 0 || !validBounds(p.bounds)) continue;
        u = u ? {
          n: Math.max(u.n, p.bounds.n), s: Math.min(u.s, p.bounds.s),
          w: Math.min(u.w, p.bounds.w), e: Math.max(u.e, p.bounds.e)
        } : { n: p.bounds.n, s: p.bounds.s, w: p.bounds.w, e: p.bounds.e };
      }
      return u;
    }

    /** 某個 zoom 下這片範圍蓋到的圖磚（含端點）。 */
    function tileRange(b, z) {
      const n = 1 << z;
      const cl = (v, hi) => Math.max(0, Math.min(hi, v));
      return {
        x0: cl(Math.floor(wx(b.w) * n), n - 1), x1: cl(Math.ceil(wx(b.e) * n) - 1, n - 1),
        y0: cl(Math.floor(wy(b.n) * n), n - 1), y1: cl(Math.ceil(wy(b.s) * n) - 1, n - 1)
      };
    }
    function totalTiles(b, z0, z1) {
      let sum = 0;
      for (let z = z0; z <= z1; z++) { const r = tileRange(b, z); sum += (r.x1 - r.x0 + 1) * (r.y1 - r.y0 + 1); }
      return sum;
    }

    /**
     * 整疊的原生解析度大約對應哪一級 zoom：取「最細的那一張」。
     * 多切上去對其餘幾張只是把同一批像素放大，但為了最細的那張，值得切到它為止。
     */
    function nativeZoom() {
      let best = null;
      for (const p of pieces) {
        if (!p.on || p.opacity <= 0 || !validBounds(p.bounds)) continue;
        const span = wx(p.bounds.e) - wx(p.bounds.w);
        if (!(span > 0)) continue;
        const z = Math.round(Math.log2(p.w / (span * TILE)));
        if (best === null || z > best) best = z;
      }
      return best === null ? null : Math.max(0, Math.min(22, best));
    }

    /** 把 zmin／zmax 直接帶到「原生」那一級（見說明書），供自動帶入與手動按鈕共用。 */
    function applyNativeZoom() {
      const nz = nativeZoom();
      if (nz !== null) { $('zmax').value = nz; $('zmin').value = Math.max(0, nz - 5); }
      estimate();
    }

    // ── 保持向量 ──
    // 只有「剛好一張、而且是 SVG」時才讓這條路可選：多張要合成、非 SVG 要點陣化，
    // 兩種都超出「直接把來源當圖層本體」這條路能做的事。
    function isSvgPiece(p) {
      if (p.file) return /\.svg$/i.test(p.file);
      if (p.blob && p.blob.type) return p.blob.type === 'image/svg+xml';
      return /\.svg$/i.test(p.name || '');
    }
    function vectorEligible() {
      return pieces.length === 1 && isSvgPiece(pieces[0]);
    }
    function vectorActive() {
      return vectorEligible() && $('vecmode').checked;
    }
    /** 依「單張 SVG」的條件與勾選狀態，整組顯示／隱藏切磚才需要的欄位。 */
    function applyVectorUI() {
      const eligible = vectorEligible();
      if (!eligible) $('vecmode').checked = false;
      $('vecrow').style.display = eligible ? '' : 'none';
      const vec = vectorActive();
      $('vechint').style.display = vec ? '' : 'none';
      $('zoomtoolsrow').style.display = vec ? 'none' : '';
      $('zoomgrid').style.display = vec ? 'none' : '';
      $('zoomhint').style.display = vec ? 'none' : '';
      $('keepsrcrow').style.display = vec ? 'none' : '';
      $('keepsrchint').style.display = vec ? 'none' : '';
      $('go').innerHTML = vec
        ? '<i class="fa-solid fa-vector-square"></i> ' + I18N.start_vector
        : '<i class="fa-solid fa-scissors"></i> ' + I18N.start_cut;
    }

    function estimate() {
      if (vectorActive()) {
        estEl.className = 'hint';
        estEl.textContent = '';
        $('go').disabled = running;
        return;
      }
      const u = unionBounds();
      const z0 = parseInt($('zmin').value, 10), z1 = parseInt($('zmax').value, 10);
      if (!u || !isFinite(z0) || !isFinite(z1) || z0 > z1) { estEl.textContent = ''; $('go').disabled = running; return; }
      const total = totalTiles(u, z0, z1);
      const nz = nativeZoom();
      let msg = fmt(I18N.estimate, { tiles: total.toLocaleString() });
      if (nz !== null) msg += ' · ' + fmt(I18N.native_hint, { z: nz });
      if ($('keepsrc').checked) {
        const bytes = pieces.reduce((s, p) => s + (p.blob ? p.blob.size : 0), 0);
        // 幾百 KB 的插畫用 MB 顯示會變成「0.0 MB」，看起來像沒算到
        if (bytes > 0) {
          const size = bytes >= 1048576 ? (bytes / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' KB';
          msg += ' · ' + fmt(I18N.src_size, { size });
        }
      }
      if (total > MAX_TILES) { msg = fmt(I18N.too_many, { tiles: total.toLocaleString(), max: MAX_TILES.toLocaleString() }); estEl.className = 'hint no'; }
      else estEl.className = 'hint';
      estEl.textContent = msg;
      $('go').disabled = running || total > MAX_TILES;
    }

    // ── 圖片清單 ──
    /** 一顆圖示按鈕。檔名可能含 < >，所以名字一律走 textContent，只有固定的圖示用 innerHTML。 */
    function iconBtn(cls, icon, title, fn, off) {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = cls;
      b.title = title;
      b.setAttribute('aria-label', title);
      b.disabled = !!off;
      b.innerHTML = '<i class="fa-solid ' + icon + '"></i>';
      b.addEventListener('click', fn);
      return b;
    }

    function renderList() {
      listEl.textContent = '';
      if (!pieces.length) {
        const d = document.createElement('div');
        d.className = 'pcempty';
        d.textContent = I18N.no_pieces;
        listEl.appendChild(d);
        infoEl.textContent = '';
        return;
      }
      pieces.forEach((p, i) => {
        const row = document.createElement('div');
        row.className = 'pcrow' + (i === sel ? ' on' : '');

        const ord = document.createElement('span');
        ord.className = 'pcord';
        ord.append(
          iconBtn('pcbtn', 'fa-chevron-up', I18N.move_up, () => movePiece(i, -1), i === 0),
          iconBtn('pcbtn', 'fa-chevron-down', I18N.move_down, () => movePiece(i, 1), i === pieces.length - 1)
        );
        row.appendChild(ord);

        const vis = document.createElement('label');
        vis.className = 'pcvis';
        vis.title = I18N.piece_hide;
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.checked = p.on;
        cb.addEventListener('change', () => { p.on = cb.checked; refresh(); });
        vis.appendChild(cb);
        row.appendChild(vis);

        const pick = document.createElement('button');
        pick.type = 'button';
        pick.className = 'pcpick';
        const nm = document.createElement('span');
        nm.className = 'pcname';
        nm.textContent = p.name;
        const sz = document.createElement('span');
        sz.className = 'pcsize mono';
        sz.textContent = p.w + ' x ' + p.h + (p.opacity < 1 ? ' · ' + p.opacity : '');
        pick.append(nm, sz);
        pick.addEventListener('click', () => select(i));
        row.appendChild(pick);

        row.appendChild(iconBtn('pcbtn pcdel', 'fa-trash-can', I18N.remove, () => removePiece(i)));
        listEl.appendChild(row);
      });
      infoEl.textContent = fmt(I18N.piece_count, { n: pieces.length, on: pieces.filter(p => p.on && p.opacity > 0).length });
    }

    /** 疊圖、清單、把手、預估全部重畫一次。不在拖曳路徑上，可以慢。 */
    function refresh() {
      const ghost = $('ghost').checked;
      pieces.forEach((p, i) => {
        p.draw(ghost);
        // 陣列前端是最上層，z-index 就得反過來給：Leaflet 的疊放順序看的是 z-index，
        // 不是 addTo 的先後，所以每次重畫都重新指定一遍才不會被加入順序決定。
        if (p.overlay) p.overlay.setZIndex(pieces.length - i);
      });
      renderList();
      const p = pieces[sel];
      $('placeui').classList.toggle('pcdisabled', !p);
      $('placemsg').style.display = p ? 'none' : '';
      if (p && validBounds(p.bounds)) showHandles(p.latLngBounds());
      else hideHandles();
      applyVectorUI();
      estimate();
    }

    function select(i) {
      sel = (i >= 0 && i < pieces.length) ? i : -1;
      const p = pieces[sel];
      if (p) {
        if (validBounds(p.bounds)) writeFields(p.bounds);
        $('popacity').value = p.opacity;
        $('popacityval').textContent = Math.round(p.opacity * 100) + '%';
      }
      refresh();
    }

    function movePiece(i, d) {
      const j = i + d;
      if (j < 0 || j >= pieces.length) return;
      const tmp = pieces[i]; pieces[i] = pieces[j]; pieces[j] = tmp;
      if (sel === i) sel = j; else if (sel === j) sel = i;
      refresh();
    }

    function removePiece(i) {
      const p = pieces[i];
      if (!p) return;
      p.destroy();
      pieces.splice(i, 1);
      if (sel === i) sel = Math.min(i, pieces.length - 1);
      else if (sel > i) sel--;
      select(sel);
    }

    /**
     * 新加入的圖片先擺哪裡。尺寸完全相同的十之八九是同一張畫布匯出的不同圖層，
     * 直接沿用既有那張的位置，省下重對一次；尺寸不同才退回「鋪滿目前視野」。
     */
    function defaultBounds(p) {
      const twin = pieces.find(q => q.w === p.w && q.h === p.h && validBounds(q.bounds));
      if (twin) return { n: twin.bounds.n, s: twin.bounds.s, w: twin.bounds.w, e: twin.bounds.e };
      const b = map.getBounds();
      const pad = (v0, v1) => [v0 + (v1 - v0) * 0.2, v1 - (v1 - v0) * 0.2];
      const [s, n] = pad(b.getSouth(), b.getNorth());
      const [w, e] = pad(b.getWest(), b.getEast());
      return applyAspect({ n, s, w, e }, 'sw', p);
    }

    // ── 事件 ──
    ['north', 'south', 'west', 'east'].forEach(k => $(k).addEventListener('input', () => {
      const p = pieces[sel], b = readFields();
      if (p && b) commit(p, b, { fields: false });
    }));
    ['zmin', 'zmax'].forEach(k => $(k).addEventListener('input', estimate));
    $('usenativez').addEventListener('click', applyNativeZoom);
    $('keepsrc').addEventListener('change', estimate);
    $('vecmode').addEventListener('change', () => { applyVectorUI(); estimate(); });
    $('popacity').addEventListener('input', () => {
      const p = pieces[sel];
      if (!p) return;
      const v = parseFloat($('popacity').value);
      p.opacity = isFinite(v) ? Math.max(0, Math.min(1, v)) : 1;
      $('popacityval').textContent = Math.round(p.opacity * 100) + '%';
      p.draw($('ghost').checked);
      renderList();
      estimate();
    });
    $('opacity').addEventListener('input', () => {
      $('opacityval').textContent = Math.round(parseFloat($('opacity').value) * 100) + '%';
    });
    $('ghost').addEventListener('change', () => {
      const g = $('ghost').checked;
      pieces.forEach(p => p.draw(g));
    });
    $('useview').addEventListener('click', () => {
      const p = pieces[sel];
      if (!p) return;
      const b = map.getBounds();
      commit(p, applyAspect({ n: b.getNorth(), s: b.getSouth(), w: b.getWest(), e: b.getEast() }, 'sw', p));
    });
    $('fitov').addEventListener('click', () => {
      const u = unionBounds() || (pieces[sel] ? pieces[sel].bounds : null);
      if (validBounds(u)) map.fitBounds([[u.s, u.w], [u.n, u.e]]);
    });
    $('lockar').addEventListener('change', () => {
      const p = pieces[sel];
      if (p && $('lockar').checked && validBounds(p.bounds)) commit(p, applyAspect(p.bounds, 'sw', p));
    });

    // ── 加入圖片 ──
    $('src').addEventListener('change', async ev => {
      const files = Array.from(ev.target.files || []);
      ev.target.value = '';        // 清掉，同一個檔案再選一次也要能觸發 change
      if (!files.length) return;
      const wasEmpty = !pieces.length;
      const failed = [];
      let firstName = '';
      for (const f of files) {
        const url = URL.createObjectURL(f);
        let img;
        try {
          img = await loadImage(url);
        } catch (e) {
          URL.revokeObjectURL(url);
          failed.push(f.name);
          continue;
        }
        const p = new Piece(f.name, img, url, f);
        p.bounds = defaultBounds(p);
        pieces.unshift(p);         // 後加的蓋在前面加的上面，跟繪圖軟體「置入」的行為一致
        if (!firstName) firstName = f.name;
      }
      statusEl.textContent = failed.map(n => fmt(I18N.img_failed, { name: n })).join(' ');
      if (firstName && !$('llabel').value) $('llabel').value = firstName.replace(/\.[^.]+$/, '');
      // zoom 只在「從空清單開始」時自動帶，之後再加圖不覆蓋使用者調過的值
      if (wasEmpty) applyNativeZoom();
      select(0);
    });

    // ── 切磚 ──
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = TILE;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });

    // WebP 帶 alpha 的體積遠小於 PNG；不支援的瀏覽器（Safari 舊版）靜默退回 PNG。
    // 格式在開工前決定一次，整個金字塔統一，layer.json 的副檔名才不會對不上。
    async function pickFormat() {
      const c = document.createElement('canvas'); c.width = c.height = 1;
      const b = await new Promise(r => c.toBlob(r, 'image/webp', 0.9));
      return (b && b.type === 'image/webp') ? { mime: 'image/webp', ext: 'webp', q: 0.85 } : { mime: 'image/png', ext: 'png', q: undefined };
    }

    /**
     * 要壓平的那幾張，由下往上排好，投影座標先算好一次。
     * 每一格都重算 wx()／wy() 會在上萬格的迴圈裡白花掉可觀的時間。
     */
    function cutOrder() {
      const out = [];
      for (let i = pieces.length - 1; i >= 0; i--) {
        const p = pieces[i];
        if (!p.on || p.opacity <= 0 || !validBounds(p.bounds)) continue;
        const pr = p.proj();
        out.push({ p, x0: pr.x0, x1: pr.x1, y0: pr.y0, y1: pr.y1 });
      }
      return out;
    }

    /**
     * 畫一格：由下往上把每一張疊上去，這就是「壓平」——上層蓋住下層，上層透明的地方
     * 透出下層。整格全透明回 null，稀疏金字塔靠這個判斷省掉大半的檔案。
     */
    function cutTile(z, x, y, order, fmtInfo) {
      const n = 1 << z;
      const tx0 = x / n, tx1 = (x + 1) / n, ty0 = y / n, ty1 = (y + 1) / n;
      let drew = false;
      ctx.clearRect(0, 0, TILE, TILE);
      for (const it of order) {
        if (tx1 <= it.x0 || tx0 >= it.x1 || ty1 <= it.y0 || ty0 >= it.y1) continue;   // 這一格碰不到這張
        const px = v => (v - it.x0) / (it.x1 - it.x0) * it.p.w;
        const py = v => (v - it.y0) / (it.y1 - it.y0) * it.p.h;
        ctx.globalAlpha = it.p.opacity;
        // 來源矩形超出影像時瀏覽器會照比例裁切目的地，所以不必自己算交集
        ctx.drawImage(it.p.img, px(tx0), py(ty0), px(tx1) - px(tx0), py(ty1) - py(ty0), 0, 0, TILE, TILE);
        drew = true;
      }
      ctx.globalAlpha = 1;
      if (!drew) return null;
      const buf = ctx.getImageData(0, 0, TILE, TILE).data;
      const u32 = new Uint32Array(buf.buffer);
      let any = false;
      for (let i = 0; i < u32.length; i++) { if (u32[i] !== 0) { any = true; break; } }
      if (!any) return null;
      return new Promise(r => canvas.toBlob(r, fmtInfo.mime, fmtInfo.q));
    }

    /**
     * 送一次 POST。soft 列出「不算失敗、直接把 JSON 交回去」的狀態碼：原稿分塊的 409
     * （offset 對不上）得由呼叫端自己接手續傳，而不是把整個流程炸掉。
     */
    async function post(body, soft) {
      // 磚很多時一定會撞到限流（admin bucket 120/分）：照 Retry-After 等一下再送，不放棄整批
      for (let attempt = 0; attempt < 6; attempt++) {
        const res = await fetch(BASE + '?api=tilecut', { method: 'POST', body });
        if (res.status !== 429) {
          const j = await res.json().catch(() => ({}));
          if (soft && soft.indexOf(res.status) >= 0) { j.status = res.status; return j; }
          if (!res.ok || !j.ok) throw new Error(j.error || ('HTTP ' + res.status));
          return j;
        }
        const wait = parseInt(res.headers.get('Retry-After') || '10', 10) || 10;
        statusEl.textContent = fmt(I18N.rate_limited, { wait });
        await new Promise(r => setTimeout(r, wait * 1000));
      }
      throw new Error('429');
    }

    // ── 原稿 ──
    /**
     * 把整疊原稿送上去，回傳要寫進 edit.json 的那幾筆（沒勾「保留原稿」就回 null）。
     *
     * 排在切磚之前：容量不夠、格式不認得這類問題，最好在使用者等了十分鐘之前就講。
     * 每一塊都自報 offset，伺服器對不上會回 409 並附上它手上的長度，照那個續傳——限流
     * 重試時「送出去了但回應掉了」一定會發生，不處理就會把同一段接兩次。
     */
    async function uploadSources(project, id, force) {
      if (!force && !$('keepsrc').checked) return null;
      for (let i = 0; i < pieces.length; i++) {
        const p = pieces[i];
        p.file = '';          // 這一輪重新落地；上一輪的檔名可能連副檔名都不一樣
        if (!p.blob) continue;
        let off = 0, stuck = 0;
        while (off < p.blob.size) {
          if (aborted) return null;
          const end = Math.min(p.blob.size, off + SRCCHUNK);
          const fd = new FormData();
          fd.append('action', 'srcput'); fd.append('csrf', csrf);
          fd.append('project', project); fd.append('id', id);
          fd.append('idx', i); fd.append('offset', off);
          if (end >= p.blob.size) fd.append('last', '1');
          fd.append('chunk', p.blob.slice(off, end), 'c');
          statusEl.textContent = fmt(I18N.src_upload, {
            i: i + 1, n: pieces.length, pct: Math.round(end / p.blob.size * 100)
          });
          const j = await post(fd, [409]);
          if (j.status === 409) {
            // 對不上就照伺服器手上的長度接下去。連續三次還對不上代表不是掉回應而是別的問題
            if (++stuck > 3) throw new Error(j.error || '409');
            off = Math.max(0, j.have | 0);
            continue;
          }
          stuck = 0;
          off = end;
          if (j.file) p.file = j.file;
        }
      }
      return pieces
        .map(p => ({ file: p.file, name: p.name, w: p.w, h: p.h, bounds: p.bounds, opacity: p.opacity, on: p.on }))
        .filter(x => x.file);
    }

    /** 「重新編輯」：把上次留下的原稿抓回來，重建成清單上的那幾張。 */
    async function loadEdit() {
      if (!EDIT) return;
      const L = EDIT.layer || {};
      const set = (el, v) => { if (v !== undefined && v !== null && v !== '') $(el).value = v; };
      set('llabel', L.label); set('opacity', L.opacity); set('attr', L.attribution);
      set('zmin', L.minZoom); set('zmax', L.maxZoom);
      $('opacityval').textContent = Math.round(parseFloat($('opacity').value) * 100) + '%';
      if (L.pane) $('pane').value = L.pane;
      const project = $('project').value;
      const failed = [];
      for (const it of EDIT.pieces) {
        let url = '';
        try {
          const res = await fetch(BASE + '?api=tilecut&action=srcfile&project=' + encodeURIComponent(project)
            + '&id=' + encodeURIComponent(EDIT.id) + '&file=' + encodeURIComponent(it.file));
          if (!res.ok) throw new Error('HTTP ' + res.status);
          const blob = await res.blob();
          url = URL.createObjectURL(blob);
          const p = new Piece(it.name || it.file, await loadImage(url), url, blob);
          p.file = it.file;
          p.bounds = validBounds(it.bounds) ? it.bounds : defaultBounds(p);
          p.opacity = isFinite(it.opacity) ? Math.max(0, Math.min(1, it.opacity)) : 1;
          p.on = it.on !== false;
          pieces.push(p);     // edit.json 存的就是「上層在前」，照順序接上去
        } catch (e) {
          if (url) URL.revokeObjectURL(url);
          failed.push(it.name || it.file);
        }
      }
      if (failed.length) statusEl.textContent = failed.map(n => fmt(I18N.load_failed, { name: n })).join(' ');
      if (!pieces.length) return;
      // edit.json 裡沒有 ext／minZoom／maxZoom，代表上一版是保持向量輸出的——回填時把開關對回去
      if (!('ext' in L) && pieces.length === 1 && isSvgPiece(pieces[0])) $('vecmode').checked = true;
      select(0);
      const u = unionBounds();
      if (validBounds(u)) map.fitBounds([[u.s, u.w], [u.n, u.e]]);
    }

    /**
     * 「從圖磚重建」：沒留原稿時的降級路徑——把已經落地在伺服器上的圖磚，照原本切磚時的
     * 那個 zoom 拼回一張圖，當成新的來源重新對位、重切。拼回來的是壓平後的結果，
     * 原本幾張圖疊在一起的分層資訊已經回不去了。張數上限跟正常切磚共用同一個 MAX_TILES，
     * 所以最壞情況耗時跟切一次磚差不多，不特別支援中途停止。
     */
    async function reconstructFromTiles() {
      if (!RECON) return;
      const set = (el, v) => { if (v !== undefined && v !== null && v !== '') $(el).value = v; };
      set('llabel', RECON.label); set('opacity', RECON.opacity); set('attr', RECON.attribution);
      $('opacityval').textContent = Math.round(parseFloat($('opacity').value) * 100) + '%';
      if (RECON.pane) $('pane').value = RECON.pane;

      const gb = { s: RECON.bounds[0][0], w: RECON.bounds[0][1], n: RECON.bounds[1][0], e: RECON.bounds[1][1] };
      const r = tileRange(gb, RECON.z);
      const cols = r.x1 - r.x0 + 1, rows = r.y1 - r.y0 + 1;
      const total = cols * rows;
      if (total > MAX_TILES) { statusEl.textContent = fmt(I18N.too_many, { tiles: total, max: MAX_TILES }); return; }

      const btn = $('reconbtn');
      btn.disabled = true;
      doneEl.textContent = '';
      barEl.style.width = '0%';
      try {
        const project = $('project').value;
        const rc = document.createElement('canvas');
        rc.width = cols * TILE; rc.height = rows * TILE;
        const rctx = rc.getContext('2d');
        const tiles = [];
        for (let y = r.y0; y <= r.y1; y++) for (let x = r.x0; x <= r.x1; x++) tiles.push({ x, y });
        let done = 0;
        for (let i = 0; i < tiles.length; i += BATCH) {
          await Promise.all(tiles.slice(i, i + BATCH).map(({ x, y }) => new Promise(res => {
            const img = new Image();
            // layerfile.php 對缺磚回的是透明佔位圖（成功、不是錯誤），draw 目的地一律固定 TILE 大小，
            // 才不會因為佔位圖只有 1×1 而只畫出一個小點。抓失敗（真的壞掉）就當空白，不擋住整次重建。
            img.onload = () => { rctx.drawImage(img, (x - r.x0) * TILE, (y - r.y0) * TILE, TILE, TILE); done++; res(); };
            img.onerror = () => { done++; res(); };
            img.src = BASE + 'layer/' + encodeURIComponent(project) + '/' + encodeURIComponent(RECON.id)
              + '/tiles/' + RECON.z + '/' + x + '/' + y + '.' + RECON.ext;
          })));
          statusEl.textContent = fmt(I18N.recon_progress, { done, total });
          barEl.style.width = (done / total * 100).toFixed(1) + '%';
        }

        const n = 1 << RECON.z;
        const bounds = { w: lngOf(r.x0 / n), e: lngOf((r.x1 + 1) / n), n: latOf(r.y0 / n), s: latOf((r.y1 + 1) / n) };
        const blob = await new Promise(res => rc.toBlob(res, 'image/png'));
        const url = URL.createObjectURL(blob);
        const p = new Piece(RECON.id + '-tiles.png', await loadImage(url), url, blob);
        p.bounds = bounds;
        pieces.unshift(p);
        applyNativeZoom();
        select(0);
        map.fitBounds([[bounds.s, bounds.w], [bounds.n, bounds.e]]);
        statusEl.textContent = I18N.recon_done;
        barEl.style.width = '0%';
      } catch (e) {
        btn.disabled = false;
        statusEl.textContent = I18N.error_prefix + (e && e.message ? e.message : I18N.conn_failed);
      }
    }

    $('stop').addEventListener('click', () => { aborted = true; });

    $('go').addEventListener('click', async () => {
      const project = $('project').value;
      const id = $('lid').value.trim().toLowerCase();
      doneEl.textContent = '';
      if (!pieces.length) { statusEl.textContent = I18N.need_image; return; }
      const vec = vectorActive();
      const order = vec ? null : cutOrder();
      if (vec) {
        if (!pieces[0].on || pieces[0].opacity <= 0) { statusEl.textContent = I18N.no_visible; return; }
      } else if (!order.length) { statusEl.textContent = I18N.no_visible; return; }
      if (!/^[a-z0-9][a-z0-9_-]{0,31}$/.test(id)) { statusEl.textContent = I18N.need_id; return; }
      const b = unionBounds();
      if (!validBounds(b)) { statusEl.textContent = I18N.bad_bounds; return; }

      let z0, z1, total;
      if (!vec) {
        z0 = parseInt($('zmin').value, 10); z1 = parseInt($('zmax').value, 10);
        if (!(z0 <= z1)) { statusEl.textContent = I18N.bad_bounds; return; }
        total = totalTiles(b, z0, z1);
        if (total > MAX_TILES) { statusEl.textContent = fmt(I18N.too_many, { tiles: total, max: MAX_TILES }); return; }
      }

      running = true; aborted = false;
      $('go').disabled = true; $('stop').disabled = false;
      try {
        const fd0 = new FormData();
        fd0.append('action', 'begin'); fd0.append('csrf', csrf);
        fd0.append('project', project); fd0.append('id', id);
        if ($('overwrite').checked) fd0.append('overwrite', '1');
        await post(fd0);

        if (vec) {
          // 保持向量:不切磚,原稿(單張 SVG)一律強制送(force=true),因為它就是圖層本體,
          // 不是「使用者想不想留」的選項——keepsrc checkbox 在這個模式下本來就藏起來。
          const editPieces = await uploadSources(project, id, true);
          if (!aborted) {
            barEl.style.width = '100%';
            const fdF = new FormData();
            fdF.append('action', 'finish'); fdF.append('csrf', csrf);
            fdF.append('project', project); fdF.append('id', id);
            fdF.append('vector', '1');
            fdF.append('label', $('llabel').value.trim());
            fdF.append('pane', $('pane').value);
            fdF.append('opacity', $('opacity').value);
            fdF.append('attribution', $('attr').value.trim());
            fdF.append('south', b.s); fdF.append('west', b.w); fdF.append('north', b.n); fdF.append('east', b.e);
            if (editPieces && editPieces.length) fdF.append('edit', JSON.stringify({ pieces: editPieces }));
            await post(fdF);
            statusEl.textContent = I18N.vector_complete;
            doneEl.className = 'hint ok';
            doneEl.textContent = fmt(I18N.next_step, { id, project });
          }
        } else {
          const fmtInfo = await pickFormat();
          let cut = 0, sent = 0, skipped = 0, rejected = 0;

          // 原稿先送。切了十分鐘才發現空間不夠，那十分鐘就白費了
          const editPieces = await uploadSources(project, id);

          let batch = new FormData(), inBatch = 0;
          const flush = async () => {
            if (!inBatch) return;
            batch.append('action', 'tile'); batch.append('csrf', csrf);
            batch.append('project', project); batch.append('id', id);
            statusEl.textContent = fmt(I18N.uploading, { sent, total });
            const j = await post(batch);
            sent += j.saved || 0; rejected += j.rejected || 0;
            batch = new FormData(); inBatch = 0;
          };

          outer:
          for (let z = z0; z <= z1; z++) {
            const r = tileRange(b, z);
            for (let x = r.x0; x <= r.x1; x++) {
              for (let y = r.y0; y <= r.y1; y++) {
                if (aborted) break outer;
                cut++;
                const blob = await cutTile(z, x, y, order, fmtInfo);
                if (!blob) { skipped++; }
                else {
                  batch.append('tiles[' + z + '_' + x + '_' + y + ']', blob, z + '_' + x + '_' + y + '.' + fmtInfo.ext);
                  inBatch++;
                  if (inBatch >= BATCH) await flush();
                }
                if (cut % 25 === 0) {
                  statusEl.textContent = fmt(I18N.cutting, { z, i: cut, total, sent, skipped });
                  barEl.style.width = (cut / total * 100).toFixed(1) + '%';
                  await new Promise(r => setTimeout(r, 0));   // 讓出主執行緒，畫面才不會凍住
                }
              }
            }
          }
          await flush();
          barEl.style.width = '100%';

          if (aborted) { statusEl.textContent = fmt(I18N.stopped, { sent }); }
          else {
            const fdF = new FormData();
            fdF.append('action', 'finish'); fdF.append('csrf', csrf);
            fdF.append('project', project); fdF.append('id', id);
            fdF.append('label', $('llabel').value.trim());
            fdF.append('pane', $('pane').value);
            fdF.append('opacity', $('opacity').value);
            fdF.append('attribution', $('attr').value.trim());
            fdF.append('ext', fmtInfo.ext);
            fdF.append('south', b.s); fdF.append('west', b.w); fdF.append('north', b.n); fdF.append('east', b.e);
            fdF.append('minZoom', z0); fdF.append('maxZoom', z1);
            fdF.append('count', sent); fdF.append('pieces', order.length);
            if (editPieces && editPieces.length) fdF.append('edit', JSON.stringify({ pieces: editPieces }));
            await post(fdF);
            statusEl.textContent = fmt(I18N.complete, { sent, skipped, ext: fmtInfo.ext });
            doneEl.className = 'hint ok';
            doneEl.textContent = fmt(I18N.next_step, { id, project });
            if (rejected) doneEl.textContent += ' ' + fmt(I18N.rejected, { rejected });
          }
        }
      } catch (e) {
        statusEl.textContent = I18N.error_prefix + (e && e.message ? e.message : I18N.conn_failed);
      }
      running = false; $('stop').disabled = true; estimate();
    });

    if (RECON) $('reconbtn').addEventListener('click', reconstructFromTiles);

    // 載回原稿是非同步的（要一張張抓），先畫一次空清單，畫面才不會在那之前是一片空白
    refresh();
    loadEdit().then(refresh);
  </script>
</body>

</html>
