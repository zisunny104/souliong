<?php
// 編輯點位本身的座標／精選內容：預設僅限主要管理者；主 PIN 可個別開啟特定專案 PIN 的 edit_spots 權限。
// 點位不論是原本來自靜態底稿（api/spotmigrate.php 併入 spots.jsonl 的官方/共享資料，無個別投稿者）
// 還是 newspot.php 建立的動態點位，此刻都已是 spots.jsonl 裡同一種起點記錄（一定有 num），因此不比照
// editentry.php 驗證 owner/ctoken，而是單純以 perm_check() 把關。
// 比照「故事」的版本化精神：不覆寫起點，而是新增一筆 kind:'spot' 版本紀錄，帶 edit_of 指回這個點位的
// 起點記錄 id。前端讀取時把同一條 edit_of 鏈的最新一筆疊加到起點原始狀態上（見 api/spotlib.php 的
// spot_effective()，viewer.core.js 的 effectiveSpots() 是同一套算法的前端版本）。
// 可覆寫欄位（lat/lon/feature/content，見 spot_overridable_fields()）採 merge-forward：伺服器先算出
// 目前有效狀態，只疊上這次請求裡「真的有送」的欄位，沒送的欄位沿用舊值——因此 feature 要清空
// （取消精選）必須明確送出空字串，不能靠「不送」，前端 submitSpotEdit() 已改成一律送。
// POST project, item_num（必填，對應起點的 num）, lat, lon, name(可留空), feature(可留空，精選投稿 id)。
require __DIR__ . '/store.php';
require __DIR__ . '/security.php';
require __DIR__ . '/spotlib.php';
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

try {
    $eff = spot_effective($cfg, $project, $item_num);
    if ($eff === null) {
        json_out(['error' => '找不到這個點位'], 404);
    }

    $editorName = clean_str_es($_POST['name'] ?? null, $cfg['name_max']) ?? '管理者';

    $changes = ['lat' => $lat, 'lon' => $lon];
    if (isset($_POST['feature'])) $changes['feature'] = clean_str_es($_POST['feature'], 64);
    $fields = spot_merge_forward($eff, $changes);

    $record = [
        'id'         => bin2hex(random_bytes(8)),
        'project'    => $project,
        'kind'       => 'spot',
        'item_num'   => $item_num,
        'edit_of'    => (string)$eff['id'],
        'name'       => $editorName,
        'lat'        => $fields['lat'],
        'lon'        => $fields['lon'],
        'feature'    => $fields['feature'],
        'content'    => $fields['content'],
        'created_at' => gmdate('c'),
    ];
    store_append($cfg, $project, $record);

    json_out(['ok' => true, 'item' => $record]);
} catch (Throwable $e) {
    error_log('souliong editspot: ' . $e->getMessage());
    json_out(['error' => 'server'] + (!empty($cfg['debug']) ? ['detail' => $e->getMessage()] : []), 500);
}
