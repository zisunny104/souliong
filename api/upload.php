<?php
// POST upload.php  (multipart/form-data)
// 共同欄位：project, item_num, kind, name, comment, source_url, photo_time(ISO), lat, lon, loc_source
// 依 kind 而不同的檔案欄位（見 features.php 的 souliong_kinds()）：
//   photo → photo(檔案) + thumb(檔案，選填)
//   video → media(檔案) + thumb(檔案，選填) + duration(秒)
//   audio → media(檔案) + duration(秒)
//   text / desc → 無檔案，只要 comment
// append-only：本後端無刪除/修改端點。
require __DIR__ . '/store.php';
require __DIR__ . '/security.php';
require __DIR__ . '/stats.php';
require __DIR__ . '/features.php';
$cfg = require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST only'], 405);
}
rate_limit($cfg, 'upload');

$project = $_POST['project'] ?? '';
if (!preg_match('/^[a-z0-9_-]{1,40}$/', $project) || !is_dir($cfg['projects_dir'] . '/' . $project)) {
    json_out(['error' => 'unknown project'], 400);
}

// 停權檢查：被主辦者鎖定的身分（PIN 投稿者或匿名裝置）一律擋下，不論投稿代碼是否有效（不影響已投稿內容）。
$blockOwnerHash = !empty($_POST['owner']) ? hash('sha256', (string)$_POST['owner']) : null;
$blockContribId = !empty($_POST['ctoken']) ? contrib_id_of((string)$_POST['ctoken']) : null;
if (is_blocked($cfg, $project, $blockOwnerHash, $blockContribId)) {
    json_out(['error' => '此身分已被主辦者停權，無法繼續投稿'], 403);
}

// 投稿代碼：限特定人上傳（碼存後端檔案，前端拿不到，這裡才是真正把關）。已用管理 PIN 登入者視為已解鎖。
// 能不能投稿完全看投稿代碼（codes.json，各自可設到期/次數）：一組有效碼都沒有＝這張地圖現在沒開放投稿；
// 有碼就一定要附碼，這裡順便計一次使用。
$givenCode = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
if (!perm_can($cfg, $project)) {
    if (!contrib_open($cfg, $project)) {
        json_out(['error' => '這張地圖目前未開放投稿'], 403);
    }
    if (!code_check($cfg, $project, $givenCode, true)) {
        json_out(['error' => '需要正確的投稿代碼才能上傳（碼可能已到期或用完次數）'], 403);
    }
}

function clean_str(?string $s, int $max): ?string {
    if ($s === null) return null;
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s); // 去除控制字元
    $s = trim($s);
    if ($s === '') return null;
    // 以 UTF-8 為單位截斷（用 PCRE /u，不依賴 mbstring）
    if (preg_match('/^.{0,' . $max . '}/us', $s, $m)) $s = $m[0];
    return $s;
}
function num_or_null($v) {
    if ($v === null || $v === '') return null;
    return is_numeric($v) ? (float)$v : null;
}

// kind 白名單看的是 postable 而不是「註冊表裡有沒有這個 key」——point／newpoint 也在註冊表裡，
// 但它們只能由 editpoint.php／newpoint.php 在權限檢查後寫入，放行等於開後門讓任何人偽造
// 座標覆蓋紀錄（詳見 features.php 的 souliong_kinds() 說明）。
// 沒送 kind ＝照片（多型別上線前的客戶端就是這樣送的，維持相容）；送了但不可 POST 或根本不認識，
// 直接 400 擋掉——不要默默改判成照片存一筆進去，那會把「被拒絕的請求」變成一筆真的紀錄。
$kindIn = trim((string)($_POST['kind'] ?? ''));
if ($kindIn !== '' && !souliong_kind_postable($kindIn)) {
    json_out(['error' => 'bad kind'], 400);
}
$kind    = $kindIn !== '' ? $kindIn : 'photo';
$kindDef = souliong_kinds()[$kind];

