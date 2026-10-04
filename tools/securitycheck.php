<?php
/** CLI 安全回歸：署名 URL／HTML 與限次碼並行消耗，僅使用臨時 fixture。 */
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../api/layers.php';
require_once __DIR__ . '/../api/i18n.php';
require_once __DIR__ . '/../api/security.php';
if (($argv[1] ?? '') === '--consume') {
    $cfg = ['projects_dir' => $argv[2], 'state_dir' => $argv[2] . '/state'];
    echo code_check($cfg, 'test', '123456', true) ? '1' : '0';
    exit;
}
if (($argv[1] ?? '') === '--guess') {
    $cfg = ['projects_dir' => $argv[2], 'state_dir' => $argv[2] . '/state', 'rate_limits' => ['codefail' => ['max' => 3, 'window' => 60]]];
    echo code_check($cfg, 'test', $argv[3], false) ? 'ok' : 'no';
    exit;
}
function security_ck(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
}
foreach (['javascript:alert(1)', 'data:text/html,x', '//evil.test', ' https://example.test', "https://example.test\n"] as $url) {
    security_ck(!str_contains(souliong_credit_html(['text' => 'url', 'url' => $url], []), '<a '), "拒絕 URL $url");
}
$html = souliong_credit_html(['text' => '<img src=x>', 'suffix' => '<svg onload=alert(1)>', 'url' => 'https://example.test'], []);
security_ck(!str_contains($html, '<img') && !str_contains($html, '<svg') && str_contains($html, '<a href="https://example.test"'), '安全署名與正常連結');
$tmp = sys_get_temp_dir() . '/souliong_security_' . bin2hex(random_bytes(6));
mkdir($tmp . '/test', 0777, true);
try {
    file_put_contents($tmp . '/test/codes.json', json_encode([['code'=>'123456','max_uses'=>1,'used_count'=>0]]));
    $jobs = [];
    for ($i = 0; $i < 24; $i++) {
        $p = proc_open([PHP_BINARY, __FILE__, '--consume', $tmp], [1=>['pipe','w'],2=>['pipe','w']], $pipes);
        security_ck(is_resource($p), '啟動並行子行程');
        $jobs[] = [$p, $pipes];
    }
    $accepted = 0;
    foreach ($jobs as [$p, $pipes]) {
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        security_ck(proc_close($p) === 0 && $err === '', '子行程成功：' . $err);
        security_ck(in_array($out, ['0','1'], true), '子行程輸出');
        $accepted += (int)$out;
    }
    $rows = json_decode(file_get_contents($tmp . '/test/codes.json'), true);
    security_ck($accepted === 1 && $rows[0]['used_count'] === 1, '24 個並行請求只允許 1 次');
    $guess = function (string $c) use ($tmp): string {
        return (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --guess ' . escapeshellarg($tmp) . ' ' . escapeshellarg($c) . ' 2>&1');
    };
    file_put_contents($tmp . '/test/codes.json', json_encode([['code' => '111111']]));
    security_ck($guess('111111') === 'ok', '額度內正確碼通過');
    for ($i = 0; $i < 3; $i++) security_ck($guess('000000') === 'no', '猜錯回 no');
    $after = $guess('111111');
    security_ck(str_contains($after, '請求過於頻繁') && !str_contains($after, 'ok'), '猜錯額度用完後正確碼也被擋');
    echo "securitycheck：署名安全、24 個並行限次碼與猜碼額度通過\n";
} finally {
    foreach (glob($tmp . '/state/.rate/*') ?: [] as $f) unlink($f);
    @rmdir($tmp . '/state/.rate'); @rmdir($tmp . '/state');
    unlink($tmp . '/test/codes.json'); rmdir($tmp . '/test'); rmdir($tmp);
}
