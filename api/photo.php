<?php
// 由 PHP 輸出照片（框架不供應靜態檔）。用法：?api=photo&f=<project>/<file>
// &th=1 輸出縮圖：用既有 <檔名>_t.* 檔，沒有就以 GD 產生一次存檔；產不出來（無 GD/WebP）退回原圖。
require __DIR__ . '/store.php';
require __DIR__ . '/imaging.php';
$cfg = require __DIR__ . '/config.php';

$f = $_GET['f'] ?? '';
// 僅允許 <project>/<檔名>，禁止路徑穿越
if (strpos($f, '..') !== false || !preg_match('#^[a-z0-9_-]+/[A-Za-z0-9_.-]+$#', $f)) {
    http_response_code(400);
    exit;
}
$path = photo_abs_path($cfg, $f);
if ($path === null || !is_file($path)) {
    http_response_code(404);
    exit;
}
if (!empty($_GET['th'])) {
    $t = photo_thumb_of($path);
    if ($t !== null) $path = $t;
}
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mimes = ['webp' => 'image/webp', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
$mime = $mimes[$ext] ?? 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=31536000, immutable');
readfile($path);

/** 既有或新產生的縮圖檔路徑；產不出來（無 GD、格式不支援、寫檔失敗…）回 null，由呼叫端輸出原圖 */
function photo_thumb_of(string $path): ?string {
    $base = preg_replace('/\.[A-Za-z0-9]+$/', '', $path);
    if (substr($base, -2) === '_t') return null;   // 要的本來就是縮圖檔，別再產「縮圖的縮圖」
    foreach (['webp', 'jpg', 'png'] as $te) {
        if (is_file($base . '_t.' . $te)) return $base . '_t.' . $te;
    }
    $d = souliong_image_decode_file($path);
    if ($d === null) return null;
    [$src, $w, $h] = $d;
    $out = souliong_image_resize_encode($src, $w, $h, 640, $base . '_t');   // 跟前端上傳縮圖同規格（最長邊 640）
    imagedestroy($src);
    return $out;
}
