<?php
// 編輯點位本身的座標。權限是點位軸的 edit_spots（含 CSRF），預設僅限主要管理者，可個別授權給專案管理者。
// 點位不論來自靜態底稿（api/spotmigrate.php 併入的官方資料）還是 newspot.php 建立的動態點位，此刻都是
// spots.jsonl 裡同一種起點紀錄（一定有 num），因此不比照 editentry.php 驗 owner/ctoken。
// 不覆寫起點，而是新增一筆 kind:'spot' 版本紀錄（spot_append_version()），edit_of 指回起點紀錄 id；
// 版本紀錄是稀疏的，這支只寫 lat、lon，content 不動（疊加規則見 spot_effective()）。
// POST project, item_num（必填，起點的 num）, lat, lon, csrf, name（選填）。
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/spotlib.php';
$cfg = require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST only'], 405);
}
rate_limit($cfg, 'write');

$project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
if ($project === '' || !is_dir($cfg['projects_dir'] . '/' . $project)) {
    json_out(['error' => 'bad request'], 400);
}

$actor = Auth::require($cfg, $project, 'edit_spots', true, auth_msg('deny_edit_spot'));

$item_num = (isset($_POST['item_num']) && $_POST['item_num'] !== '') ? (int)$_POST['item_num'] : null;
$lat      = num_or_null($_POST['lat'] ?? null);
$lon      = num_or_null($_POST['lon'] ?? null);
if ($item_num === null || $lat === null || $lon === null || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
    json_out(['error' => 'bad request'], 400);
}

try {
    $eff = spot_effective($cfg, $project, $item_num);
    if ($eff === null) {
        json_out(['error' => '找不到這個點位'], 404);
    }
    $name   = clean_str($_POST['name'] ?? null, $cfg['name_max']) ?? '管理者';
    $record = spot_append_version($cfg, $project, $eff, ['lat' => $lat, 'lon' => $lon], $actor->audit(), $name);
    json_out(['ok' => true, 'item' => $record]);
} catch (Throwable $e) {
    error_log('souliong editspot: ' . $e->getMessage());
    json_out(['error' => 'server'] + (!empty($cfg['debug']) ? ['detail' => $e->getMessage()] : []), 500);
}
