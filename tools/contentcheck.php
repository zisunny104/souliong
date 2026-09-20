<?php
/**
 * 點位內容區（api/spotcontent.php）的行為回歸測試（CLI）。
 *
 * 用法：php tools/contentcheck.php      全部通過結束碼 0，有任何一項失敗為 1。
 *
 * 在臨時沙盒跑（sys_get_temp_dir()/contentcheck_*，結束時清掉）：複製 api／pages／lang 與根目錄檔案，
 * 起一個內建伺服器（127.0.0.1:8123），用真正的 multipart 上傳打 op=save，不碰真正的 state/ 與 projects/。
 * 涵蓋：新增 text／photo／audio、版本衝突、清空、相同內容不寫版本、id 保留（photo／audio 只吃 comment）、
 * 未列出即刪除、壞 MIME、缺檔、非法區塊、權限與 CSRF、photo 網址實際可取（含 th=1 產縮圖）、list 輸出。
 * 依賴：GD（造測試圖與縮圖）、fileinfo（音訊 MIME 判斷）。
 */
error_reporting(E_ALL);
$root = dirname(__DIR__);
$port = 8123;
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

$sb = str_replace('\\', '/', sys_get_temp_dir()) . '/contentcheck_' . bin2hex(random_bytes(4));
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
if (!$up) { fwrite(STDERR, "內建伺服器起不來（port $port 被占用？）\n"); exit(1); }

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

