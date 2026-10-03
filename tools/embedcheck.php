<?php
/**
 * 對外資料 API／嵌入／導航小頁（api=project、api=spots、api=navsheet、嵌入允許清單）的回歸測試（CLI）。
 *
 * 用法：php tools/embedcheck.php      全部通過結束碼 0，有任何一項失敗為 1。
 *
 * 在臨時沙盒跑（sys_get_temp_dir()/embedcheck_*，結束時清掉）：複製 api／pages／lang 與根資料夾檔案，
 * 起一個內建伺服器（127.0.0.1:8124），不碰真正的 state/ 與 projects/。沙盒的 index.php 先送一個
 * X-Frame-Options: DENY，模擬伺服器層或上游已加的標頭，用來驗證「清單非空才移除、否則保留」。
 * 涵蓋：回應格式與欄位白名單（不含任何雜湊／IP）、ETag／304、CORS 只放行清單內來源、
 * frame-ancestors 與 XFO 互斥、spotId 穩定且不隨 num 重用、navsheet 404／405、
 * 允許清單格式驗證（拒絕萬用字元與路徑）、後台儲存驗證、embed-bridge.js 的 unknown_command 處理（靜態檢查）。
 */
error_reporting(E_ALL);
$root = dirname(__DIR__);
$port = 8124;
$base = "http://127.0.0.1:$port";

function ec_rm(string $d): void {
    if (!is_dir($d)) return;
    foreach (scandir($d) as $n) {
        if ($n === '.' || $n === '..') continue;
        is_dir("$d/$n") ? ec_rm("$d/$n") : @unlink("$d/$n");
    }
    @rmdir($d);
}
function ec_copy(string $from, string $to): void {
    if (!is_dir($to)) mkdir($to, 0777, true);
    foreach (scandir($from) as $n) {
        if ($n === '.' || $n === '..') continue;
        is_dir("$from/$n") ? ec_copy("$from/$n", "$to/$n") : copy("$from/$n", "$to/$n");
    }
}
function ec_write(string $path, $data): void {
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
    file_put_contents($path, is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
function ec_jsonl(string $path, array $rows): void {
    ec_write($path, implode("\n", array_map(fn($r) => json_encode($r, JSON_UNESCAPED_UNICODE), $rows)) . "\n");
}

$sb = str_replace('\\', '/', sys_get_temp_dir()) . '/embedcheck_' . bin2hex(random_bytes(4));
$server = null;
register_shutdown_function(function () use (&$server, $sb) {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    ec_rm($sb);
});

$real = require $root . '/api/config.php';
$cfg = array_merge($real, [
    'ip_salt' => 'embedcheck-' . bin2hex(random_bytes(8)), 'primary_pin' => '', 'primary_pin_label' => '',
    'state_dir' => "$sb/state", 'projects_dir' => "$sb/projects", 'rate_max' => 100000, 'rate_limits' => [],
    'embed_allowed_origins' => [],
]);
unset($cfg['admin_pin'], $cfg['admin_pin_label']);
mkdir("$sb/state", 0777, true);
ec_copy($root . '/api', "$sb/api");
ec_copy($root . '/pages', "$sb/pages");
ec_copy($root . '/lang', "$sb/lang");
foreach (['index.php', 'config.php'] as $f) { if (is_file("$root/$f")) copy("$root/$f", "$sb/$f"); }
ec_write("$sb/api/config.php", "<?php\nreturn json_decode(file_get_contents(__DIR__ . '/../cfg.json'), true);\n");
ec_write("$sb/cfg.json", $cfg);
// 沙盒的 index.php 開頭先送 X-Frame-Options: DENY，模擬伺服器層或上游已加的標頭
$idx = (string)file_get_contents("$sb/index.php");
file_put_contents("$sb/index.php", preg_replace('/^<\?php/', "<?php\nheader('X-Frame-Options: DENY');", $idx, 1));

require_once $root . '/api/security.php';
require_once $root . '/api/embedorigins.php';
$cookie = PRIMARY_COOKIE . '=' . primary_derived($cfg);
$csrf = primary_derived($cfg);

// 專案 E 有允許清單、專案 N 沒有。起點紀錄刻意帶上各種不可外流的欄位，驗證白名單。
const EC_ORIGIN = 'https://embed.example.org';
$E = 'ecembed';
$N = 'ecplain';
$now = gmdate('c');
$secrets = ['SECRET_CONTRIB_HASH_aaa', 'SECRET_OWNER_HASH_bbb', 'SECRET_SRC_HASH_ccc', '203.0.113.77'];
$ID1 = 'a1a1a1a1a1a1a1a1';
$ID2 = 'b2b2b2b2b2b2b2b2';
$ID3 = 'c3c3c3c3c3c3c3c3';
$ID4 = 'd4d4d4d4d4d4d4d4';
$mk = fn(string $id, int $num, string $title, float $lat, float $lon, array $extra = []) => $extra + [
    'id' => $id, 'project' => $E, 'kind' => 'spot', 'num' => $num, 'item_num' => $num, 'title' => $title,
    'cat' => 'green', 'catLabel' => '綠', 'color' => '#2e9e5b', 'area' => '甲區', 'lat' => $lat, 'lon' => $lon,
    'name' => '匿名', 'owner_hash' => $secrets[1], 'contrib_hash' => $secrets[0], 'src_hash' => $secrets[2],
    'ip' => $secrets[3], 'created_at' => $now, 'edit_of' => '',
];
ec_write("$sb/projects/$E/meta.json", [
    'id' => $E, 'title' => '嵌入測試', 'subtitle' => '副標', 'center' => [24.05, 120.69], 'zoom' => 15,
    'embedOrigins' => [EC_ORIGIN, 'http://localhost:5173'],
]);
// 舊資料的 kind 值 point／newpoint 也要被正規化為 spot；第三筆是 newpoint 並帶一筆編輯版本
ec_jsonl("$sb/projects/$E/spots.jsonl", [
    $mk($ID1, 1, '第一點', 24.051, 120.691),
    $mk($ID2, 2, '第二點', 24.052, 120.692, ['kind' => 'point']),
    $mk($ID3, 3, 'Name With Space & 符號', 24.053, 120.693, ['kind' => 'newpoint']),
    ['id' => 'e5e5e5e5e5e5e5e5', 'project' => $E, 'kind' => 'spot', 'item_num' => 2, 'edit_of' => $ID2, 'lat' => 24.0525, 'lon' => 120.6925,
     'contrib_hash' => $secrets[0], 'created_at' => gmdate('c', time() + 5)],
]);
ec_write("$sb/projects/$N/meta.json", ['id' => $N, 'title' => '無清單', 'center' => [24.0, 120.0], 'zoom' => 14]);
ec_jsonl("$sb/projects/$N/spots.jsonl", [
    ['id' => 'f6f6f6f6f6f6f6f6', 'project' => $N, 'kind' => 'spot', 'num' => 1, 'item_num' => 1, 'title' => 'N1', 'lat' => 24.0, 'lon' => 120.0, 'created_at' => $now, 'edit_of' => ''],
]);

// ── 內建伺服器 ──────────────────────────────────────────────────

$log = "$sb/server.log";
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $sb, "$sb/index.php"], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $sb);
$up = false;
for ($i = 0; $i < 50 && !$up; $i++) {
    usleep(100000);
    $s = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);
    if ($s) { fclose($s); $up = true; }
}
if (!$up) { fwrite(STDERR, "內建伺服器起不來（port $port 被佔用？）\n"); exit(1); }

