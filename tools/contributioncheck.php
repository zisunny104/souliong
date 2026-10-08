<?php
/** Isolated HTTP tests: shared code and code-free access, real files, captions, existing code gates. */
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

$sb = str_replace('\\', '/', sys_get_temp_dir()) . '/contributioncheck_' . bin2hex(random_bytes(4));
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
$primaryToken = primary_session_issue($cfg, 'cfg');
$cookie = PRIMARY_COOKIE . '=' . $primaryToken;
$csrf = primary_csrf_for_token($cfg, $primaryToken);

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


require_once $root . '/api/contribaccess.php';
$epoch = 1791072000;
function policy(int $start, int $end): array {
    return ['contributionAccess' => ['enabled' => true, 'starts_at' => gmdate('c', $start), 'expires_at' => gmdate('c', $end)],
        'embedOrigins' => ['http://127.0.0.1:8127'], 'contrib' => ['kinds' => ['photo', 'text']]];
}
$m = policy($epoch, $epoch + 60);
ck(contrib_free_state($m, $epoch - 1)['state'] === 'scheduled', 'before start');
ck(contrib_free_state($m, $epoch)['open'], 'inclusive start');
ck(contrib_free_state($m, $epoch + 59)['open'], 'inside window');
ck(contrib_free_state($m, $epoch + 60)['state'] === 'ended', 'exclusive end');
ck(!contrib_free_state(policy($epoch, $epoch), $epoch)['open'], 'empty window closed');
ck(!contrib_free_state(null, $epoch)['open'], 'default closed');
ck(contrib_timestamp('tomorrow') === false && contrib_timestamp('2026-02-30T12:00:00Z') === false, 'malformed dates fail closed');
ck(!contrib_free_state($m + ['features' => ['upload' => false]], $epoch)['open'], 'read-only closed');
ck(contrib_local_time('2026-10-04T12:00') === '2026-10-04T04:00:00+00:00', 'Taipei conversion');
ck(contrib_local_time('2026-02-30T12:00') === null, 'invalid date rejected');
$metaPath = "$sb/projects/$P/meta.json";
cc_write($metaPath, policy(time() - 60, time() + 3600));
$url = "$base/?api=upload";
$fields = ['project' => $P, 'kind' => 'photo', 'name' => '體驗者', 'comment' => '村子裡的一束光', 'owner' => 'test-device'];
[$c, $r] = cc_post($url, $fields, ['photo' => ['test.png', $png]], null);
ck($c === 200 && !empty($r['item']['id']), 'anonymous real photo accepted', [$c, $r]);
$entry = $r['item'] ?? [];
ck(($entry['comment'] ?? '') === $fields['comment'] && ($entry['name'] ?? '') === $fields['name'], 'caption and name preserved');
ck(!empty($entry['photo']) && is_file($cfg['projects_dir'] . '/' . $P . '/photos/' . basename($entry['photo'])), 'photo bytes persisted');
// 精選只由管理者設定，不更動投稿內容或其原始 ID。
$feature = ['project' => $P, 'id' => $entry['id'], 'featured' => '1'];
[$c, $r] = cc_post("$base/?api=featureentry", $feature, [], null);
ck($c === 403 || $c === 401, 'visitor cannot feature entries', [$c, $r]);
[$c, $r] = cc_post("$base/?api=featureentry", $feature + ['csrf' => 'wrong'], [], $cookie);
ck($c === 403, 'featured state requires CSRF', [$c, $r]);
[$c, $r] = cc_post("$base/?api=featureentry", $feature + ['csrf' => $csrf], [], $cookie);
ck($c === 200 && ($r['featured'] ?? false) === true, 'manager can feature entry', [$c, $r]);
[$c, $r] = cc_http("$base/?api=list&project=$P");
$list = $r['items'] ?? [];
$starred = array_values(array_filter($list, fn($row) => ($row['id'] ?? '') === $entry['id']))[0] ?? [];
ck(($starred['featured'] ?? false) === true && $starred['comment'] === $entry['comment'] && $starred['photo'] === $entry['photo'], 'featured state reloads without changing content');
[$c, $r] = cc_post("$base/?api=featureentry", array_merge($feature, ['featured' => '<script>', 'csrf' => $csrf]), [], $cookie);
ck($c === 400, 'invalid featured values rejected');
[$c, $r] = cc_post("$base/?api=featureentry", array_merge($feature, ['id' => '0000000000000000', 'csrf' => $csrf]), [], $cookie);
ck($c === 404, 'missing featured entry rejected');
[$c, $r] = cc_post("$base/?api=featureentry", array_merge($feature, ['featured' => '0', 'csrf' => $csrf]), [], $cookie);
ck($c === 200 && ($r['featured'] ?? true) === false, 'manager can remove featured state');

