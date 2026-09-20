<?php
// 由 PHP 輸出圖層圖檔（框架不供應靜態檔，理由同 api/photo.php）。
// 用法：<base>/layer/<project>/<id>/<相對路徑>
//   圖磚   <base>/layer/100chairs/chungshing-art/tiles/16/54738/28275.png
//   單張   <base>/layer/100chairs/chungshing-art/overlay.svg
//
// 為什麼是一支端點而不是「圖磚一支、單張一支」：自繪插畫可能切成金字塔，也可能就是一張大
// 透明 PNG／SVG，兩者只差在資料夾裡的路徑長相。用同一支端點吃，layer.json 想換形式時
// 網址結構不用跟著改。
//
// <project> 只決定解析範圍（專案層 projects/<proj>/layers/ 優先於全站層 layers/），全站
// 層也走同一條網址——這樣前端拿到的網址形狀永遠一致，不必知道圖層是誰的。
require_once __DIR__ . '/layers.php';
require_once __DIR__ . '/routes.php';
$cfg = require __DIR__ . '/config.php';

// 副檔名白名單（與後台匯出／匯入共用同一份，見 layers.php）。這道關卡同時是「layer.json
// 拿不到」的保證：註冊表內容不該從公開端點外流。
$MIMES = souliong_layer_mimes();

$raw = (string)($_GET['f'] ?? '');
// <project>/<id>/<路徑>；路徑每一段都只允許保守字元（含 @，sprite@2x 要用），且整串不得出現 ".."
if (strpos($raw, '..') !== false
    || !preg_match('#^([a-z0-9_-]+)/([a-z0-9_-]+)/([A-Za-z0-9_.@-]+(?:/[A-Za-z0-9_.@-]+)*)$#', $raw, $m)) {
    http_response_code(400);
    exit;
}
[, $proj, $id, $rel] = $m;

$ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
if (!isset($MIMES[$ext])) {
    http_response_code(400);
    exit;
}
// .json 開放給自訂向量樣式（style.json）用，但註冊表本身不能從這支端點外流——
// 就算哪天真的有人把檔案取名叫 layer.json，這裡照樣擋下來。
if ($ext === 'json' && strtolower(basename($rel)) === 'layer.json') {
    http_response_code(400);
    exit;
}

$dir  = souliong_layer_dir($cfg, $id, $proj);
$real = ($dir === null) ? false : realpath($dir . '/' . $rel);
$root = ($dir === null) ? false : realpath($dir);
// realpath 收尾：就算前面的字元白名單哪天被繞過，實體路徑也必須落在該圖層資料夾之內
// （符號連結同樣會在這裡被攤平）。
$norm = fn($p) => str_replace('\\', '/', (string)$p);
$ok = $real !== false && $root !== false
    && strpos($norm($real), $norm($root) . '/') === 0
    && is_file($real);

if (!$ok) {
    // 稀疏疊圖的常態是「這一格根本沒畫」。圖磚形狀（…/<z>/<x>/<y>.<ext>）的請求回一張
    // 透明圖，Leaflet 就不會為每個空格印一行紅字；其餘（單張疊圖路徑打錯）照實回 404。
    if (preg_match('#(^|/)\d+/\d+/\d+\.[A-Za-z0-9]+$#', $rel)) {
        header('Content-Type: image/png');
        header('X-Souliong-Tile: miss');     // 除錯用：分得出「空白」與「真的有一張全透明的磚」
        header('Cache-Control: public, max-age=300');   // 之後可能補畫，所以不能 immutable
        echo souliong_blank_png();
        exit;
    }
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $MIMES[$ext]);
header('X-Content-Type-Options: nosniff');
if ($ext === 'svg') {
    // SVG 可以內嵌 <script>。放在 <img> 裡不會執行，但有人直接開這個網址就會——同源之下
    // 那等於「能放圖層檔的人＝能在本站執行腳本」。在回應層面直接關掉，不倚賴呼叫端怎麼用。
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
}
header('Cache-Control: public, max-age=31536000, immutable');
if ($ext === 'json') {
    // MapLibre 的 sprite 必須是絕對網址，相對值只有在這裡（知道自己的公開網址）才能補成對的
    $body = souliong_style_absolute_sprite((string)file_get_contents($real), $proj, $id, $rel);
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}
header('Content-Length: ' . filesize($real));
readfile($real);

/**
 * 向量樣式（有 version 與 layers 的 json）頂層 sprite 若是相對路徑，改寫成同圖層資料夾內的
 * 絕對網址；已是絕對網址、解析失敗、不是樣式、路徑不合法一律原樣回傳。
 */
function souliong_style_absolute_sprite(string $json, string $proj, string $id, string $rel): string
{
    $st = json_decode($json, true);
    if (!is_array($st) || !isset($st['version'], $st['layers']) || !is_string($st['sprite'] ?? null)) {
        return $json;
    }
    $sprite = $st['sprite'];
    if ($sprite === '' || preg_match('#^[a-z][a-z0-9+.-]*:|^//|^/#i', $sprite)
        || strpos($sprite, '..') !== false || !preg_match('#^[A-Za-z0-9_.@-]+(?:/[A-Za-z0-9_.@-]+)*$#', $sprite)) {
        return $json;
    }
    $dir = dirname($rel);
    $st['sprite'] = Route::abs(Route::layerFile($proj, $id, ($dir === '.' ? '' : $dir . '/') . $sprite));
    $out = json_encode($st, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $out === false ? $json : $out;
}

/**
 * 1×1 全透明 PNG（68 bytes）。刻意用 zlib 現組而不是塞一串 base64 魔術字串，也不另外放一個
 * 二進位檔：這樣讀程式的人看得出它就是「一個像素、RGBA 全零」，不必去解碼才知道是什麼。
 */
function souliong_blank_png(): string
{
    static $png = null;
    if ($png !== null) {
        return $png;
    }
    $chunk = fn(string $type, string $data): string =>
        pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    $png = "\x89PNG\r\n\x1a\n"
        // 寬 1、高 1、每通道 8 bit、色彩型別 6（RGBA）、無壓縮/濾波/交錯預設值
        . $chunk('IHDR', pack('N', 1) . pack('N', 1) . chr(8) . chr(6) . chr(0) . chr(0) . chr(0))
        // 掃描線＝濾波器位元組 0 ＋ RGBA(0,0,0,0)
        . $chunk('IDAT', gzcompress("\x00\x00\x00\x00\x00", 9))
        . $chunk('IEND', '');
    return $png;
}