/** 回傳 [狀態碼, 標頭（小寫名稱 => 值陣列）, 原文]；不跟隨轉址。 */
function ec_req(string $url, string $method = 'GET', array $headers = [], ?string $body = null): array {
    $h = '';
    foreach ($headers as $k => $v) $h .= "$k: $v\r\n";
    $http = ['method' => $method, 'header' => $h, 'ignore_errors' => true, 'timeout' => 20, 'follow_location' => 0];
    if ($body !== null) $http['content'] = $body;
    $raw = @file_get_contents($url, false, stream_context_create(['http' => $http]));
    $code = 0;
    $hdr = [];
    foreach ($http_response_header ?? [] as $l) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $l, $m)) { $code = (int)$m[1]; $hdr = []; continue; }
        if (strpos($l, ':') !== false) {
            [$k, $v] = explode(':', $l, 2);
            $hdr[strtolower(trim($k))][] = trim($v);
        }
    }
    return [$code, $hdr, is_string($raw) ? $raw : ''];
}
$hv = fn(array $h, string $k): string => implode(', ', $h[strtolower($k)] ?? []);

$n = 0; $fails = [];
function ck(bool $ok, string $what, $detail = null): void {
    global $n, $fails;
    $n++;
    if (!$ok) $fails[] = $what . ($detail !== null ? ' | ' . (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE)) : '');
}
$keysOf = function ($a): array { $k = is_array($a) ? array_keys($a) : []; sort($k); return $k; };

// ── 1. 允許清單解析與格式驗證（純函式）──────────────────────────

