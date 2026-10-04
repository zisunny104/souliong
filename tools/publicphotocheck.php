<?php
/** Isolated HTTP tests: finite anonymous photo permission, real files, captions, existing code gates. */
error_reporting(E_ALL);
$root = dirname(__DIR__);
$port = 8127;
$base = "http://127.0.0.1:$port";

function cc_rm(string $d): void {
    if (!is_dir($d)) return;
    foreach (scandir($d) as $n) {
        if ($n === '.' || $n === '..') continue;
        is_dir("$d/$n") ? cc_rm("$d/$n") : @unlink("$d/$n");
    }
    @rmdir($d);
}
function cc_copy(string $from, string $to): void {
    if (!is_dir($to)) mkdir($to, 0777, true);
    foreach (scandir($from) as $n) {
        if ($n === '.' || $n === '..') continue;
        is_dir("$from/$n") ? cc_copy("$from/$n", "$to/$n") : copy("$from/$n", "$to/$n");
    }
}
function cc_write(string $path, $data): void {
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
    file_put_contents($path, is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

if (!function_exists('imagecreatetruecolor') || !class_exists('finfo')) {
    fwrite(STDERR, "需要 GD 與 fileinfo 擴充\n");
    exit(1);
}

$sb = str_replace('\\', '/', sys_get_temp_dir()) . '/publicphotocheck_' . bin2hex(random_bytes(4));
$server = null;
register_shutdown_function(function () use (&$server, $sb) {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    cc_rm($sb);
});

$real = require $root . '/api/config.php';
$cfg = array_merge($real, [
    'ip_salt' => 'contentcheck-' . bin2hex(random_bytes(8)), 'primary_pin' => '', 'primary_pin_label' => '',
    'state_dir' => "$sb/state", 'projects_dir' => "$sb/projects", 'rate_max' => 100000, 'rate_limits' => [],
]);
unset($cfg['admin_pin'], $cfg['admin_pin_label']);
mkdir("$sb/state", 0777, true);
cc_copy($root . '/api', "$sb/api");
cc_copy($root . '/pages', "$sb/pages");
cc_copy($root . '/lang', "$sb/lang");
cc_copy($root . '/assets', "$sb/assets");
foreach (['index.php', 'config.php'] as $f) { if (is_file("$root/$f")) copy("$root/$f", "$sb/$f"); }
cc_write("$sb/api/config.php", "<?php\nreturn json_decode(file_get_contents(__DIR__ . '/../cfg.json'), true);\n");
cc_write("$sb/cfg.json", $cfg);

require_once $root . '/api/security.php';
$cookie = PRIMARY_COOKIE . '=' . primary_derived($cfg);
$csrf = primary_derived($cfg);

$P = 'cc';
$now = gmdate('c');
cc_write("$sb/projects/$P/meta.json", ['features' => ['contentEdit' => true]]);
cc_write("$sb/projects/$P/spots.jsonl", json_encode([
    'id' => 'o1', 'project' => $P, 'kind' => 'spot', 'num' => 1, 'item_num' => 1, 'title' => 'P1',
    'lat' => 24.0, 'lon' => 120.0, 'created_at' => $now,
]) . "\n");

// 測試素材：PNG 由 GD 產、WAV 手組（finfo 判為 audio/x-wav，在 audio 白名單內）
function cc_png(int $w, int $h): string {
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 30, 120, 220));
    ob_start(); imagepng($im); return (string)ob_get_clean();
}
function cc_wav(): string {
    $data = str_repeat("\x00\x00", 800);
    return 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16) . 'data' . pack('V', strlen($data)) . $data;
}
$png = cc_png(40, 30); $thumb = cc_png(8, 6); $wav = cc_wav();

// ── 內建伺服器 ──────────────────────────────────────────────────

$log = "$sb/server.log";
$server = proc_open([PHP_BINARY, '-d', 'post_max_size=256K', '-d', 'upload_max_filesize=128K', '-S', "127.0.0.1:$port", '-t', $sb, "$sb/index.php"], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $sb);
$up = false;
for ($i = 0; $i < 50 && !$up; $i++) {
    usleep(100000);
    $s = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);
    if ($s) { fclose($s); $up = true; }
}
if (!$up) { fwrite(STDERR, "內建伺服器起不來（port $port 被佔用？）\n"); exit(1); }

/** multipart POST；$files: 欄位名 => [檔名, 內容]。回傳 [http 狀態, 解碼後 JSON 或原文]。 */
function cc_post(string $url, array $fields, array $files, ?string $cookie): array {
    $b = 'ccb' . bin2hex(random_bytes(6));
    $body = '';
    foreach ($fields as $k => $v) $body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
    foreach ($files as $k => [$fn, $bytes]) $body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"; filename=\"$fn\"\r\nContent-Type: application/octet-stream\r\n\r\n$bytes\r\n";
    $body .= "--$b--\r\n";
    $h = "Content-Type: multipart/form-data; boundary=$b\r\n" . ($cookie ? "Cookie: $cookie\r\n" : '');
    return cc_http($url, ['method' => 'POST', 'header' => $h, 'content' => $body]);
}
function cc_http(string $url, array $http = []): array {
    $ctx = stream_context_create(['http' => $http + ['ignore_errors' => true, 'timeout' => 20]]);
    $raw = @file_get_contents($url, false, $ctx);
    $code = 0; $ct = '';
    foreach ($http_response_header ?? [] as $l) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $l, $m)) $code = (int)$m[1];
        if (stripos($l, 'Content-Type:') === 0) $ct = trim(substr($l, 13));
    }
    $j = is_string($raw) ? json_decode($raw, true) : null;
    return [$code, $j ?? $raw, $ct];
}