// 再確認這張地圖有沒有開放這個內容種類（meta.json 的 contrib.kinds）。desc 不在對話框的種類
// 清單裡、由 story 模組自己把關，所以不受這條限制。沒設定 contrib 的舊地圖解析出來就是
// ['photo']，前端不送 kind 時的預設值也是 photo，因此既有投稿流程完全不受影響。
$metaU = json_decode((string)@file_get_contents($cfg['projects_dir'] . '/' . $project . '/meta.json'), true);
$contribCfg = souliong_contrib_cfg($metaU);
if (in_array($kind, souliong_contrib_kinds(), true) && !in_array($kind, $contribCfg['kinds'], true)) {
    json_out(['error' => '這張地圖沒有開放這種投稿：' . souliong_kind_label($kind)], 403);
}
$name       = clean_str($_POST['name'] ?? null, $cfg['name_max']) ?? '匿名';
$comment    = clean_str($_POST['comment'] ?? null, $cfg['comment_max']);
// 資料來源連結（如引用音源的原始頁面）：只收 http(s) 網址，格式不對就當沒填，不擋整筆投稿。
$source_url = clean_str($_POST['source_url'] ?? null, 500);
if ($source_url !== null && (!preg_match('#^https?://#i', $source_url) || filter_var($source_url, FILTER_VALIDATE_URL) === false)) {
    $source_url = null;
}
// 引用來源本身的授權（跟下面 $license「投稿者本人姓名標示權利」是兩件事，不共用欄位）
$source_license = in_array($_POST['source_license'] ?? '', ['cc0', 'cc-by'], true) ? $_POST['source_license'] : null;
$item_num   = (isset($_POST['item_num']) && $_POST['item_num'] !== '') ? (int)$_POST['item_num'] : null;
$lat        = num_or_null($_POST['lat'] ?? null);
$lon        = num_or_null($_POST['lon'] ?? null);
$loc_source = clean_str($_POST['loc_source'] ?? null, 16);
$photo_time = clean_str($_POST['photo_time'] ?? null, 40);
// 影音長度（秒，前端從 <video>/<audio> 的 metadata 讀）。上限 24 小時純粹是防止塞離譜的值進資料，
// 真正限制長度的是檔案大小。取不到長度（串流式 webm 常見）就存 null，顯示端自己 fallback。
$duration = is_numeric($_POST['duration'] ?? null) ? round((float)$_POST['duration'], 2) : null;
if ($duration !== null && ($duration <= 0 || $duration > 86400)) $duration = null;
if ($lat !== null && ($lat < -90 || $lat > 90)) $lat = null;
if ($lon !== null && ($lon < -180 || $lon > 180)) $lon = null;

// 授權：CC BY（姓名標示）只對已建立身分（有 ctoken）的投稿者開放，沒有穩定身分就沒有名字可標示，
// 一律以伺服器端這裡認定的身分為準，不採信前端畫面上勾選框當下是否可見。
$hasIdentity = !empty($_POST['ctoken']);
$license     = ($hasIdentity && ($_POST['license'] ?? '') === 'cc-by') ? 'cc-by' : 'cc0';
$wikidataOk  = !empty($_POST['wikidata_ok']);

/**
 * 判斷上傳檔的實際 MIME。圖片優先用 getimagesize()（不依賴 fileinfo 擴充，較可攜）；
 * 影音沒有等價的可攜函式，只能靠 finfo，主機沒裝 fileinfo 擴充時影音就一律收不了。
 * 這是刻意的：絕對不能改用 $_FILES['type']，那個值由瀏覽器（也就是投稿者）說了算、可任意偽造，
 * 拿它當白名單等於沒有白名單。
 */
function detect_mime(string $tmp): string {
    $info = @getimagesize($tmp);
    if (is_array($info) && !empty($info['mime'])) return (string)$info['mime'];
    if (class_exists('finfo')) {
        $m = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (is_string($m) && $m !== '') return $m;
    }
    return '';
}