ck(embed_origin_normalize('https://Embed.Example.ORG') === 'https://embed.example.org', '來源轉小寫');
ck(embed_origin_normalize('https://embed.example.org:443') === 'https://embed.example.org', '省略預設埠 443');
ck(embed_origin_normalize('https://embed.example.org:8443/') === 'https://embed.example.org:8443', '保留非預設埠並容許結尾斜線');
ck(embed_origin_normalize('http://localhost:3000') === 'http://localhost:3000', 'localhost 可用 http');
ck(embed_origin_normalize('http://127.0.0.1:5173') === 'http://127.0.0.1:5173', '127.0.0.1 可用 http');
$badOrigins = [
    '*', 'https://*', 'https://*.example.org', '*.example.org', 'https://embed.example.org/path', 'https://embed.example.org/a/b',
    'https://embed.example.org?x=1', 'https://embed.example.org#frag', 'http://embed.example.org', 'https://user@embed.example.org',
    'https://embed.example.org:99999', 'https://embed.example.org:0', 'javascript:alert(1)', 'null', 'https://', '//embed.example.org',
    'embed.example.org', 'ftp://embed.example.org', 'https://embed..example.org', "https://embed.example.org\r\nX-Evil: 1", 'https://例子.tw',
];
foreach ($badOrigins as $b) ck(embed_origin_normalize($b) === null, '拒絕不合法來源：' . json_encode($b, JSON_UNESCAPED_UNICODE));
$p = embed_origins_parse("https://a.example.org, https://a.example.org;https://*.bad.org\nhttps://b.example.org/x");
ck($p['valid'] === ['https://a.example.org'] && $p['invalid'] === ['https://*.bad.org', 'https://b.example.org/x'], '解析：去重、分隔符、回報不合法項目', $p);
$all = embed_origins_allowed(['embed_allowed_origins' => ['https://site.example.org', 'https://*.bad.org']], ['embedOrigins' => ['https://proj.example.org', 'https://site.example.org']]);
ck($all === ['https://site.example.org', 'https://proj.example.org'], '全站＋專案聯集、不合法項目靜默忽略（fail-closed）', $all);
ck(embed_origins_allowed([], null) === [], '預設空清單');
$tmpState = sys_get_temp_dir() . '/ec_state_' . bin2hex(random_bytes(4));
mkdir($tmpState);
file_put_contents($tmpState . '/embed_origins.json', json_encode(['https://file.example.org', 'https://*.bad.org', 5]));
$fromFile = embed_origins_site(['state_dir' => $tmpState, 'embed_allowed_origins' => ['https://site.example.org']]);
ck($fromFile === ['https://site.example.org', 'https://file.example.org'], 'state/embed_origins.json 與 config 取聯集、壞項目忽略', $fromFile);
file_put_contents($tmpState . '/embed_origins.json', '{壞掉的 json');
ck(embed_origins_site(['state_dir' => $tmpState]) === [], '清單檔損毀時 fail-closed（視為空）');
($tmpState . '/embed_origins.json');
($tmpState);
ck(embed_origin_match('https://evil.example.org', ['https://a.example.org']) === null && embed_origin_match('https://a.example.org', []) === null, '空清單或未命中不放行');

// ── 2. api=project ──────────────────────────────────────────────

