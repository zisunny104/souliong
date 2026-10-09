<?php
// 輸出第一方 CSS／JS 靜態資源（框架不供應靜態檔，見 api/photo.php／api/layerfile.php）。
// ?api=appasset&f=<assets/ 底下相對路徑>         固定的 CSS／JS
// ?api=appasset&pack=<id>&project=<proj，可省略>  主題包 pack.css，路徑由 souliong_pack_dir() 解析
require_once __DIR__ . '/packs.php';
$cfg = require __DIR__ . '/config.php';

$norm = fn($p) => str_replace('\\', '/', (string)$p);
$serve = function (string $absPath, string $ext): void {
    $mimes = ['css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'svg' => 'image/svg+xml'];
    header('Content-Type: ' . $mimes[$ext]);
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($absPath));
    header('Cache-Control: public, max-age=31536000, immutable');
    readfile($absPath);
    exit;
};

if (isset($_GET['pack'])) {
    $id = (string)$_GET['pack'];
    $proj = preg_replace('/[^a-z0-9_-]/', '', (string)($_GET['project'] ?? ''));
    $dir = souliong_pack_dir($cfg, $id, $proj);
    $real = $dir !== null ? realpath($dir . '/pack.css') : false;
    if ($real === false || !is_file($real)) {
        http_response_code(404);
        exit;
    }
    $serve($real, 'css');
}

$raw = (string)($_GET['f'] ?? '');
// 僅允許 assets/css/<name>.css 或 assets/js/<...>/<name>.js，字元白名單＋realpath 收尾。
if (strpos($raw, '..') !== false
    || ($raw !== 'assets/favicon.svg' && !preg_match('#^assets/(css/[a-z0-9_-]+\.css|js/(?:[a-z0-9_-]+/)*[a-z0-9_.-]+\.js)$#', $raw))) {
    http_response_code(400);
    exit;
}
$ext = strtolower(pathinfo($raw, PATHINFO_EXTENSION));
$root = realpath(__DIR__ . '/..');
$real = ($root === false) ? false : realpath($root . '/' . $raw);
$ok = $real !== false && $root !== false && strpos($norm($real), $norm($root) . '/') === 0 && is_file($real);
if (!$ok) {
    http_response_code(404);
    exit;
}
$serve($real, $ext);