$rev = 'o1';
function save($blocks, string $rev, array $files = [], array $over = []): array {
    global $base, $P, $csrf, $cookie;
    $f = ['project' => $P, 'item_num' => '1', 'csrf' => $csrf, 'op' => 'save', 'base_rev' => $rev, 'blocks' => is_string($blocks) ? $blocks : json_encode($blocks)];
    return cc_post("$base/?api=spotcontent", array_merge($f, $over['fields'] ?? []), $files, array_key_exists('cookie', $over) ? $over['cookie'] : $cookie);
}
function versions(): int {
    global $sb, $P;
    return count(file("$sb/projects/$P/spots.jsonl", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
}

// 權限與 CSRF（在任何寫入之前，失敗必須不留下版本）
[$c, $r] = save([], $rev, [], ['cookie' => null]);
ck($c === 403, '匿名不可存', [$c, $r]);
[$c, $r] = save([], $rev, [], ['fields' => ['csrf' => 'wrong']]);
ck($c === 403, 'CSRF 錯誤不可存', [$c, $r]);
ck(versions() === 1, '失敗的請求不寫版本');

// 新增 text＋photo（含縮圖）＋audio
[$c, $r] = save([
    ['kind' => 'text', 'comment' => '第一段'],
    ['kind' => 'photo', 'file' => 'media_0', 'thumb' => 'media_1', 'comment' => '照片說明', 'source_url' => 'https://example.org/a', 'source_license' => 'cc-by'],
    ['kind' => 'audio', 'file' => 'media_2', 'duration' => 1, 'comment' => '聲音說明'],
], $rev, ['media_0' => ['p.png', $png], 'media_1' => ['t.png', $thumb], 'media_2' => ['a.wav', $wav]]);
ck($c === 200 && !empty($r['ok']), '新增三種區塊', [$c, $r]);
$blocks = $r['item']['content'] ?? [];
ck(count($blocks) === 3 && array_column($blocks, 'kind') === ['text', 'photo', 'audio'], '區塊順序與種類', array_column($blocks, 'kind'));
[$t, $ph, $au] = $blocks + [null, null, null];
ck(!empty($t['id']) && !empty($ph['id']) && !empty($au['id']) && count(array_unique([$t['id'], $ph['id'], $au['id']])) === 3, '每個區塊有唯一 id');
ck(strpos($t['html'] ?? '', '第一段') !== false, 'text 有衍生 html');
ck(($ph['comment'] ?? '') === '照片說明' && ($ph['source_url'] ?? '') === 'https://example.org/a' && ($ph['source_license'] ?? '') === 'cc-by' && ($ph['license'] ?? '') === 'cc0', 'photo 欄位', $ph);
ck(preg_match('#^cc/[0-9_a-f]+\.png$#', $ph['photo'] ?? '') && preg_match('#^cc/[0-9_a-f]+_t\.png$#', $ph['thumb'] ?? ''), 'photo／thumb 落地路徑', $ph);
ck(is_file("$sb/projects/" . ($ph['photo'] ?? 'x')) === false && is_file("$sb/projects/$P/photos/" . basename($ph['photo'] ?? 'x')) && is_file("$sb/projects/$P/photos/" . basename($ph['thumb'] ?? 'x')), '檔案存在 photos/');
ck(is_file("$sb/projects/$P/media/" . basename($au['media'] ?? 'x')) && ($au['media_mime'] ?? '') === 'audio/x-wav', 'audio 落地 media/', $au);
ck(isset($ph['photo_url'], $ph['thumb_url']) && strpos($ph['photo_url'], 'api=photo') !== false && strpos($ph['thumb_url'], 'th=1') === false, 'photo 衍生網址', $ph);
$rev1 = $r['item']['content_rev'] ?? '';
ck($rev1 !== '' && $rev1 !== $rev && versions() === 2, '寫入一筆版本', [$rev1, versions()]);

// 版本衝突
[$c, $r] = save([['kind' => 'text', 'comment' => 'x']], 'o1');
ck($c === 409, '舊 base_rev 回 409', [$c, $r]);
ck(versions() === 2, '409 不寫版本');

// 相同內容不寫版本
[$c, $r] = save([['id' => $t['id'], 'comment' => '第一段'], ['id' => $ph['id'], 'comment' => '照片說明'], ['id' => $au['id'], 'comment' => '聲音說明']], $rev1);
ck($c === 200 && ($r['item']['content_rev'] ?? '') === $rev1 && versions() === 2, '相同內容不寫版本', [$c, $r['item']['content_rev'] ?? null]);

// id 保留：photo／audio 只吃 comment，其餘欄位（含偽造的 photo／media）沿用原值
[$c, $r] = save([
    ['id' => $t['id'], 'comment' => '第一段改'],
    ['id' => $ph['id'], 'kind' => 'photo', 'comment' => '新說明', 'photo' => 'evil/x.png', 'thumb' => 'evil/y.png', 'source_url' => 'https://evil.example', 'license' => 'cc-by'],
    ['id' => $au['id'], 'comment' => '新聲音說明', 'media' => 'evil/x.wav', 'duration' => 999],
], $rev1);
$b2 = $r['item']['content'] ?? [];
ck($c === 200 && count($b2) === 3, 'id 保留儲存成功', [$c, $r]);
ck(($b2[0]['comment'] ?? '') === '第一段改' && strpos($b2[0]['html'] ?? '', '第一段改') !== false, 'text 改 comment');
ck(($b2[1]['photo'] ?? '') === $ph['photo'] && ($b2[1]['thumb'] ?? '') === $ph['thumb'] && ($b2[1]['source_url'] ?? '') === $ph['source_url'] && ($b2[1]['license'] ?? '') === 'cc0' && ($b2[1]['comment'] ?? '') === '新說明', 'photo 只採用 comment', $b2[1] ?? null);
ck(($b2[2]['media'] ?? '') === $au['media'] && ($b2[2]['duration'] ?? null) === ($au['duration'] ?? null) && ($b2[2]['comment'] ?? '') === '新聲音說明', 'audio 只採用 comment', $b2[2] ?? null);
$rev2 = $r['item']['content_rev'] ?? '';
ck($rev2 !== $rev1 && versions() === 3, 'id 保留改 comment 寫一筆版本');

// 未列出即刪除、順序即 blocks 順序
[$c, $r] = save([['id' => $au['id']], ['id' => $t['id'], 'comment' => '第一段改']], $rev2);
$b3 = $r['item']['content'] ?? [];
ck($c === 200 && array_column($b3, 'id') === [$au['id'], $t['id']], '未列出的區塊被刪、順序照送', $b3);
$rev3 = $r['item']['content_rev'] ?? '';

// 新增 photo 但沒給縮圖：th=1 首次請求才產縮圖
[$c, $r] = save([['id' => $au['id']], ['id' => $t['id'], 'comment' => '第一段改'], ['kind' => 'photo', 'file' => 'media_0']], $rev3, ['media_0' => ['p2.png', $png]]);
$b4 = $r['item']['content'] ?? [];
$ph2 = $b4[2] ?? [];
ck($c === 200 && array_key_exists('thumb', $ph2) && $ph2['thumb'] === null && strpos($ph2['thumb_url'] ?? '', 'th=1') !== false, '無縮圖的 photo：thumb 為 null、thumb_url 帶 th=1', $ph2);
$rev4 = $r['item']['content_rev'] ?? '';

// 網址實際可取（絕對網址）
foreach (['photo_url' => $ph, 'thumb_url' => $ph] as $k => $blk) {
    [$c, $body, $ct] = cc_http($base . $blk[$k]);
    ck($c === 200 && strpos($ct, 'image/png') === 0 && is_string($body) && strncmp($body, "\x89PNG", 4) === 0, "$k 回 200 且是 PNG", [$c, $ct]);
}
[$c, $body, $ct] = cc_http($base . ($ph2['photo_url'] ?? '/x'));
ck($c === 200 && strpos($ct, 'image/') === 0, '第二張 photo_url 回 200', [$c, $ct]);
$t2 = basename($ph2['photo'] ?? 'x', '.png');
[$c, $body, $ct] = cc_http($base . ($ph2['thumb_url'] ?? '/x'));
ck($c === 200 && strpos($ct, 'image/') === 0, 'th=1 回 200', [$c, $ct]);
ck(is_file("$sb/projects/$P/photos/{$t2}_t.webp") || is_file("$sb/projects/$P/photos/{$t2}_t.jpg") || is_file("$sb/projects/$P/photos/{$t2}_t.png"), 'th=1 產出縮圖檔');

// list 輸出帶內容與網址
[$c, $r] = cc_http("$base/?api=list&project=$P");
$spot = null;
foreach ((array)($r['items'] ?? []) as $it) { if (($it['id'] ?? '') === $rev4) $spot = $it; }
$lc = $spot['content'] ?? [];
$lph = null;
foreach ($lc as $b) { if (($b['kind'] ?? '') === 'photo') $lph = $b; }
ck($c === 200 && $lph && !empty($lph['photo_url']) && !empty($lph['thumb_url']) && ($spot['content_rev'] ?? '') === $rev4, 'list 輸出 content 與 photo 網址', [$c, $lph]);

// 清空
[$c, $r] = save([], $rev4);
ck($c === 200 && ($r['item']['content'] ?? null) === [] && ($r['item']['content_rev'] ?? '') !== $rev4, '空陣列清空內容並寫版本', [$c, $r]);
$rev5 = $r['item']['content_rev'] ?? '';
$v = versions();
[$c, $r] = save([], $rev5);
ck($c === 200 && versions() === $v, '清空後再送空陣列不寫版本');

// 拒絕的輸入（每一項都不得寫版本）
$v = versions();
$bad = [
    ['壞 MIME（photo 送文字檔）', [['kind' => 'photo', 'file' => 'media_0']], ['media_0' => ['x.png', 'not an image']], 415],
    ['壞 MIME（audio 送圖片）', [['kind' => 'audio', 'file' => 'media_0']], ['media_0' => ['x.wav', $png]], 415],
    ['photo 缺檔', [['kind' => 'photo']], [], 400],
    ['photo 指向不存在的欄位', [['kind' => 'photo', 'file' => 'media_9']], [], 400],
    ['audio 缺檔', [['kind' => 'audio']], [], 400],
    ['欄位名不合格式', [['kind' => 'photo', 'file' => 'photo']], ['photo' => ['x.png', $png]], 400],
    ['text 沒有 comment', [['kind' => 'text']], [], 400],
    ['text 空白 comment', [['kind' => 'text', 'comment' => '   ']], [], 400],
    ['不可寫入的 kind（video）', [['kind' => 'video', 'file' => 'media_0']], ['media_0' => ['x.png', $png]], 400],
    ['不可寫入的 kind（spot）', [['kind' => 'spot']], [], 400],
    ['未知 id', [['id' => 'nope', 'comment' => 'x']], [], 400],
    ['blocks 不是陣列', 'oops', [], 400],
];
foreach ($bad as [$label, $blocks, $files, $want]) {
    [$c, $r] = save($blocks, $rev5, $files);
    ck($c === $want, $label . ' 回 ' . $want, [$c, $r]);
}
// 重複 id 需要先有區塊
[$c, $r] = save([['kind' => 'text', 'comment' => 'dup']], $rev5);
$rev6 = $r['item']['content_rev'] ?? '';
$did = $r['item']['content'][0]['id'] ?? 'x';
[$c, $r] = save([['id' => $did, 'comment' => 'a'], ['id' => $did, 'comment' => 'b']], $rev6);
ck($c === 400, '重複 id 回 400', [$c, $r]);
ck(versions() === $v + 1, '被拒絕的輸入都沒有寫版本（只有 dup 那一筆）', [versions(), $v]);
[$c, $r] = save([['kind' => 'text', 'comment' => 'x']], $rev6, [], ['fields' => ['item_num' => '99']]);
ck($c === 404, '不存在的點位回 404', [$c, $r]);

// 超過上限：整個請求超過 post_max_size 與單檔超過 upload_max_filesize 都要回明確的 413
$v = versions();
[$c, $r] = save([['kind' => 'photo', 'file' => 'media_0']], $rev6, ['media_0' => ['big.png', str_repeat('x', 300 * 1024)]]);
ck($c === 413 && ($r['code'] ?? '') === 'too_large' && strpos((string)($r['error'] ?? ''), 'MB') !== false, '請求超過 post_max_size 回 413', [$c, $r]);
[$c, $r] = save([['kind' => 'photo', 'file' => 'media_0']], $rev6, ['media_0' => ['big.png', str_repeat('x', 160 * 1024)]]);
ck($c === 413, 'photo 單檔超過 upload_max_filesize 回 413', [$c, $r]);
[$c, $r] = save([['kind' => 'audio', 'file' => 'media_0']], $rev6, ['media_0' => ['big.wav', str_repeat('x', 160 * 1024)]]);
ck($c === 413, 'audio 單檔超過 upload_max_filesize 回 413', [$c, $r]);
ck(versions() === $v, '超過上限的請求不寫版本', [versions(), $v]);

echo "\n";
if ($fails) {
    foreach ($fails as $f) echo "FAIL $f\n";
    echo "\ncontentcheck：$n 項中 " . count($fails) . " 項失敗\n";
    exit(1);
}
echo "contentcheck：全部通過（$n 項）\n";
