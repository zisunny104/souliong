<?php
// 編輯定位點（椅子）本身的座標：預設僅限主要管理者；主 PIN 可個別開啟特定專案 PIN 的 edit_points 權限。
// 椅子資料來自靜態 chairs.json（匯入的官方/共享資料，無個別投稿者），因此不比照 editentry.php 驗證 owner/ctoken，
// 而是單純以 perm_check() 把關。比照「故事」的版本化精神：不覆寫 chairs.json，而是新增一筆版本紀錄，
// 前端讀取時取同一 item_num 底下最新一筆 kind:'point' 紀錄覆蓋原始座標（見 viewer.core.js 的 effectivePoints()）。
// POST project, item_num（必填，對應 chairs.json 的 num）, lat, lon, name(可留空)。
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

if (!perm_check($cfg, $project, 'edit_points')) {
    json_out(['error' => '沒有權限編輯定位點（僅限主要管理者，或已被授權的專案管理者）'], 403);
}

// CSRF：值＝同一支登入身分在 view.php 才拿得到的衍生值（見 $APP.csrf），跨站請求讀不到頁面內容故無法偽造
$csrfExpected = primary_authed($cfg) ? primary_derived($cfg) : pin_derived($cfg, $project, (string)pin_current_id($cfg, $project));
if (!hash_equals($csrfExpected, (string)($_POST['csrf'] ?? ''))) {
    json_out(['error' => '憑證失效，請重新整理頁面後再操作一次'], 403);
}

function clean_str_ep(?string $s, int $max): ?string {
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

try {
    $editorName = clean_str_ep($_POST['name'] ?? null, $cfg['name_max']) ?? '管理者';

    $record = [
        'id'         => bin2hex(random_bytes(8)),
        'project'    => $project,
        'kind'       => 'point',
        'item_num'   => $item_num,
        'name'       => $editorName,
        'lat'        => $lat,
        'lon'        => $lon,
        'created_at' => gmdate('c'),
    ];
    store_append($cfg, $project, $record);

    json_out(['ok' => true, 'item' => $record]);
} catch (Throwable $e) {
    error_log('souliong editpoint: ' . $e->getMessage());
    json_out(['error' => 'server'] + (!empty($cfg['debug']) ? ['detail' => $e->getMessage()] : []), 500);
}