// 照片沿用歷史的 photo/thumb 欄位與 photos/ 目錄，影音走新的 media 欄位與 media/ 目錄。
// 這樣切是為了讓既有的 exiffix.php／thumbfix.php／editentry.php／photo.php 與前端的
// photoFullUrl() 一行都不用改，舊資料與舊流程完全不受影響（見 features.php 的 file 欄位說明）。
$photoRel  = null;
$thumbRel  = null;
$mediaRel  = null;
$mediaMime = null;

$fileField = $kindDef['file'] ?? null;
$isPhoto   = ($fileField === 'photo');
if ($fileField !== null && isset($_FILES[$fileField]) && $_FILES[$fileField]['error'] === UPLOAD_ERR_OK) {
    $f = $_FILES[$fileField];
    $maxBytes = (int)($cfg['max_bytes_' . $kind] ?? $kindDef['max_bytes'] ?? $cfg['max_bytes']);
    if ($f['size'] > $maxBytes) {
        json_out(['error' => 'file too large'], 413);
    }
    // 照片維持吃 config 的 allowed_mime（部署端本來就能調的旋鈕），其他種類用註冊表的 mimes
    $mimes = ($isPhoto && !empty($cfg['allowed_mime'])) ? $cfg['allowed_mime'] : ($kindDef['mimes'] ?? []);
    $mime  = detect_mime($f['tmp_name']);
    if (!isset($mimes[$mime])) {
        json_out(['error' => 'unsupported type: ' . ($mime === '' ? '(unknown)' : $mime)], 415);
    }
    $ext = $mimes[$mime];
    $destDir = project_dir($cfg, $project) . '/' . ($isPhoto ? 'photos' : 'media');
    if (!is_dir($destDir)) { @mkdir($destDir, 0775, true); }
    // 檔名用「內容實際的時間」（照片 EXIF／檔案修改時間），不是伺服器收到上傳的時間，方便直接依檔名辨識先後
    $shotTs = $photo_time !== null ? strtotime($photo_time) : false;
    $fbase = date('Ymd_His', $shotTs !== false ? $shotTs : time()) . '_' . bin2hex(random_bytes(4));
    $fname = $fbase . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $destDir . '/' . $fname)) {
        json_out(['error' => 'save failed'], 500);
    }
    if ($isPhoto) { $photoRel = $project . '/' . $fname; }
    else { $mediaRel = $project . '/' . $fname; $mediaMime = $mime; }

    // 顯示用縮圖（照片由前端隨主圖一起轉、影片由前端抽第一幀；沒有或存失敗都不影響投稿本身）。
    // 縮圖永遠是圖片，所以驗證一律走照片那組 mime，跟主檔是什麼種類無關。
    if (!empty($kindDef['thumb']) && isset($_FILES['thumb']) && $_FILES['thumb']['error'] === UPLOAD_ERR_OK) {
        $tf = $_FILES['thumb'];
        $timgs = !empty($cfg['allowed_mime']) ? $cfg['allowed_mime'] : souliong_kinds()['photo']['mimes'];
        $tmime = detect_mime($tf['tmp_name']);
        if ($tf['size'] <= 1024 * 1024 && isset($timgs[$tmime])) {
            // 縮圖跟主檔放同一個目錄，命名規則 <主檔名>_t.<副檔名>——photo.php 的 photo_thumb_of()
            // 就是照這個規則找檔的，影音的縮圖也沿用同一套，media.php 才不用另外發明一種規則。
            $tname = $fbase . '_t.' . $timgs[$tmime];
            if (@move_uploaded_file($tf['tmp_name'], $destDir . '/' . $tname)) {
                $thumbRel = $project . '/' . $tname;
            }
        }
    }
}

if ($kind === 'desc') {
    // 說明版本：必須有文字與所屬點位
    if ($comment === null) { json_out(['error' => 'need comment'], 400); }
    if ($item_num === null) { json_out(['error' => 'need item_num'], 400); }
} elseif ($kind === 'text') {
    if ($comment === null) { json_out(['error' => 'need comment'], 400); }
} elseif (!$isPhoto && $fileField !== null) {
    // 影片／音訊投稿沒有檔案就沒有意義（照片可以只留一段話，那是既有行為，維持不變）
    if ($mediaRel === null) { json_out(['error' => 'need media file'], 400); }
} elseif ($photoRel === null && $comment === null) {
    json_out(['error' => 'need photo or comment'], 400);
}

