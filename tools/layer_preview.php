<?php
// 維護工具（CLI only）：替每個圖層烘一張預覽圖 layers/<id>/preview.jpg，後台挑選圖層時顯示。
// 以亞洲大學周邊當示範地點；自繪疊圖改用它自己的範圍。透明疊圖會墊一張底圖才看得出效果。
// 需要本機有 Chrome／Edge（可用環境變數 CHROME 指定）與 GD。
//   php tools/layer_preview.php            全部圖層
//   php tools/layer_preview.php paper-ink  指定圖層
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

const PV_CENTER = [120.6877, 24.0467]; // lng, lat
const PV_ZOOM = 16;
const PV_W = 480;
const PV_H = 300;
const PV_HOLD = 12; // 秒

$root = dirname(__DIR__);
$layersDir = $root . '/layers';

function pv_browser(): ?string
{
    $cands = [
        getenv('CHROME') ?: '',
        'C:/Program Files/Google/Chrome/Application/chrome.exe',
        'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
        'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
        '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser',
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    ];
    foreach ($cands as $c) {
        if ($c !== '' && is_file($c)) return $c;
    }
    return null;
}

/** 圖層轉成 MapLibre 的樣式；$base 為 true 時是主角，否則只是疊上去的內容。 */
function pv_layer_parts(string $dir, array $def): array
{
    $type = (string)($def['type'] ?? '');
    $id = basename($dir);
    if ($type === 'vector') {
        $url = (string)($def['url'] ?? '');
        if (str_contains($url, '://')) return ['style' => $url];
        $st = json_decode((string)file_get_contents($dir . '/' . $url), true);
        if (!is_array($st)) return [];
        // 本機圖示檔瀏覽器 fetch 不到，預覽也用不到，直接拿掉
        unset($st['sprite']);
        return ['style' => $st];
    }
    if ($type === 'raster') {
        $u = (string)($def['url'] ?? '');
        $u = str_replace(['{r}', '{s}'], ['', substr((string)($def['subdomains'] ?? 'a'), 0, 1)], $u);
        return ['source' => ['type' => 'raster', 'tiles' => [$u], 'tileSize' => 256, 'maxzoom' => (int)($def['maxZoom'] ?? 19)], 'id' => $id];
    }
    if ($type === 'image') {
        $b = $def['bounds'] ?? null;
        $f = $dir . '/' . (string)($def['url'] ?? '');
        if (!is_array($b) || !is_file($f)) return [];
        $mime = str_ends_with(strtolower($f), '.svg') ? 'image/svg+xml' : (str_ends_with(strtolower($f), '.png') ? 'image/png' : 'image/jpeg');
        return [
            'source' => ['type' => 'image', 'url' => 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($f)),
                'coordinates' => [[$b[0][1], $b[1][0]], [$b[1][1], $b[1][0]], [$b[1][1], $b[0][0]], [$b[0][1], $b[0][0]]]],
            'id' => $id, 'opacity' => (float)($def['opacity'] ?? 1), 'bounds' => $b,
        ];
    }
    return [];
}

function pv_html(array|string $style, array $center, float $zoom, ?array $fit): string
{
    $cfg = json_encode(['style' => $style, 'center' => $center, 'zoom' => $zoom, 'fit' => $fit], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return '<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.css">'
        . '<style>html,body,#m{margin:0;width:' . PV_W . 'px;height:' . PV_H . 'px;overflow:hidden}.maplibregl-ctrl{display:none}</style>'
        . '<div id="m"></div><script src="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.js"></script><script>'
        . 'const C=' . $cfg . ';'
        . 'const m=new maplibregl.Map({container:"m",style:C.style,center:C.center,zoom:C.zoom,interactive:false,attributionControl:false,fadeDuration:0,preserveDrawingBuffer:true});'
        . 'if(C.fit){m.fitBounds([[C.fit[0][1],C.fit[0][0]],[C.fit[1][1],C.fit[1][0]]],{padding:10,animate:false})}'
        . 'let t;function done(){clearTimeout(t);t=setTimeout(()=>{document.title="ready"},1500)}'
        . 'm.on("idle",done);m.on("error",e=>{' . (getenv('PV_DEBUG') ? 'const d=document.createElement("pre");d.style.cssText="position:absolute;top:0;z-index:9;margin:0;background:#fff;color:red;font:10px monospace;white-space:pre-wrap";d.textContent=String(e&&e.error&&e.error.message);document.body.appendChild(d)' : '') . '});'
        . '</script><script src="/hold"></script>';   // 拖住 load 事件，等圖磚畫完才截圖
}

$browser = pv_browser();
if ($browser === null) {
    fwrite(STDERR, "找不到 Chrome／Edge，請用環境變數 CHROME 指定路徑\n");
    exit(1);
}
if (!function_exists('imagecreatefrompng')) {
    fwrite(STDERR, "需要 GD 擴充\n");
    exit(1);
}

$only = array_slice($argv, 1);
$ids = [];
foreach (scandir($layersDir) ?: [] as $d) {
    if ($d[0] !== '.' && is_file($layersDir . '/' . $d . '/layer.json')) $ids[] = $d;
}
if ($only) $ids = array_values(array_intersect($ids, $only));

$basePhoto = ['type' => 'raster', 'url' => 'https://wmts.nlsc.gov.tw/wmts/PHOTO2/default/GoogleMapsCompatible/{z}/{y}/{x}', 'maxZoom' => 20];
$tmp = sys_get_temp_dir() . '/layer_preview_' . getmypid();
@mkdir($tmp, 0775, true);
// 本機小伺服器：/hold 延遲回應，讓瀏覽器的 load 事件等到地圖畫完；截圖是在 load 時拍的
file_put_contents($tmp . '/router.php', str_replace('__HOLD__', (string)PV_HOLD, <<<'RT'
<?php
$u = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($u === '/hold') { sleep(__HOLD__); header('Content-Type: text/javascript'); exit; }
if ($u === '/p.html') { header('Content-Type: text/html; charset=utf-8'); readfile(__DIR__ . '/p.html'); exit; }
http_response_code(404);
RT));
$port = random_int(20000, 40000);
$srv = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=', '-S', '127.0.0.1:' . $port, $tmp . '/router.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $srvPipes, $tmp);
register_shutdown_function(function () use ($srv) { if (is_resource($srv)) proc_terminate($srv); });
usleep(800000);
$fail = 0;

