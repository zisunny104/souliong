<?php
// 編輯自己（或管理者可管理的）投稿：只能改文字/關聯地點/定位，不能換檔案本身（照片、影片、音訊皆同）。
// POST project, edit_of(原始投稿 id), item_num(可留空), comment, lat, lon, loc_source, name, owner 或 ctoken（需與原投稿相符，或具管理權限）。
// 比照「故事」的版本化精神：不覆寫舊資料，而是新增一筆引用原始 id 的版本紀錄；原始紀錄與所有舊版本永久保留。
require __DIR__ . '/store.php';
require __DIR__ . '/security.php';
require __DIR__ . '/features.php';
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

function clean_str_ee(?string $s, int $max): ?string {
    if ($s === null) return null;
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s);
    $s = trim($s);
    if ($s === '') return null;
    if (preg_match('/^.{0,' . $max . '}/us', $s, $m)) $s = $m[0];
    return $s;
}
function num_or_null_ee($v) {
    if ($v === null || $v === '') return null;
    return is_numeric($v) ? (float)$v : null;
}

try {
    // 找出原始紀錄：只能編輯「排在投稿牆上的那幾種」（照片／影片／音訊／文字）。
    // desc（地點故事版本）有自己的版本機制；point／newpoint 是地點本身，走 editpoint.php／newpoint.php。
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

    // 權限：原投稿者本人（owner 或 ctoken 相符）或管理者（主 PIN 或此專案 PIN）
    $owner  = (string)($_POST['owner'] ?? '');
    $ctoken = (string)($_POST['ctoken'] ?? '');
    $ownerStored   = (string)($orig['owner_hash'] ?? '');
    $contribStored = (string)($orig['contrib_hash'] ?? '');
    $ownerOk   = $owner !== '' && $ownerStored !== '' && hash_equals($ownerStored, hash('sha256', $owner));
    $contribOk = $ctoken !== '' && $contribStored !== '' && hash_equals($contribStored, contrib_hash_of($ctoken));
    $isAdmin   = !$ownerOk && !$contribOk && admin_perm($cfg, $project, 'edit_others');
    if (!$ownerOk && !$contribOk && !$isAdmin) {
        json_out(['error' => '沒有權限編輯這則（只有原投稿者本人或管理者可以）'], 403);
    }
    // CSRF：owner／ctoken 是跨站讀不到的 bearer 秘密，本身就有等同 CSRF token 的防偽效果，不需再檢查；
    // 但管理者是靠 cookie 驗證，跨站請求會自動夾帶 cookie，比照 editpoint.php 另加一道 CSRF 驗證。
    if ($isAdmin) {
        $isPrimary = admin_authed($cfg);
        $acctCsrf = $isPrimary ? null : account_current($cfg);
        $csrfExpected = $isPrimary
            ? admin_derived($cfg)
            : ($acctCsrf !== null ? account_derived($cfg, (string)$acctCsrf['id']) : padm_derived($cfg, $project, (string)padm_pin_id($cfg, $project)));
        if (!hash_equals($csrfExpected, (string)($_POST['csrf'] ?? ''))) {
            json_out(['error' => '憑證失效，請重新整理頁面後再操作一次'], 403);
        }
    }

    $comment    = clean_str_ee($_POST['comment'] ?? null, $cfg['comment_max']);
    $item_num   = (isset($_POST['item_num']) && $_POST['item_num'] !== '') ? (int)$_POST['item_num'] : null;
    $lat        = num_or_null_ee($_POST['lat'] ?? null);
    $lon        = num_or_null_ee($_POST['lon'] ?? null);
    $loc_source = clean_str_ee($_POST['loc_source'] ?? null, 16) ?? 'manual';
    if ($lat !== null && ($lat < -90 || $lat > 90)) $lat = null;
    if ($lon !== null && ($lon < -180 || $lon > 180)) $lon = null;

    $editorName  = clean_str_ee($_POST['name'] ?? null, $cfg['name_max']) ?? '匿名';
    $contribId   = $ctoken !== '' ? contrib_id_of($ctoken) : null;
    $contribHash = $ctoken !== '' ? contrib_hash_of($ctoken) : null;
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
        'photo'        => null,                  // 編輯不換檔案本身（照片／影音同理），顯示時沿用原始那筆
        'media'        => null,
        'photo_time'   => $orig['photo_time'] ?? null,
        'duration'     => $orig['duration'] ?? null,   // 跟 photo_time 同理：影音長度是原始檔案的事實，不能因為編輯就清空
        'lat'          => $lat,
        'lon'          => $lon,
        'loc_source'   => $loc_source,
        'exif'         => $orig['exif'] ?? null,  // 相機資訊是拍攝當下的不變事實，跟 photo_time 一樣要沿用原始值，不能因為編輯就清空
        'owner_hash'   => $owner !== '' ? hash('sha256', $owner) : null,
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