[$c, $r] = cc_http("$base/?api=contribstatus&project=$P");
ck($c === 200 && $r['open'] === true, 'status endpoint');
[$c, $r] = cc_http("$base/?p=$P&embed=1&ui=submit&type=photo");
ck($c === 200 && str_contains($r, 'embed-submit') && str_contains($r, 'contribution.js') && str_contains($r, 'kind-photo.js') && !str_contains($r, 'kind-video.js'), 'embedded submit dialog limited by type');
[$c, $r] = cc_post($url, $fields, ['photo' => ['bad.png', 'not an image']], null);
ck($c === 415, 'fake photo MIME rejected', [$c, $r]);
[$c, $r] = cc_post($url, $fields, [], null);
ck($c === 200, 'code-free photo follows existing caption-only behavior');
[$c, $r] = cc_post($url, array_replace($fields, ['kind' => 'text']), ['photo' => ['test.png', $png]], null);
ck($c === 200, 'code-free access accepts project-enabled text');
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
ck(count($lines) === 4, 'denied uploads leave no records');
// Policy changes require the same manager authorization and CSRF as codes.
$post = ['action' => 'contribaccess', 'project' => $P, 'contrib_free_enabled' => '1',
    'contrib_free_start' => '2026-10-04T12:00', 'contrib_free_end' => '2026-10-04T13:00'];
[$c, $r] = cc_post("$base/?api=manager", $post + ['csrf' => 'wrong'], [], $cookie);
ck($c === 403, 'manager invalid CSRF rejected');
[$c, $r] = cc_post("$base/?api=manager", $post + ['csrf' => $csrf], [], $cookie);
$saved = json_decode(file_get_contents($metaPath), true);
ck(($saved['contributionAccess']['starts_at'] ?? '') === '2026-10-04T04:00:00+00:00', 'manager persists policy in UTC', $c);

// Long-term and timed policies use the same schema and project content rules.
$long = ['contributionAccess' => ['enabled' => true, 'starts_at' => null, 'expires_at' => null],
    'contrib' => ['kinds' => ['text', 'audio'], 'newSpot' => 'contributor']];