$n = 0; $fails = [];
function ck(bool $ok, string $what, $detail = null): void {
    global $n, $fails;
    $n++;
    if (!$ok) $fails[] = $what . ($detail !== null ? ' | ' . (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE)) : '');
}


require_once $root . '/api/publicphoto.php';
$epoch = 1791072000;
function policy(int $start, int $end): array {
    return ['publicPhoto' => ['enabled' => true, 'startsAt' => gmdate('c', $start), 'endsAt' => gmdate('c', $end)],
        'embedOrigins' => ['http://127.0.0.1:8127'], 'contrib' => ['kinds' => ['photo', 'text']]];
}
$m = policy($epoch, $epoch + 60);
ck(public_photo_state($m, $epoch - 1)['state'] === 'scheduled', 'before start');
ck(public_photo_state($m, $epoch)['open'], 'inclusive start');
ck(public_photo_state($m, $epoch + 59)['open'], 'inside window');
ck(public_photo_state($m, $epoch + 60)['state'] === 'ended', 'exclusive end');
ck(!public_photo_state(policy($epoch, $epoch), $epoch)['open'], 'empty window closed');
ck(!public_photo_state(null, $epoch)['open'], 'default closed');
ck(public_photo_timestamp('tomorrow') === false && public_photo_timestamp('2026-02-30T12:00:00Z') === false, 'malformed dates fail closed');
ck(!public_photo_state($m + ['features' => ['upload' => false]], $epoch)['open'], 'read-only closed');
ck(public_photo_local_time('2026-10-04T12:00') === '2026-10-04T04:00:00+00:00', 'Taipei conversion');
ck(public_photo_local_time('2026-02-30T12:00') === null, 'invalid date rejected');
$metaPath = "$sb/projects/$P/meta.json";
cc_write($metaPath, policy(time() - 60, time() + 3600));
$url = "$base/?api=upload";
$fields = ['project' => $P, 'kind' => 'photo', 'name' => '體驗者', 'comment' => '村子裡的一束光', 'owner' => 'test-device'];
[$c, $r] = cc_post($url, $fields, ['photo' => ['test.png', $png]], null);
ck($c === 200 && !empty($r['item']['id']), 'anonymous real photo accepted', [$c, $r]);
$entry = $r['item'] ?? [];
ck(($entry['comment'] ?? '') === $fields['comment'] && ($entry['name'] ?? '') === $fields['name'], 'caption and name preserved');
ck(!empty($entry['photo']) && is_file($cfg['projects_dir'] . '/' . $P . '/photos/' . basename($entry['photo'])), 'photo bytes persisted');
[$c, $r] = cc_http("$base/?api=photostatus&project=$P");
ck($c === 200 && $r['open'] === true, 'status endpoint');
[$c, $r] = cc_http("$base/?api=photosubmit&project=$P&embed=1");
ck($c === 200 && str_contains($r, 'capture="environment"') && str_contains($r, 'photo-comment'), 'embedded camera and caption controls');
[$c, $r] = cc_post($url, $fields, ['photo' => ['bad.png', 'not an image']], null);
ck($c === 415, 'fake photo MIME rejected', [$c, $r]);
[$c, $r] = cc_post($url, $fields, [], null);
ck($c === 403, 'photo permission cannot post caption alone');
[$c, $r] = cc_post($url, array_replace($fields, ['kind' => 'text']), ['photo' => ['test.png', $png]], null);
ck($c === 403, 'public photo cannot post text');
cc_write("$sb/projects/$P/blocked.json", ['owners' => [hash('sha256', 'test-device')], 'contribs' => []]);
[$c, $r] = cc_post($url, $fields, ['photo' => ['test.png', $png]], null);
ck($c === 403 && str_contains($r['error'] ?? '', '停權'), 'block list precedes public permission');
cc_write("$sb/projects/$P/blocked.json", []);
foreach ([policy(time() + 60, time() + 3600), policy(time() - 3600, time()), []] as $i => $closed) {
    cc_write($metaPath, $closed);
    [$c, $r] = cc_post($url, $fields, ['photo' => ['test.png', $png]], null);
    ck($c === 403, 'closed interval rejects upload ' . $i);
}
cc_write("$sb/projects/$P/codes.json", [['code' => '123456', 'label' => '', 'created' => gmdate('c'), 'expires_at' => null, 'max_uses' => 1, 'used_count' => 0]]);
[$c, $r] = cc_post($url, $fields + ['code' => '123456'], ['photo' => ['test.png', $png]], null);
ck($c === 200, 'existing code works outside public window');
[$c, $r] = cc_post($url, $fields + ['code' => '123456'], ['photo' => ['test.png', $png]], null);
ck($c === 403, 'existing use limit remains');
$lines = file("$sb/projects/$P/entries.jsonl", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
ck(count($lines) === 2, 'denied uploads leave no records');
// Policy changes require the same manager authorization and CSRF as codes.
$post = ['action' => 'publicphoto', 'project' => $P, 'public_photo_enabled' => '1',
    'public_photo_start' => '2026-10-04T12:00', 'public_photo_end' => '2026-10-04T13:00'];
[$c, $r] = cc_post("$base/?api=manager", $post + ['csrf' => 'wrong'], [], $cookie);
ck($c === 403, 'manager invalid CSRF rejected');
[$c, $r] = cc_post("$base/?api=manager", $post + ['csrf' => $csrf], [], $cookie);
$saved = json_decode(file_get_contents($metaPath), true);
ck(($saved['publicPhoto']['startsAt'] ?? '') === '2026-10-04T04:00:00+00:00', 'manager persists policy in UTC', $c);
echo 'publicphotocheck: ' . ($n - count($fails)) . '/' . $n . " passed\n";
foreach ($fails as $fail) echo "FAIL $fail\n";
exit($fails ? 1 : 0);
