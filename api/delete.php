<?php
// 刪除自己的投稿：POST project, id, owner(token) 或 ctoken(投稿者跨裝置身分)。兩者擇一相符即可刪。
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/contribgate.php';
$cfg = require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_out(['error' => 'POST only'], 405); }
rate_limit($cfg, 'write');

$project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
$id      = (string)($_POST['id'] ?? '');
$who     = Contributor::fromRequest();
if ($project === '' || $id === '' || ($who->ownerHash() === null && $who->contribId() === null) || strlen($id) > 64 || !is_dir($cfg['projects_dir'] . '/' . $project)) {
    json_out(['error' => 'bad request'], 400);
}

try {
    $rec = store_find($cfg, $project, $id);
    if (!$rec) { json_out(['error' => 'not found'], 404); }
    if (!$who->owns($rec)) {
        json_out(['error' => '沒有權限刪除這則（可能是別人上傳的，或此裝置/身分的標記已更換）'], 403);
    }
    // 點位本身（kind:'spot'）不能自刪，即使 owner_hash 對得上：那是地點的識別記錄，
    // 不是可各自撤回的投稿，要刪只能透過有 edit_spots 權限的後台管理。
    if (($rec['kind'] ?? null) === 'spot') {
        json_out(['error' => '不能刪除點位本身'], 403);
    }
    $removed = store_delete($cfg, $project, $id);
    store_purge_files($cfg, $removed);   // 照片與影音的主檔＋縮圖一起清（見 store.php）
    json_out(['ok' => true, 'id' => $id]);
} catch (Throwable $e) {
    error_log('souliong delete: ' . $e->getMessage());
    json_out(['error' => 'server'] + (!empty($cfg['debug']) ? ['detail' => $e->getMessage()] : []), 500);
}