try {
    $owner = (string)($_POST['owner'] ?? '');
    $ownerHash = $blockOwnerHash;

    // 相機 EXIF 參數（前端送 JSON；白名單欄位、限縮長度與大小）
    $exif = null;
    if (!empty($_POST['exif']) && strlen((string)$_POST['exif']) <= 600) {
        $e = json_decode((string)$_POST['exif'], true);
        if (is_array($e)) {
            $exif = [];
            foreach (['make', 'model', 'lens', 'sw'] as $k) {
                if (isset($e[$k])) { $v = clean_str((string)$e[$k], 60); if ($v !== null) $exif[$k] = $v; }
            }
            foreach (['f', 'exp', 'focal'] as $k) {
                if (isset($e[$k]) && is_numeric($e[$k])) $exif[$k] = (float)$e[$k];
            }
            if (isset($e['iso']) && is_numeric($e['iso'])) $exif['iso'] = (int)$e['iso'];
            if (!$exif) $exif = null;
        }
    }

    // 冒名鑑識：加鹽來源 IP 雜湊（僅管理端可見，list 不外流；可於 config 關閉）
    $srcHash = !empty($cfg['log_src']) ? substr(hash('sha256', ($cfg['ip_salt'] ?? '') . '|' . client_ip($cfg)), 0, 16) : null;

    // 可選的投稿者身分（ctoken 由 localStorage 帶入）：存公開短 ID 供分組顯示、存 hash 供跨裝置驗刪
    $ctoken = (string)($_POST['ctoken'] ?? '');
    $contribId = $blockContribId;
    $contribHash = $ctoken !== '' ? contrib_hash_of($ctoken) : null;

    $record = [
        'id'         => bin2hex(random_bytes(8)),
        'project'    => $project,
        'item_num'   => $item_num,
        'kind'       => $kind,
        'name'       => $name,
        'comment'    => $comment,
        'source_url' => $source_url,
        'source_license' => $source_license,
        'photo'      => $photoRel,
        'thumb'      => $thumbRel,
        'media'      => $mediaRel,                                        // 影音檔（照片不用這欄，見上面的目錄切分說明）
        'media_mime' => $mediaMime,
        'duration'   => $duration,
        'photo_time' => $photo_time,
        'lat'        => $lat,
        'lon'        => $lon,
        'loc_source' => $loc_source,
        'license'    => $license,
        'wikidata_ok'=> $wikidataOk,
        'exif'       => $exif,
        'owner_hash' => $ownerHash,                                       // 用於「只刪自己的」與同源追蹤
        'src_hash'   => $srcHash,                                        // 冒名鑑識用，不外流
        'contrib_id' => $contribId,                                      // 可選：對外可見的假名投稿者ID（分組用）
        'contrib_hash' => $contribHash,                                 // 可選：跨裝置驗刪用，不外流
        'created_at' => gmdate('c'),
    ];
    store_append($cfg, $project, $record);

    // 統計：上傳數 + 投稿種類 + 相機型號（有上限，防膨脹）
    stats_apply($cfg, $project, function (&$s) use ($exif, $kind) {
        stats_bump($s, 'uploads');
        stats_bump($s, 'kinds', $kind, 10);
        if ($exif && !empty($exif['model'])) stats_bump($s, 'cameras', $exif['model'], 300);
    });

    $out = $record;
    unset($out['src_hash'], $out['contrib_hash']);                      // 不外流 IP 雜湊與身分驗刪雜湊
    $out['photo_url'] = $photoRel ? ('photos/' . $photoRel) : null;
    $out['media_url'] = $mediaRel ? ('media/' . $mediaRel) : null;
    json_out(['ok' => true, 'item' => $out]);
} catch (Throwable $e) {
    error_log('souliong upload: ' . $e->getMessage());
    json_out(['error' => 'server'] + (!empty($cfg['debug']) ? ['detail' => $e->getMessage()] : []), 500);
}
