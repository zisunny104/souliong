<?php
/**
 * 地圖標記自訂圖片。GET <base>?api=pinmark&project=<id> 輸出圖檔，供地圖標記取代編號／留空／
 * 幾何圖形用。上傳／重設僅限後台（api/manager.php 的 pinmarkupload／pinmarkreset），存檔邏輯
 * 共用 api/coverlib.php 的 pinmark_apply_bytes()／pinmark_apply_reset()。
 */
require __DIR__ . '/store.php';
require __DIR__ . '/coverlib.php';
$cfg = require __DIR__ . '/config.php';

$project = preg_replace('/[^a-z0-9_-]/', '', $_GET['project'] ?? '');
if ($project === '' || !is_dir($cfg['projects_dir'] . '/' . $project)) {
    http_response_code(404);
    exit;
}

$path = cover_file_of(project_dir($cfg, $project) . '/pinmark');
if ($path === null) {
    http_response_code(404);
    exit;
}
$ext = pathinfo($path, PATHINFO_EXTENSION);
header('Content-Type: ' . ($ext === 'webp' ? 'image/webp' : 'image/jpeg'));
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=300');
readfile($path);
