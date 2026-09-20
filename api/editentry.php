<?php
// 編輯自己（或管理者可管理的）投稿：只能改文字/關聯地點/定位，不能換檔案本身（照片、影片、音訊皆同）。
// POST project, edit_of(原始投稿 id), item_num(可留空), comment, source_url, lat, lon, loc_source, name, owner 或 ctoken（需與原投稿相符，或具管理權限）。
// 比照「故事」的版本化精神：不覆寫舊資料，而是新增一筆引用原始 id 的版本紀錄；原始紀錄與所有舊版本永久保留。
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/contribgate.php';
require_once __DIR__ . '/features.php';
$cfg = require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST only'], 405);
}
rate_limit($cfg, 'write');

$project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
$editOf  = (string)($_POST['edit_of'] ?? '');
if ($project === '' || $editOf === '' || strlen($editOf) > 64 || !is_dir($cfg['projects_dir'] . '/' . $project)) {
    json_out(['error' => 'bad request'], 400);
}

try {
    // 找出原始紀錄：只能編輯「排在投稿牆上的那幾種」（照片／影片／音訊／文字）。
    // spot 是地點本身，走 editspot.php／newspot.php；內容區走 spotcontent.php。
    $orig = null;
    foreach (store_all($cfg, $project) as $r) {
        if ((string)($r['id'] ?? '') === $editOf) { $orig = $r; break; }
    }
    if (!$orig) {
        json_out(['error' => 'not found'], 404);
    }
    $origKind = (string)($orig['kind'] ?? 'photo');   // 多型別之前的舊記錄沒有 kind，一律是照片
    $hasBody  = !empty($orig['photo']) || !empty($orig['media']) || ($origKind === 'text' && !empty($orig['comment']));
    if (!in_array($origKind, souliong_contrib_kinds(), true) || !$hasBody) {
        json_out(['error' => 'not found'], 404);
    }

    // 權限：原投稿者本人（owner 或 ctoken 相符）不需 CSRF（bearer 秘密本身跨站讀不到）；
    // 否則要有 edit_others，並照 Auth::require 的規則驗 CSRF（cookie 身分跨站會自動夾帶）。
    $who = Contributor::fromRequest();
    if (!$who->owns($orig)) {
        Auth::require($cfg, $project, 'edit_others', true, auth_msg('deny_edit_entry'));
    }

    $comment    = clean_str($_POST['comment'] ?? null, $cfg['comment_max']);
    $source_url = clean_str($_POST['source_url'] ?? null, 500);
    if ($source_url !== null && (!preg_match('#^https?://#i', $source_url) || filter_var($source_url, FILTER_VALIDATE_URL) === false)) {
        $source_url = null;
    }
    $item_num   = (isset($_POST['item_num']) && $_POST['item_num'] !== '') ? (int)$_POST['item_num'] : null;
    $lat        = num_or_null($_POST['lat'] ?? null);
    $lon        = num_or_null($_POST['lon'] ?? null);
    $loc_source = clean_str($_POST['loc_source'] ?? null, 16) ?? 'manual';
    if ($lat !== null && ($lat < -90 || $lat > 90)) $lat = null;
    if ($lon !== null && ($lon < -180 || $lon > 180)) $lon = null;

    $editorName  = clean_str($_POST['name'] ?? null, $cfg['name_max']) ?? '匿名';
    $contribId   = $who->contribId();
    $contribHash = $who->contribHash();
    $srcHash     = !empty($cfg['log_src']) ? substr(hash('sha256', ($cfg['ip_salt'] ?? '') . '|' . client_ip($cfg)), 0, 16) : null;

    $record = [
        'id'           => bin2hex(random_bytes(8)),
        'project'      => $project,
        'item_num'     => $item_num,
        // 種類沿用被編輯的那一筆：影片的編輯紀錄若標成 photo，後台投稿列表的種類欄會整排標錯
        'kind'         => $origKind,
        'edit_of'      => $editOf,               // 指向被編輯的原始投稿 id，供前端組出「最新版本」
        'name'         => $editorName,
        'comment'      => $comment,
        'source_url'   => $source_url,
        'photo'        => null,                  // 編輯不換檔案本身（照片／影音同理），顯示時沿用原始那筆
        'media'        => null,
        'photo_time'   => $orig['photo_time'] ?? null,
        'duration'     => $orig['duration'] ?? null,   // 跟 photo_time 同理：影音長度是原始檔案的事實，不能因為編輯就清空
        'lat'          => $lat,
        'lon'          => $lon,
        'loc_source'   => $loc_source,
        'exif'         => $orig['exif'] ?? null,  // 相機資訊是拍攝當下的不變事實，跟 photo_time 一樣要沿用原始值，不能因為編輯就清空
        'owner_hash'   => $who->ownerHash(),
        'src_hash'     => $srcHash,
        'contrib_id'   => $contribId,
        'contrib_hash' => $contribHash,
        'created_at'   => gmdate('c'),
    ];
    store_append($cfg, $project, $record);

    $out = $record;
    unset($out['src_hash'], $out['contrib_hash']);
    json_out(['ok' => true, 'item' => $out]);
} catch (Throwable $e) {
    error_log('souliong editentry: ' . $e->getMessage());
    json_out(['error' => 'server'] + (!empty($cfg['debug']) ? ['detail' => $e->getMessage()] : []), 500);
}
