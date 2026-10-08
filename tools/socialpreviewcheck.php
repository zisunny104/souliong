<?php
/** Isolated public-preview API and data checks; no live project files are touched. */
require_once dirname(__DIR__) . '/api/socialcardlib.php';
$root = dirname(__DIR__);
$sandbox = sys_get_temp_dir() . '/souliong-socialcheck-' . bin2hex(random_bytes(4));
$server = null; $failures = 0; $checks = 0;
function sp_copy(string $from, string $to): void {
    mkdir($to, 0775, true);
    foreach (scandir($from) as $name) {
        if ($name === '.' || $name === '..') continue;
        is_dir($from . '/' . $name) ? sp_copy($from . '/' . $name, $to . '/' . $name) : copy($from . '/' . $name, $to . '/' . $name);
    }
}
function sp_remove(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $name) {
        if ($name === '.' || $name === '..') continue;
        is_dir($dir . '/' . $name) ? sp_remove($dir . '/' . $name) : unlink($dir . '/' . $name);
    }
    rmdir($dir);
}
function sp_assert(bool $ok, string $name): void {
    global $failures, $checks; $checks++;
    if (!$ok) { $failures++; fwrite(STDERR, "FAIL: $name\n"); }
}
function sp_json(string $path, array $value): void { file_put_contents($path, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
function sp_request(string $url, string $method = 'GET', array $headers = []): array {
    $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 20]]);
    $body = file_get_contents($url, false, $context);
    $lines = $http_response_header ?? [];
    preg_match('/\s(\d{3})\s/', $lines[0] ?? '', $match);
    $out = [];
    foreach ($lines as $line) if (str_contains($line, ':')) { [$key, $value] = explode(':', $line, 2); $out[strtolower($key)] = trim($value); }
    return [(int)($match[1] ?? 0), $body, $out];
}
register_shutdown_function(function () use (&$server, $sandbox, &$failures) {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if ($failures && is_file($sandbox . '/server.log')) fwrite(STDERR, substr(file_get_contents($sandbox . '/server.log'), -3000));
    sp_remove($sandbox);
});
foreach (['api', 'pages', 'lang', 'assets', 'layers', 'tools'] as $dir) sp_copy($root . '/' . $dir, $sandbox . '/' . $dir);
foreach (['index.php', 'config.php'] as $file) copy($root . '/' . $file, $sandbox . '/' . $file);
mkdir($sandbox . '/state', 0775, true); mkdir($sandbox . '/projects/test/photos', 0775, true);
$cfg = require $root . '/api/config.php';
$cfg = array_replace($cfg, ['projects_dir' => $sandbox . '/projects', 'state_dir' => $sandbox . '/state', 'layers_dir' => $sandbox . '/layers', 'social_preview_map' => false, 'primary_pin' => '', 'ip_salt' => 'social-preview-test']);
file_put_contents($sandbox . '/api/config.php', '<?php return ' . var_export($cfg, true) . ';');
$meta = ['title' => '預覽測試', 'desc' => '專案說明', 'center' => [24, 120], 'zoom' => 16, 'pinMark' => 'icon', 'numbering' => 'disable'];
sp_json($sandbox . '/projects/test/meta.json', $meta);
$spot = ['id' => 'aabbccdd11223344', 'kind' => 'spot', 'num' => 1, 'title' => '原點位', 'lat' => 24, 'lon' => 120, 'content' => [['kind' => 'text', 'comment' => '原介紹']]];
$spotEdit = ['id' => '11223344aabbccdd', 'kind' => 'spot', 'edit_of' => 'aabbccdd11223344', 'content' => [['kind' => 'text', 'comment' => '最新介紹']], 'created_at' => '2026-10-08T00:00:00Z'];
file_put_contents($sandbox . '/projects/test/spots.jsonl', json_encode($spot) . "\n" . json_encode($spotEdit) . "\n");
$photo = imagecreatetruecolor(600, 400); imagefill($photo, 0, 0, imagecolorallocate($photo, 175, 105, 40)); imagejpeg($photo, $sandbox . '/projects/test/photos/test.jpg'); imagedestroy($photo);
$entries = [];
foreach (['photo', 'video', 'audio', 'text'] as $kind) $entries[] = ['id' => $kind . '1', 'kind' => $kind, 'item_num' => 1, 'name' => '原作者', 'comment' => '**測試說明**', 'license' => 'cc-by-nc-nd', 'photo' => $kind === 'photo' ? 'test/test.jpg' : null, 'thumb' => $kind === 'video' ? 'test/test.jpg' : null, 'media' => in_array($kind, ['video', 'audio']) ? 'test/test.mp4' : null, 'created_at' => '2026-10-07T00:00:00Z'];
$entries[] = ['id' => 'photo2', 'kind' => 'photo', 'edit_of' => 'photo1', 'name' => '編輯者', 'comment' => '最新版說明', 'item_num' => 1, 'created_at' => '2026-10-08T00:00:00Z'];
file_put_contents($sandbox . '/projects/test/entries.jsonl', implode("\n", array_map('json_encode', $entries)) . "\n");
$data = souliong_social_data($cfg, 'test', $meta, 'photo1');
sp_assert($data['description'] === '最新版說明' && $data['author'] === '原作者', 'latest description retains original author');
sp_assert($data['spotName'] === '原點位' && $data['license'] === 'CC BY-NC-ND', 'linked point and license come from data');
sp_assert(souliong_social_data($cfg, 'test', $meta, '', 'aabbccdd11223344')['description'] === '最新介紹', 'latest point content');
sp_assert(souliong_social_image($cfg, 'test', 'other/test.jpg') === null && souliong_social_image($cfg, 'test', 'test/../../etc/passwd') === null, 'cross-project and traversal rejected');
sp_assert(souliong_social_runtime($cfg) === null, 'missing or disabled map renderer falls back');
sp_assert(preg_match('//u', souliong_og_truncate(str_repeat('測', 100), 20)) === 1, 'UTF-8 truncation preserves characters');
$socket = stream_socket_server('tcp://127.0.0.1:0'); $address = stream_socket_get_name($socket, false); fclose($socket);
$port = (int)substr(strrchr($address, ':'), 1); $base = 'http://127.0.0.1:' . $port;
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $sandbox, $sandbox . '/index.php'], [0 => ['pipe', 'r'], 1 => ['file', $sandbox . '/server.log', 'a'], 2 => ['file', $sandbox . '/server.log', 'a']], $pipes);
fclose($pipes[0]); usleep(250000);
$url = $base . '/?api=socialpreview&project=test';
foreach (['photo', 'video', 'audio', 'text'] as $kind) {
    [$code, $body, $headers] = sp_request($url . '&entry=' . $kind . '1');
    $info = @getimagesizefromstring($body);
    sp_assert($code === 200 && $info && $info[0] === 1200 && $info[1] === 630 && $headers['content-type'] === 'image/jpeg', $kind . ' produces JPEG card');
    sp_assert(!empty($headers['etag']) && $headers['cache-control'] === 'public, max-age=60', $kind . ' fallback cache headers');
}
[$code, $body, $headers] = sp_request($url . '&spot=aabbccdd11223344');
sp_assert($code === 200 && @getimagesizefromstring($body)[0] === 1200, 'point JPEG');
$etag = $headers['etag'];
[$code, $body] = sp_request($url . '&spot=aabbccdd11223344', 'GET', ['If-None-Match: ' . $etag]); sp_assert($code === 304 && $body === '', 'conditional GET');
[$code, $body, $headers] = sp_request($url . '&spot=aabbccdd11223344', 'HEAD'); sp_assert($code === 200 && $body === '' && (int)$headers['content-length'] > 0, 'HEAD');
foreach (['&entry=missing' => 404, '&spot=missing' => 404, '&project[]=test' => 400, '&entry[]=x' => 400, '&spot=../../x' => 400] as $query => $expected) sp_assert(sp_request($url . $query)[0] === $expected, 'invalid request ' . $query);
sp_assert(sp_request($url, 'POST')[0] === 405, 'GET/HEAD only');
[$code, $html] = sp_request($base . '/test?entry=photo1');
sp_assert($code === 200 && str_contains($html, 'api=socialpreview') && str_contains($html, 'og:image:width') && str_contains($html, 'twitter:image'), 'OG and Twitter metadata');
sp_assert(str_contains($html, '最新版說明'), 'metadata uses current description');
if (in_array(getenv('SOCIAL_PREVIEW_BROWSER_CHECK'), ['1', 'mock'], true)) {
    mkdir($sandbox . '/projects/test/layers/test-map', 0775, true);
    sp_json($sandbox . '/projects/test/layers/test-map/layer.json', ['type' => 'vector', 'pane' => 'base', 'url' => 'style.json', 'attribution' => [['text' => 'Fixture map']]]);
    file_put_contents($sandbox . '/projects/test/layers/test-map/style.json', json_encode(['version' => 8, 'sources' => (object)[], 'layers' => [['id' => 'background', 'type' => 'background', 'paint' => ['background-color' => '#dce9cf']]]]));
    $meta['layers'] = ['test-map']; $meta['categoryIcons'] = ['new' => 'house'];
    sp_json($sandbox . '/projects/test/meta.json', $meta);
    if (getenv('SOCIAL_PREVIEW_BROWSER_CHECK') === 'mock') {
        file_put_contents($sandbox . '/assets/js/engine/maplibre-engine.js', <<<'JS'
window.maplibregl = {};
window.MapLibreEngine = class {
 constructor(options) {
  this.supportsSnapshot=false; this.element=document.getElementById(options.container); this.markers={}; this.center=[120,24]; this.offset=[0,0]; this.loaded=false;
  this.ready=fetch(options.manifests.find(m=>m.type==='vector').url).then(r=>r.json()).then(style=>{this.element.style.background=style.layers[0].paint['background-color'];this.loaded=true;});
  this.map={jumpTo:o=>{this.center=Array.isArray(o.center)?o.center:[o.center.lng,o.center.lat];this.offset=[0,0];},project:p=>({x:600+((p[0]??p.lng)-this.center[0])*1000-this.offset[0],y:315+((p[1]??p.lat)-this.center[1])*1000-this.offset[1]}),panBy:p=>{this.offset=p;},getCenter:()=>this.center,isStyleLoaded:()=>this.loaded,areTilesLoaded:()=>this.loaded,on:()=>{},once:(event,cb)=>this.ready.then(cb)};
 }
 getRawMap(){return this.map;} getZoom(){return 16;} mountControls(){} onZoomThresholdCross(){} fitBounds(){} onBackgroundClick(){} panTo(){} applyTheme(){} setView(){}
 clearMarkerLayer(key){(this.markers[key]||[]).forEach(el=>el.remove());this.markers[key]=[];}
 setMarkerLayer(key,specs){this.clearMarkerLayer(key);this.markers[key]=(specs||[]).map(spec=>{const el=document.createElement('div'),p=this.map.project([spec.lon,spec.lat]);el.innerHTML=spec.html;Object.assign(el.style,{position:'absolute',left:(p.x-spec.anchor[0])+'px',top:(p.y-spec.anchor[1])+'px',width:spec.size[0]+'px',height:spec.size[1]+'px'});this.element.append(el);return el;});}
};
JS);
    }
    $browserCfg = $cfg;
    $browserCfg['social_preview_map'] = true;
    $browserCfg['debug'] = true;
    $browserCfg['social_preview_base_url'] = $base;
    $browserCfg['social_preview_playwright'] = $root . '/state/social-preview-runtime/node_modules/playwright-core';
    $browserCfg['social_preview_chromium'] = '/usr/bin/chromium';
    $png = souliong_social_map($browserCfg, 'test', 'aabbccdd11223344', 16);
    sp_assert($png !== null, (getenv('SOCIAL_PREVIEW_BROWSER_CHECK') === 'mock' ? 'isolated map engine' : 'actual MapLibre') . ' layer and icon render');
    if ($png) {
        $map = imagecreatefromstring($png);
        sp_assert((imagecolorat($map, 50, 100) & 0xffffff) === 0xdce9cf, 'selected project layer retained');
        sp_assert((imagecolorat($map, 300, 315) & 0xffffff) !== 0xdce9cf, 'existing point icon appears at projected position');
        imagedestroy($map);
        if (getenv('SOCIAL_PREVIEW_SAVE_MAP')) file_put_contents(getenv('SOCIAL_PREVIEW_SAVE_MAP'), $png);
    }
}
$entries = array_values(array_filter($entries, fn($entry) => $entry['id'] !== 'photo1'));
file_put_contents($sandbox . '/projects/test/entries.jsonl', implode("\n", array_map('json_encode', $entries)) . "\n");
sp_assert(sp_request($url . '&entry=photo1')[0] === 404, 'deleted original cannot serve cached image');
echo "$checks checks, $failures failures\n";
exit($failures ? 1 : 0);