$u = fn(string $api, string $proj, array $q = []) => "$base/?" . http_build_query(['api' => $api, 'project' => $proj] + $q);
[$c, $h, $b] = ec_req($u('project', $E));
$pj = json_decode($b, true);
ck($c === 200 && is_array($pj), 'api=project 200 且為 JSON', [$c, substr($b, 0, 200)]);
ck(stripos($hv($h, 'content-type'), 'application/json') === 0, 'project Content-Type 為 JSON', $hv($h, 'content-type'));
ck($keysOf($pj) === ['cats', 'id', 'layers', 'subtitle', 'title', 'updatedAt', 'v', 'view'], 'project 頂層欄位白名單', $keysOf($pj));
ck(($pj['v'] ?? null) === 1 && ($pj['id'] ?? '') === $E && ($pj['title'] ?? '') === '嵌入測試' && ($pj['subtitle'] ?? '') === '副標', 'project v／id／title／subtitle');
ck(($pj['view']['center'] ?? null) === [24.05, 120.69] && ($pj['view']['zoom'] ?? null) === 15 && isset($pj['view']['minZoom'], $pj['view']['maxZoom']), 'project view', $pj['view'] ?? null);
ck($keysOf($pj['view'] ?? []) === ['center', 'maxZoom', 'minZoom', 'zoom'], 'project view 欄位白名單', $keysOf($pj['view'] ?? []));
ck(is_array($pj['layers'] ?? null) && $pj['layers'] && isset($pj['layers'][0]['id'], $pj['layers'][0]['type'], $pj['layers'][0]['attribution']) && in_array($pj['layers'][0]['type'], ['vector-style', 'raster'], true), 'project layers 格式', $pj['layers'][0] ?? null);
foreach ($pj['layers'] ?? [] as $l) {
    $ref = $l['styleUrl'] ?? ($l['tileUrl'] ?? '');
    ck($l['type'] === 'raster' || preg_match('#^https?://#', (string)$ref) === 1, '圖層 styleUrl 為絕對網址', $l);
}
ck(is_array($pj['cats'] ?? null) && ($pj['cats'][0]['key'] ?? '') === 'green' && ($pj['cats'][0]['label'] ?? '') === '綠' && ($pj['cats'][0]['color'] ?? '') === '#2e9e5b', 'project cats', $pj['cats'] ?? null);
ck(preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string)($pj['updatedAt'] ?? '')) === 1, 'updatedAt 為 UTC ISO 8601');
foreach ($secrets as $sec) ck(strpos($b, $sec) === false, 'project 不含機密值：' . $sec);
ck(!preg_match('/hash|csrf|perm|manager|pin/i', implode(' ', array_keys($pj))), 'project 欄位名不含 hash／csrf／perms');
ck($hv($h, 'set-cookie') === '', 'project 不送 Set-Cookie', $hv($h, 'set-cookie'));
ck(strpos($hv($h, 'cache-control'), 'max-age=60') !== false && strpos($hv($h, 'cache-control'), 'public') !== false, 'project Cache-Control public, max-age=60', $hv($h, 'cache-control'));
$etagP = $hv($h, 'etag');
ck(preg_match('/^"[0-9a-f]{16}"$/', $etagP) === 1, 'project ETag 格式', $etagP);

// ── 3. api=spots：格式、白名單、有效狀態、正規化 ─────────────────

[$c, $h, $b] = ec_req($u('spots', $E));
$sp = json_decode($b, true);
ck($c === 200 && is_array($sp), 'api=spots 200 且為 JSON', [$c, substr($b, 0, 200)]);
ck($keysOf($sp) === ['etag', 'project', 'spots', 'v'] && ($sp['v'] ?? null) === 1 && ($sp['project'] ?? '') === $E, 'spots 頂層欄位白名單', $keysOf($sp));
$list = $sp['spots'] ?? [];
ck(count($list) === 3, '三個地點（point／newpoint 舊 kind 已正規化）', count($list));
foreach ($list as $s) {
    ck($keysOf($s) === ['area', 'cat', 'lat', 'lon', 'nav', 'num', 'spotId', 'title'], 'spot 欄位白名單', $keysOf($s));
    ck(preg_match('/^[0-9a-f]{16}$/', $s['spotId']) === 1 && is_int($s['num']) && is_float($s['lat']) && is_float($s['lon']), 'spot 欄位型別', $s);
    ck($keysOf($s['nav'] ?? []) === ['apple', 'geo', 'google', 'osm'], 'nav 四種連結', $keysOf($s['nav'] ?? []));
}
foreach ($secrets as $sec) ck(strpos($b, $sec) === false, 'spots 不含機密值：' . $sec);
ck(!preg_match('/contrib_hash|owner_hash|src_hash|"ip"|csrf|perms|edit_of|"kind"/', $b), 'spots 內容不含儲存層欄位名');
ck(array_column($list, 'spotId') === [$ID1, $ID2, $ID3] && array_column($list, 'num') === [1, 2, 3], 'spotId 取自起點 id、依 num 排序', array_column($list, 'spotId'));
ck(($list[1]['lat'] ?? null) === 24.0525 && ($list[1]['lon'] ?? null) === 120.6925, '回傳 edit_of 疊加後的有效座標', $list[1] ?? null);
ck(strpos($list[2]['nav']['apple'] ?? '', 'q=Name%20With%20Space%20%26%20') !== false && strpos($list[2]['nav']['apple'] ?? '', ' ') === false, 'nav name 經 URL 編碼', $list[2]['nav']['apple'] ?? null);
ck(strpos($list[0]['nav']['geo'] ?? '', 'geo:24.051,120.691') === 0, 'nav geo 連結', $list[0]['nav']['geo'] ?? null);
ck($hv($h, 'set-cookie') === '', 'spots 不送 Set-Cookie');
$etagS = $hv($h, 'etag');
ck($etagS === '"' . ($sp['etag'] ?? '') . '"', 'ETag 標頭與內文 etag 一致', [$etagS, $sp['etag'] ?? null]);

