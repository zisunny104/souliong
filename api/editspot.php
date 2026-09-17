<?php
// 編輯點位本身的座標／精選內容：預設僅限主要管理者；主 PIN 可個別開啟特定專案 PIN 的 edit_spots 權限。
// 點位不論是原本來自靜態底稿（api/spotmigrate.php 併入 spots.jsonl 的官方/共享資料，無個別投稿者）
// 還是 newspot.php 建立的動態點位，此刻都已是 spots.jsonl 裡同一種起點記錄（一定有 num），因此不比照
// editentry.php 驗證 owner/ctoken，而是單純以 perm_check() 把關。
// 比照「故事」的版本化精神：不覆寫起點，而是新增一筆 kind:'spot' 版本紀錄，帶 edit_of 指回這個點位的
// 起點記錄 id。前端讀取時把同一條 edit_of 鏈的最新一筆疊加到起點原始座標上（見 viewer.core.js 的
// effectiveSpots()）。
// POST project, item_num（必填，對應起點的 num）, lat, lon, name(可留空), feature(可留空，精選投稿 id)。
require __DIR__ . '/store.php';
require __DIR__ . '/security.php';
$cfg = require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST only'], 405);
}
rate_limit($cfg, 'write');

$project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
if ($project === '' || !is_dir($cfg['projects_dir'] . '/' . $project)) {
    json_out(['error' => 'bad request'], 400);
}

if (!perm_check($cfg, $project, 'edit_spots')) {
    json_out(['error' => '沒有權限編輯定位點（僅限主要管理者，或已被授權的專案管理者）'], 403);
}

// CSRF：值＝同一支登入身分在 view.php 才拿得到的衍生值（見 $APP.csrf），跨站請求讀不到頁面內容故無法偽造
$csrfExpected = primary_authed($cfg) ? primary_derived($cfg) : pin_derived($cfg, $project, (string)pin_current_id($cfg, $project));
if (!hash_equals($csrfExpected, (string)($_POST['csrf'] ?? ''))) {
    json_out(['error' => '憑證失效，請重新整理頁面後再操作一次'], 403);
}

function clean_str_es(?string $s, int $max): ?string {
    if ($s === null) return null;
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s);
    $s = trim($s);
    if ($s === '') return null;
    if (preg_match('/^.{0,' . $max . '}/us', $s, $m)) $s = $m[0];
    return $s;
}

$item_num = (isset($_POST['item_num']) && $_POST['item_num'] !== '') ? (int)$_POST['item_num'] : null;
$lat      = is_numeric($_POST['lat'] ?? null) ? (float)$_POST['lat'] : null;
$lon      = is_numeric($_POST['lon'] ?? null) ? (float)$_POST['lon'] : null;
if ($item_num === null || $lat === null || $lon === null || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
    json_out(['error' => 'bad request'], 400);
}
$feature = clean_str_es($_POST['feature'] ?? null, 64);

try {
    // 找這個點位的起點記錄：不管是 api/newspot.php 動態建立的，還是 api/spotmigrate.php
    // 從靜態底稿併入的，起點一定有 num、edit_of 留空（見 effectiveSpots() 同一套判斷式）。
    $editOf = '';
    foreach (_store_read_lines(store_file($cfg, $project, 'spot')) as $r) {
        if (empty($r['edit_of']) && isset($r['num']) && (int)($r['item_num'] ?? -1) === $item_num) {
            $editOf = (string)$r['id'];
            break;
        }
    }

    $editorName = clean_str_es($_POST['name'] ?? null, $cfg['name_max']) ?? '管理者';

    $record = [
        'id'         => bin2hex(random_bytes(8)),
        'project'    => $project,
        'kind'       => 'spot',
        'item_num'   => $item_num,
        'edit_of'    => $editOf,
        'name'       => $editorName,
        'lat'        => $lat,
        'lon'        => $lon,
        'feature'    => $feature,
        'created_at' => gmdate('c'),
    ];
    store_append($cfg, $project, $record);

    json_out(['ok' => true, 'item' => $record]);
} catch (Throwable $e) {
    error_log('souliong editspot: ' . $e->getMessage());
    json_out(['error' => 'server'] + (!empty($cfg['debug']) ? ['detail' => $e->getMessage()] : []), 500);
}
