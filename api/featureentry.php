<?php
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/features.php';
$cfg = require __DIR__ . '/config.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['error' => 'POST only'], 405);
rate_limit($cfg, 'write');
$project = $_POST['project'] ?? '';
$id = $_POST['id'] ?? '';
$value = $_POST['featured'] ?? '';
if (!is_string($project) || !preg_match('/^[a-z0-9_-]{1,40}$/D', $project)
    || !is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id)
    || !in_array($value, ['0', '1'], true)
    || !is_dir($cfg['projects_dir'] . '/' . $project)) json_out(['error' => 'bad request'], 400);
Auth::require($cfg, $project, 'manage_contrib', true, '沒有管理投稿的權限');
try {
    $entry = store_find($cfg, $project, $id);
    if (!$entry || !empty($entry['edit_of']) || !in_array($entry['kind'] ?? 'photo', souliong_contrib_kinds(), true)) {
        json_out(['error' => 'not found'], 404);
    }
    $patched = store_patch($cfg, $project, $id, ['featured' => $value === '1']);
    if (!$patched) json_out(['error' => 'not found'], 404);
    json_out(['ok' => true, 'id' => $id, 'featured' => $patched['featured']]);
} catch (Throwable $e) {
    error_log('souliong featureentry: ' . $e->getMessage());
    json_out(['error' => 'server'], 500);
}