// ETag／304
[$c, $h, $b] = ec_req($u('spots', $E), 'GET', ['If-None-Match' => $etagS]);
ck($c === 304 && $b === '', 'If-None-Match 命中回 304 無內文', [$c, strlen($b)]);
[$c] = ec_req($u('spots', $E), 'GET', ['If-None-Match' => 'W/' . $etagS]);
ck($c === 304, '弱驗證 W/ 前綴也回 304', $c);
[$c] = ec_req($u('spots', $E), 'GET', ['If-None-Match' => '"deadbeefdeadbeef"']);
ck($c === 200, 'ETag 不符回 200', $c);
[$c, $h, $b] = ec_req($u('spots', $E), 'HEAD');
ck($c === 200 && $b === '', 'HEAD 回 200 無內文', [$c, strlen($b)]);

// 錯誤
[$c, $h, $b] = ec_req($u('spots', 'nope_none'));
ck($c === 404 && (json_decode($b, true)['error'] ?? '') !== '' && $hv($h, 'cache-control') === 'no-store', '不存在的專案回 404 JSON', [$c, $b]);
[$c] = ec_req($u('project', 'Bad Slug!'));
ck($c === 400, '不合法 slug 回 400', $c);
[$c, $h] = ec_req($u('spots', $E), 'POST', ['Content-Type' => 'application/x-www-form-urlencoded'], 'x=1');
ck($c === 405 && stripos($hv($h, 'allow'), 'GET') !== false, 'POST 回 405 並帶 Allow', [$c, $hv($h, 'allow')]);

// ── 4. CORS ─────────────────────────────────────────────────────

[$c, $h] = ec_req($u('spots', $E), 'GET', ['Origin' => EC_ORIGIN]);
ck($hv($h, 'access-control-allow-origin') === EC_ORIGIN, '清單內來源：回該來源本身', $hv($h, 'access-control-allow-origin'));
ck(stripos($hv($h, 'vary'), 'origin') !== false, 'Vary: Origin');
ck(stripos($hv($h, 'access-control-expose-headers'), 'etag') !== false, 'CORS 暴露 ETag');
[$c, $h] = ec_req($u('spots', $E), 'GET', ['Origin' => 'http://localhost:5173']);
ck($hv($h, 'access-control-allow-origin') === 'http://localhost:5173', 'localhost 開發來源（清單內）放行');
foreach (['https://evil.example.org', 'https://embed.example.org.evil.org', 'http://embed.example.org', 'https://embed.example.org:8443', 'null'] as $bad) {
    [$c, $h] = ec_req($u('spots', $E), 'GET', ['Origin' => $bad]);
    ck($hv($h, 'access-control-allow-origin') === '' && $c === 200, '清單外來源不送 ACAO：' . $bad, [$c, $hv($h, 'access-control-allow-origin')]);
    ck(stripos($hv($h, 'vary'), 'origin') !== false, '清單外來源仍送 Vary: Origin：' . $bad);
}
[$c, $h] = ec_req($u('spots', $E));
ck($hv($h, 'access-control-allow-origin') === '', '無 Origin 不送 ACAO');
[$c, $h] = ec_req($u('spots', $N), 'GET', ['Origin' => EC_ORIGIN]);
ck($hv($h, 'access-control-allow-origin') === '' && $c === 200, '沒有清單的專案：任何來源都不送 CORS（預設空清單）', $hv($h, 'access-control-allow-origin'));
[$c, $h] = ec_req($u('project', $E), 'GET', ['Origin' => EC_ORIGIN]);
ck($hv($h, 'access-control-allow-origin') === EC_ORIGIN, 'api=project 同樣套用 CORS');
[$c, $h] = ec_req($u('spots', $E), 'OPTIONS', ['Origin' => EC_ORIGIN, 'Access-Control-Request-Method' => 'GET', 'Access-Control-Request-Headers' => 'if-none-match']);
ck($c === 204 && $hv($h, 'access-control-allow-origin') === EC_ORIGIN && stripos($hv($h, 'access-control-allow-headers'), 'if-none-match') !== false, 'OPTIONS 預檢 204 並帶 CORS', [$c, $hv($h, 'access-control-allow-origin')]);
[$c, $h] = ec_req($u('spots', $E), 'OPTIONS', ['Origin' => 'https://evil.example.org', 'Access-Control-Request-Method' => 'GET']);
ck($c === 204 && $hv($h, 'access-control-allow-origin') === '', '清單外來源的預檢不帶 CORS', [$c, $hv($h, 'access-control-allow-origin')]);
[$c, $h] = ec_req($u('spots', 'nope_none'), 'GET', ['Origin' => EC_ORIGIN]);
ck($c === 404, '不存在的專案沒有清單可比，404 不送 CORS', $hv($h, 'access-control-allow-origin'));

