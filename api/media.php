<?php
// 由 PHP 輸出影片／音訊檔（框架不供應靜態檔）。用法：?api=media&f=<project>/<file>
// &th=1 輸出既有的 <檔名>_t.* 縮圖（影片封面，由前端抽第一幀在投稿時一起上傳）。
//
// 跟 photo.php 的兩個差異，都是刻意的：
//   1. **支援 HTTP Range**。<audio>/<video> 靠 Range 才能拖曳進度條，Safari 更是拿不到
//      Accept-Ranges 就直接不播。這是這支檔案存在的主要理由，不是「順便加的優化」。
//   2. 縮圖只找不生。photo.php 找不到縮圖時會用 GD 現產一張，但 GD 解不了影格，
//      這裡找不到就回 404，由前端退回圖示顯示。
require __DIR__ . '/store.php';
$cfg = require __DIR__ . '/config.php';

$f = $_GET['f'] ?? '';
// 僅允許 <project>/<檔名>，禁止路徑穿越
if (strpos($f, '..') !== false || !preg_match('#^[a-z0-9_-]+/[A-Za-z0-9_.-]+$#', $f)) {
    http_response_code(400);
    exit;
}
$path = media_abs_path($cfg, $f);
if ($path === null || !is_file($path)) {
    http_response_code(404);
    exit;
}
if (!empty($_GET['th'])) {
    $base = preg_replace('/\.[A-Za-z0-9]+$/', '', $path);
    $thumb = null;
    if (substr($base, -2) !== '_t') {
        foreach (['webp', 'jpg', 'png'] as $te) {
            if (is_file($base . '_t.' . $te)) { $thumb = $base . '_t.' . $te; break; }
        }
    }
    if ($thumb === null) {
        http_response_code(404);
        exit;
    }
    $path = $thumb;
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mimes = [
    'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
    'weba' => 'audio/webm', 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4',
    'aac' => 'audio/aac', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav',
    // 縮圖（th=1）也走這支輸出
    'webp' => 'image/webp', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
];
$mime = $mimes[$ext] ?? 'application/octet-stream';
$size = filesize($path);

// 有 gzip 之類的輸出緩衝在跑時，送出去的 Content-Length 會跟實際位元組數對不上，
// 播放器（尤其是還要靠 Range 續傳的）會直接壞掉，所以先把緩衝全部關掉。
while (ob_get_level() > 0) { @ob_end_clean(); }

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=31536000, immutable');
header('Accept-Ranges: bytes');
// 檔名是我們自己產的亂數，附上只是讓使用者另存時有個合理名字
header('Content-Disposition: inline; filename="' . basename($path) . '"');

$start = 0;
$end   = $size - 1;
$range = $_SERVER['HTTP_RANGE'] ?? '';
// 只處理單一區間。多重區間（bytes=0-99,200-299）依 RFC 允許整份回 200，播放器一律吃得下，
// 不值得為它做 multipart/byteranges。
if ($size > 0 && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m)) {
    $hasFrom = $m[1] !== '';
    $hasTo   = $m[2] !== '';
    if (!$hasFrom && !$hasTo) {
        $ok = false;
    } elseif (!$hasFrom) {
        // bytes=-N：最後 N 個位元組
        $len   = min((int)$m[2], $size);
        $start = $size - $len;
        $ok    = $len > 0;
    } else {
        $start = (int)$m[1];
        $end   = $hasTo ? min((int)$m[2], $size - 1) : $size - 1;
        $ok    = $start <= $end && $start < $size;
    }
    if (!$ok) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}

$length = $end - $start + 1;
header('Content-Length: ' . $length);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') exit;

$fp = fopen($path, 'rb');
if (!$fp) {
    http_response_code(500);
    exit;
}
fseek($fp, $start);
$remain = $length;
while ($remain > 0 && !feof($fp)) {
    $chunk = fread($fp, (int)min(262144, $remain));
    if ($chunk === false || $chunk === '') break;
    echo $chunk;
    $remain -= strlen($chunk);
    flush();
    // 使用者拖進度條會直接斷線，繼續讀完整支影片只是白燒 CPU 跟頻寬
    if (connection_aborted()) break;
}
fclose($fp);