ck(contrib_free_state($long)['open'], 'long-term code-free open');
$disabled = $long; $disabled['contributionAccess']['enabled'] = false;
ck(!contrib_free_state($disabled)['open'], 'long-term code-free may be disabled');
cc_write($metaPath, $long);
[$c, $r] = cc_post($url, $fields, ['photo' => ['test.png', $png]], null);
ck($c === 403, 'project excludes photos even while code-free');
[$c, $r] = cc_post($url, array_replace($fields, ['kind' => 'text']), [], null);
ck($c === 200, 'text-only project accepts code-free text');
[$c, $r] = cc_post($url, array_replace($fields, ['kind' => 'audio']), ['media' => ['test.wav', $wav]], null);
ck($c === 200, 'project-enabled audio uses same code-free gate', [$c, $r]);
ck(souliong_contrib_cfg($long)['captions'] === ['audio'], 'captions default to every enabled media kind');
$nocap = $long; $nocap['contrib']['noCaption'] = ['audio']; cc_write($metaPath, $nocap);
ck(souliong_contrib_cfg($nocap)['captions'] === [], 'noCaption turns captions off per kind');
[$c, $r] = cc_post($url, array_replace($fields, ['kind' => 'audio', 'comment' => '不該被存下']), ['media' => ['test.wav', $wav]], null);
ck($c === 200 && ($r['item']['comment'] ?? null) === null, 'server drops a caption when the kind disallows it', [$c, $r]);
cc_write($metaPath, $long);
[$c, $r] = cc_post("$base/?api=newspot", ['project' => $P, 'title' => '訪客新增', 'lat' => '24', 'lon' => '120', 'owner' => 'test-device'], [], null);
ck($c === 200, 'contributor newSpot shares code-free gate', [$c, $r]);
$long['contrib']['newSpot'] = 'off'; cc_write($metaPath, $long);
[$c, $r] = cc_post("$base/?api=newspot", ['project' => $P, 'title' => '不可新增', 'lat' => '24', 'lon' => '120'], [], null);
ck($c === 403, 'newSpot setting remains authoritative');
$long['features'] = ['upload' => false]; cc_write($metaPath, $long);
[$c, $r] = cc_post($url, array_replace($fields, ['kind' => 'text']), [], null);
ck($c === 403, 'read-only rejects code-free text');
[$c, $r] = cc_http("$base/?api=contribstatus&project=$P");
ck(!$r['open'] && !$r['codesAvailable'] && $r['kinds'] === [], 'read-only status matches upload permission');
ck(!contrib_open($cfg, $P), 'read-only project closes unlock gate too');
unset($long['features']); cc_write($metaPath, $long);
cc_write("$sb/projects/$P/codes.json", [['code' => '777777', 'expires_at' => null, 'max_uses' => null, 'used_count' => 7]]);
ck(code_check($cfg, $P, '777777', false), 'legacy long-term code defaults enabled');
ck(code_set_enabled($cfg, $P, '777777', false) && !code_check($cfg, $P, '777777', false), 'disable long-term code');
ck(code_set_enabled($cfg, $P, '777777', true) && code_check($cfg, $P, '777777', false), 're-enable original code');
ck(codes_load($cfg, $P)[0]['used_count'] === 7, 'toggling preserves used count');
[$c, $r] = cc_post("$base/?api=contribstatus", ['project' => $P, 'code' => '777777'], [], null);
ck($c === 200 && $r['codeValid'] && $r['open'] && $r['kinds'] === ['audio', 'text'], 'status reports shared access without disclosing code', [$c, $r]);
ck(!array_key_exists('code', $r) && codes_load($cfg, $P)[0]['used_count'] === 7, 'status does not expose or consume code');
[$c, $r] = cc_post($url, array_replace($fields, ['kind' => 'text', 'code' => '777777']), [], null);
ck($c === 200 && codes_load($cfg, $P)[0]['used_count'] === 7, 'code-free access does not consume supplied code');
$long['contributionAccess']['enabled'] = false; cc_write($metaPath, $long);
[$c, $r] = cc_post($url, array_replace($fields, ['kind' => 'text', 'code' => '777777']), [], null);
ck($c === 200 && codes_load($cfg, $P)[0]['used_count'] === 8, 'code remains usable after free access closes');
[$c, $r] = cc_post($url, array_replace($fields, ['kind' => 'text']), [], null);
ck($c === 403, 'no code denied when free access closes');
ck(!contrib_code_active(['expires_at' => gmdate('c', $epoch)], $epoch), 'code expiry shares exclusive boundary');
ck(!contrib_code_active(['starts_at' => gmdate('c', $epoch)], $epoch - 1), 'code start shares inclusive boundary');
// Manager saves keep other project fields intact and permit nullable dates.
cc_write($metaPath, $long + ['title' => 'keep', 'badgeColor' => '#123456']);
[$c, $r] = cc_post("$base/?api=manager", ['action' => 'contribaccess', 'project' => $P, 'csrf' => $csrf, 'contrib_free_enabled' => '1'], [], $cookie);
$saved = json_decode(file_get_contents($metaPath), true);
ck($saved['contributionAccess'] === ['enabled' => true, 'starts_at' => null, 'expires_at' => null], 'manager stores single canonical long-term schema');
ck($saved['title'] === 'keep' && $saved['badgeColor'] === '#123456' && $saved['contrib'] === $long['contrib'], 'saving access preserves project content and appearance');
// Submission settings preserve period history without granting access or storing codes.
require_once $root . '/api/contribhistory.php';
$scheduled = $saved;
$scheduled['contributionAccess'] = ['enabled' => true, 'starts_at' => gmdate('c', time() + 3600), 'expires_at' => gmdate('c', time() + 7200)];
$scheduled['contrib']['newSpot'] = 'admin'; cc_write($metaPath, $scheduled);
[$c, $r] = cc_post("$base/?api=manager", ['action' => 'contribaccess', 'project' => $P, 'csrf' => $csrf, 'contrib_newspot_submitted' => '1'], [], $cookie);
$saved = json_decode(file_get_contents($metaPath), true);
$history = $saved['contributionAccessHistory'][0] ?? [];
ck(contrib_history_period_state($history, true) === 'cancelled', 'cancelled future period never claimed as open');
ck($saved['contrib']['newSpot'] === 'admin', 'unchecked new-place option preserves admin-only setting');
[$c, $r] = cc_post("$base/?api=manager", ['action' => 'contribaccess', 'project' => $P, 'csrf' => $csrf, 'contrib_free_enabled' => '1', 'contrib_free_mode' => 'long', 'contrib_free_start' => '2030-01-01T00:00', 'contrib_free_end' => '2030-01-02T00:00', 'contrib_newspot_submitted' => '1', 'contrib_allow_newspot' => '1'], [], $cookie);
$saved = json_decode(file_get_contents($metaPath), true);
ck($saved['contributionAccess']['starts_at'] === null && $saved['contributionAccess']['expires_at'] === null, 'long-term mode clears stale dates server-side');
ck($saved['contrib']['newSpot'] === 'contributor', 'new-place checkbox uses existing contributor policy');
[$c, $r] = cc_post("$base/?api=manager", ['action' => 'contribaccess', 'project' => $P, 'csrf' => $csrf, 'contrib_free_enabled' => '1', 'contrib_free_mode' => 'period'], [], $cookie);
ck($c === 400, 'bounded period requires end');
[$c, $r] = cc_post("$base/?api=manager", ['action' => 'contribaccess', 'project' => $P, 'csrf' => $csrf, 'contrib_free_enabled' => '1', 'contrib_free_mode' => 'scheduled'], [], $cookie);
ck($c === 400, 'scheduled period requires start');
[$c, $r] = cc_post("$base/?api=manager", ['action' => 'togglecode', 'project' => $P, 'csrf' => $csrf, 'code' => '777777', 'enabled' => '0'], [], $cookie);
$saved = json_decode(file_get_contents($metaPath), true);
ck(count($saved['contributionCodeHistory'] ?? []) === 1, 'disabled code archives its period');
ck(!str_contains(json_encode($saved['contributionCodeHistory']), '777777'), 'history contains no code secret');
[$c, $r] = cc_post("$base/?api=manager", ['action' => 'delcode', 'project' => $P, 'csrf' => $csrf, 'code_del' => '777777'], [], $cookie);
$saved = json_decode(file_get_contents($metaPath), true);
ck(count($saved['contributionCodeHistory'] ?? []) === 2 && codes_load($cfg, $P) === [], 'removal retains code-period summary');
$bounded = ['contributionAccess' => ['enabled' => true, 'starts_at' => null, 'expires_at' => null], 'contributionAccessHistory' => array_fill(0, 100, ['reason' => 'closed'])];
contrib_history_archive_access($bounded, ['enabled' => false, 'starts_at' => null, 'expires_at' => null]);
ck(count($bounded['contributionAccessHistory']) === 100, 'period snapshots stay bounded');
// Attribution is independent of registered identity; tests write only to the sandbox.
cc_write($metaPath, policy(time() - 60, time() + 3600));
foreach (['cc-by', 'cc-by-sa', 'cc-by-nd', 'cc-by-nc', 'cc-by-nc-sa', 'cc-by-nc-nd', 'cc0'] as $license) {
    [$c, $r] = cc_post($url, array_replace($fields, ['license' => $license, 'author_url' => 'https://example.com/profile']), ['photo' => ['license.png', $png]], null);
    ck($c === 200 && ($r['item']['license'] ?? '') === $license && ($r['item']['name'] ?? '') === $fields['name'] && ($r['item']['author_url'] ?? '') === 'https://example.com/profile', 'unregistered named author preserves ' . $license, [$c, $r]);
}
foreach (['cc-by-sa', '<script>alert(1)</script>'] as $license) {
    [$c, $r] = cc_post($url, array_replace($fields, ['name' => '   ', 'license' => $license]), ['photo' => ['license.png', $png]], null);
    ck($c === 400, 'unnamed or invalid licence rejected ' . $license);
}
[$c, $r] = cc_post($url, array_replace($fields, ['name' => '', 'license' => 'cc0']), ['photo' => ['license.png', $png]], null);
ck($c === 200 && ($r['item']['license'] ?? '') === 'cc0' && ($r['item']['name'] ?? '') === '匿名', 'unnamed author uses CC0 without fabricated attribution');
echo 'contributioncheck: ' . ($n - count($fails)) . '/' . $n . " passed\n";
foreach ($fails as $fail) echo "FAIL $fail\n";
exit($fails ? 1 : 0);