// ── 5. frame-ancestors 與 X-Frame-Options 互斥 ──────────────────

$map = fn(string $proj, array $q = []) => "$base/?" . http_build_query(['p' => $proj] + $q);
[$c, $h] = ec_req($map($E, ['embed' => '1', 'ui' => 'bare']));
$csp = $hv($h, 'content-security-policy');
ck($c === 200, '嵌入地圖頁 200', $c);
ck(preg_match("#frame-ancestors 'self' https://embed\.example\.org http://localhost:5173#", $csp) === 1, '清單非空：frame-ancestors 含 self＋清單', $csp);
ck($hv($h, 'x-frame-options') === '', '清單非空：X-Frame-Options 被移除（沙盒先送了 DENY）', $hv($h, 'x-frame-options'));
ck(strpos($csp, '*') === false, 'frame-ancestors 不含萬用字元', $csp);
[$c, $h] = ec_req($map($E));
ck($hv($h, 'x-frame-options') === 'DENY' && strpos($hv($h, 'content-security-policy'), 'frame-ancestors') === false, '非 embed=1：維持原狀（保留 XFO、不送 frame-ancestors）', [$hv($h, 'x-frame-options'), $hv($h, 'content-security-policy')]);
[$c, $h] = ec_req($map($N, ['embed' => '1', 'ui' => 'bare']));
ck($hv($h, 'x-frame-options') === 'DENY' && strpos($hv($h, 'content-security-policy'), 'frame-ancestors') === false, '清單為空：維持原狀（保留 XFO、不送 frame-ancestors）', [$hv($h, 'x-frame-options'), $hv($h, 'content-security-policy')]);
foreach ([[$E, ['embed' => '1', 'ui' => 'bare']], [$E, []], [$N, ['embed' => '1']]] as [$pp, $qq]) {
    [$c, $h] = ec_req($map($pp, $qq));
    $both = $hv($h, 'x-frame-options') !== '' && strpos($hv($h, 'content-security-policy'), 'frame-ancestors') !== false;
    ck(!$both, 'XFO 與 frame-ancestors 不同時出現：' . $pp . ' ' . http_build_query($qq));
}
[$c, $h, $b] = ec_req($map($E, ['embed' => '1', 'ui' => 'bare']));
ck(preg_match('#"embedOrigins"\s*:\s*\["https:\\\\?/\\\\?/embed\.example\.org","http:\\\\?/\\\\?/localhost:5173"\]#', $b) === 1, '嵌入頁把清單注入 APP.embedOrigins', preg_match('#"embedOrigins"[^\]]*\]#', $b, $mm) ? $mm[0] : null);
[$c, $h, $b] = ec_req($map($E));
ck(preg_match('#"embedOrigins"\s*:\s*\[\]#', $b) === 1, '非嵌入頁 APP.embedOrigins 為空陣列');

// ── 6. spotId 穩定、num 可重用 ─────────────────────────────────

$spots = fn() => json_decode(ec_req($u('spots', $E))[2], true);
$a = $spots();
$b2 = $spots();
ck($a['spots'] === $b2['spots'] && $a['etag'] === $b2['etag'], '重複請求內容與 etag 相同');
// 編輯座標：spotId 不變、etag 變
$rows = array_map('json_decode', file("$sb/projects/$E/spots.jsonl", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), array_fill(0, 4, true));
$rows[] = ['id' => '0707070707070707', 'project' => $E, 'kind' => 'spot', 'item_num' => 1, 'edit_of' => $ID1, 'lat' => 24.0511, 'lon' => 120.6911, 'created_at' => gmdate('c', time() + 10)];
ec_jsonl("$sb/projects/$E/spots.jsonl", $rows);
$c1 = $spots();
ck(array_column($c1['spots'], 'spotId') === [$ID1, $ID2, $ID3] && $c1['spots'][0]['lat'] === 24.0511 && $c1['etag'] !== $a['etag'], '編輯後 spotId 不變、座標更新、etag 改變');
// 刪除最大號再新增：num 被重用、spotId 是新的、舊 spotId 不再出現
$rows = array_values(array_filter($rows, fn($r) => ($r['id'] ?? '') !== $ID3));
$rows[] = $mk($ID4, 3, '重用 3 號', 24.06, 120.7);
ec_jsonl("$sb/projects/$E/spots.jsonl", $rows);
$c2 = $spots();
$ids2 = array_column($c2['spots'], 'spotId');
ck(array_column($c2['spots'], 'num') === [1, 2, 3] && $ids2 === [$ID1, $ID2, $ID4], 'num 被重用時 spotId 不同，舊 spotId 不會指到新地點', $ids2);
ck(!in_array($ID3, $ids2, true), '被刪除地點的 spotId 不再出現');
ck(array_slice($ids2, 0, 2) === [$ID1, $ID2], '其餘地點的 spotId 不受影響');
require_once $root . '/api/spotlib.php';
ck(spot_id_valid($ID1) && !spot_id_valid('A1A1A1A1A1A1A1A1') && !spot_id_valid('12') && !spot_id_valid($ID1 . '0') && !spot_id_valid(''), 'spot_id_valid 格式檢查');