foreach ($ids as $id) {
    $dir = $layersDir . '/' . $id;
    $def = json_decode((string)file_get_contents($dir . '/layer.json'), true);
    if (!empty($def['deprecated'])) {
        echo "略過 {$id}：已封存
";
        continue;
    }
    $parts = is_array($def) ? pv_layer_parts($dir, $def) : [];
    if (!$parts) {
        fwrite(STDERR, "略過 {$id}：格式不支援\n");
        $fail++;
        continue;
    }
    $center = PV_CENTER;
    $fit = null;
    if (isset($parts['style'])) {
        $style = $parts['style'];
    } else {
        $isBase = ($def['pane'] ?? '') === 'base';
        $layers = [];
        $sources = [];
        if (!$isBase) {
            $bp = pv_layer_parts($dir, $basePhoto);
            $sources['base'] = $bp['source'];
            $layers[] = ['id' => 'base', 'type' => 'raster', 'source' => 'base'];
        }
        $sources[$id] = $parts['source'];
        $lp = ['id' => $id . '-l', 'type' => 'raster', 'source' => $id];
        if (isset($parts['opacity'])) $lp['paint'] = ['raster-opacity' => $parts['opacity']];
        $layers[] = $lp;
        $style = ['version' => 8, 'sources' => $sources, 'layers' => $layers];
        if (isset($parts['bounds'])) {
            $fit = $parts['bounds'];
            // 疊圖範圍外沒有底圖會一片空白，所以墊一張紙墨底圖
            $pi = pv_layer_parts($layersDir . '/paper-ink', json_decode((string)file_get_contents($layersDir . '/paper-ink/layer.json'), true));
            $style = $pi['style'];
            $style['sources'][$id] = $parts['source'];
            $style['layers'][] = $lp;
        }
    }
    $html = $tmp . '/p.html';
    $png = $tmp . '/p.png';
    @unlink($png);
    file_put_contents($html, pv_html($style, $center, PV_ZOOM, $fit));
    $args = [$browser, '--headless=old', '--use-angle=swiftshader', '--enable-unsafe-swiftshader',
        '--hide-scrollbars', '--window-size=' . PV_W . ',' . PV_H,
        '--user-data-dir=' . $tmp . '/profile_' . $id, '--screenshot=' . $png,
        'http://127.0.0.1:' . $port . '/p.html'];
    $out = '';
    $proc = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (is_resource($proc)) {
        $out = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }
    // 有些平台上瀏覽器啟動後主程序立刻返回、截圖稍後才寫出，所以輪詢到檔案出現且大小穩定
    for ($w = 0, $last = -1; $w < 90; $w++) {
        clearstatcache(true, $png);
        $sz = is_file($png) ? filesize($png) : 0;
        if ($sz > 0 && $sz === $last) break;
        $last = $sz;
        sleep(1);
    }
    if (!is_file($png)) {
        fwrite(STDERR, "失敗 {$id}：瀏覽器沒有產出截圖\n");
        $fail++;
        continue;
    }
    $im = imagecreatefrompng($png);
    imagejpeg($im, $dir . '/preview.jpg', 82);
    imagedestroy($im);
    printf("%s → preview.jpg（%d KB）\n", $id, intdiv(filesize($dir . '/preview.jpg'), 1024));
}

foreach (scandir($tmp) ?: [] as $f) is_file($tmp . '/' . $f) && @unlink($tmp . '/' . $f);
exit($fail ? 1 : 0);
