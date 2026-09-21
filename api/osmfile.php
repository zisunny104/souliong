<?php
// 由 PHP 輸出專案預先抓取的 OSM 資料集（roofs／trees／power，由 tools/osm_fetch.php 寫入 projects/<p>/*.geojson；
// 框架不供應靜態檔，理由同 api/photo.php）。用法：<base>/osm/<project>/<roofs|trees|power>
//
// 跟圖層／模型檔一樣屬於公開地圖的一部分，不設登入關卡；寫入只有 CLI 工具，網路上沒有寫入口。
// 檔案不存在回空的 FeatureCollection（200），前端不必為「這張地圖沒抓過」處理 404。
// 內容會被重新抓取取代，所以用 no-cache 加 ETag，沒變就 304。
require_once __DIR__ . '/osmdata.php';
$cfg = require __DIR__ . '/config.php';

$path = souliong_osm_path($cfg, (string)($_GET['project'] ?? ''), (string)($_GET['kind'] ?? ''));
if ($path === null || !is_dir(dirname($path))) {
    http_response_code(404);
    exit;
}

$body = is_file($path) ? (string)file_get_contents($path) : '';
if ($body === '' || !is_array(json_decode($body, true))) {
    $body = json_encode(souliong_osm_empty());
}

$etag = '"' . md5($body) . '"';
header('Content-Type: application/geo+json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache');
header('ETag: ' . $etag);
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Length: ' . strlen($body));
echo $body;