// ── 7. navsheet ─────────────────────────────────────────────────

$ns = fn(string $proj, string $spot, array $q = []) => "$base/?" . http_build_query(['api' => 'navsheet', 'project' => $proj, 'spot' => $spot] + $q);
[$c, $h, $b] = ec_req($ns($E, $ID1, ['embed' => '1']));
ck($c === 200 && strpos($b, 'nsheet') !== false, 'navsheet 以 spotId 取得 200', $c);
ck(strpos($b, 'geo:24.0511,120.6911') !== false, 'navsheet 用有效座標（含編輯版本）');
ck(preg_match_all('#<a [^>]*href="https?://[^"]*"[^>]*>#', $b, $am) >= 1 && !array_filter($am[0], fn($t) => strpos($t, 'target="_blank"') === false || strpos($t, 'rel="noopener noreferrer"') === false), '外部連結皆 target=_blank rel="noopener noreferrer"');
ck($hv($h, 'set-cookie') === '', 'navsheet 不送 Set-Cookie', $hv($h, 'set-cookie'));
ck(strpos($hv($h, 'content-security-policy'), "frame-ancestors 'self' https://embed.example.org") !== false && $hv($h, 'x-frame-options') === '', 'navsheet embed=1 且清單非空：frame-ancestors，XFO 移除', [$hv($h, 'content-security-policy'), $hv($h, 'x-frame-options')]);
ck(strpos($b, 'data-nsheet-origins="[&quot;https:\/\/embed.example.org&quot;') !== false || strpos($b, 'data-nsheet-origins="[&quot;https://embed.example.org&quot;') !== false, 'navsheet 注入 postMessage 目標來源（只取自清單）');
[$c, $h, $b] = ec_req($ns($E, $ID1));
ck($c === 200 && strpos($hv($h, 'content-security-policy'), "frame-ancestors 'none'") !== false && $hv($h, 'x-frame-options') === 'DENY' && strpos($b, 'data-nsheet-origins="[]"') !== false, 'navsheet 無 embed=1：禁止嵌入且不注入來源', [$hv($h, 'content-security-policy'), $hv($h, 'x-frame-options')]);
[$c, $h, $b] = ec_req($ns($E, '2', ['embed' => '1']));
ck($c === 200 && strpos($b, 'geo:24.0525,120.6925') !== false, 'navsheet 也接受 num（相容）');
[$c, $h, $b] = ec_req($ns($E, '0000000000000000', ['embed' => '1']));
ck($c === 404 && strpos($hv($h, 'cache-control'), 'no-store') !== false && strpos($b, 'nsheet-item') === false, 'navsheet 不存在的 spotId 回 404、不快取、無導航項目', [$c, $hv($h, 'cache-control')]);
[$c] = ec_req($ns($E, '', ['embed' => '1']));
ck($c === 404, 'navsheet 缺 spot 回 404', $c);
[$c] = ec_req($ns($E, $ID3, ['embed' => '1']));
ck($c === 404, 'navsheet 已被刪除地點的舊 spotId 回 404', $c);
[$c] = ec_req($ns('nope_none', $ID1, ['embed' => '1']));
ck($c === 404, 'navsheet 不存在的專案回 404', $c);
[$c] = ec_req($ns('Bad Slug!', $ID1, ['embed' => '1']));
ck($c === 404, 'navsheet 不合法 slug 回 404', $c);
[$c, $h] = ec_req($ns($E, $ID1, ['embed' => '1']), 'POST', ['Content-Type' => 'application/x-www-form-urlencoded'], 'x=1');
ck($c === 405 && stripos($hv($h, 'allow'), 'GET') !== false, 'navsheet POST 回 405 並帶 Allow', [$c, $hv($h, 'allow')]);
[$c, $h, $b] = ec_req($ns($E, $ID1, ['embed' => '1', 'theme' => 'dark']));
ck(strpos($b, 'data-theme="dark"') !== false, 'navsheet theme=dark');
[$c, $h, $b] = ec_req($ns($E, $ID1, ['embed' => '1', 'theme' => '"><script>x']));
ck(strpos($b, '<script>x') === false, 'navsheet theme 參數不可注入');

// ── 8. 後台儲存的格式驗證 ──────────────────────────────────────

$meta = fn() => json_decode((string)file_get_contents("$sb/projects/$E/meta.json"), true);
$postMeta = function (string $list) use ($base, $E, $csrf, $cookie): array {
    $body = http_build_query(['action' => 'meta', 'project' => $E, 'csrf' => $csrf, 'embed_submitted' => '1', 'embedOrigins' => $list, 'title' => '嵌入測試']);
    return ec_req("$base/manager/$E", 'POST', ['Content-Type' => 'application/x-www-form-urlencoded', 'Cookie' => $cookie], $body);
};
$before = $meta()['embedOrigins'] ?? null;
foreach (['https://*.example.org', 'https://ok.example.org https://embed.example.org/path', '*'] as $bad) {
    [$c] = $postMeta($bad);
    ck($c === 400 && ($meta()['embedOrigins'] ?? null) === $before, '後台拒絕含不合法項目的清單且不寫入：' . $bad, [$c, $meta()['embedOrigins'] ?? null]);
}
[$c] = $postMeta("HTTPS://New.Example.org:443/\nhttp://localhost:3000");
ck($c === 302 && ($meta()['embedOrigins'] ?? null) === ['https://new.example.org', 'http://localhost:3000'], '後台存入合法清單並標準化', [$c, $meta()['embedOrigins'] ?? null]);
[$c] = $postMeta('');
ck($c === 302 && !array_key_exists('embedOrigins', $meta()), '清空清單移除欄位', [$c, $meta()['embedOrigins'] ?? null]);
[$c, $h, $b] = ec_req($u('spots', $E), 'GET', ['Origin' => EC_ORIGIN]);
ck($hv($h, 'access-control-allow-origin') === '', '清單清空後立即不再放行', $hv($h, 'access-control-allow-origin'));
[$c] = ec_req("$base/manager/$E", 'POST', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['action' => 'meta', 'project' => $E, 'csrf' => 'wrong', 'embed_submitted' => '1', 'embedOrigins' => 'https://x.example.org']));
ck(in_array($c, [401, 403], true) && !array_key_exists('embedOrigins', $meta()), '未登入不可改清單', $c);

// ── 9. 未知 postMessage 指令（靜態檢查）────────────────────────

$starTarget = "/postMessage\([^;]*['\"]\*['\"]\s*\)/";
$bridge = (string)file_get_contents($root . '/assets/js/embed-bridge.js');
ck(strpos($bridge, "'unknown_command'") !== false && preg_match('/if \(!fn\)\s*return this\.reply\(job,\s*\'error\',\s*\{\s*code:\s*\'unknown_command\'/', $bridge) === 1, 'embed-bridge.js 對未登錄的指令回 error: unknown_command');
ck(preg_match('/async run\(job\).*?try \{.*?\} catch \(err\) \{.*?code:\s*\'internal\'/s', $bridge) === 1, 'embed-bridge.js 指令執行包在 try/catch，例外轉為 error 而不外拋');
ck(preg_match('/originOk\(origin\)\s*\{\s*return this\.allowed\.length > 0 && this\.allowed\.includes\(origin\)/', $bridge) === 1 && strpos($bridge, 'e.source !== window.parent') !== false, 'embed-bridge.js 只收清單內來源且須來自父視窗（空清單全拒）');
ck(strpos($bridge, "postMessage(Object.assign({ v: PROTO, ns: NS }, payload), target)") !== false && !preg_match($starTarget, $bridge), 'embed-bridge.js 回覆指定目標來源，不用 "*"');
$nav = (string)file_get_contents($root . '/assets/js/navsheet.js');
ck(!preg_match($starTarget, $nav), 'navsheet.js 不用 "*" 當 postMessage 目標');

echo "\n";
if ($fails) {
    foreach ($fails as $f) echo "FAIL $f\n";
    echo "\nembedcheck：$n 項中 " . count($fails) . " 項失敗\n";
    exit(1);
}
echo "embedcheck：全部通過（$n 項）\n";
