<?php
/**
 * 登入工作階段與一次性 token 的檢查（CLI）：工作階段可撤銷、token 只存雜湊、舊明文資料會自動搬遷。
 * 在臨時沙盒跑，不碰真實的 state/。
 */
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/security.php';
require_once $root . '/api/accounts.php';

$fails = [];
$n = 0;
function sc_ck(bool $ok, string $what): void {
    global $fails, $n;
    $n++;
    if (!$ok) $fails[] = $what;
}
function sc_sandbox(array $extra = []): array {
    $sd = strtr(sys_get_temp_dir(), [chr(92) => '/']) . '/sesscheck_' . bin2hex(random_bytes(4));
    mkdir($sd, 0777, true);
    register_shutdown_function(function () use ($sd) {
        foreach (glob($sd . '/*') ?: [] as $f) @unlink($f);
        @rmdir($sd);
    });
    return $extra + ['state_dir' => $sd, 'projects_dir' => $sd . '/p', 'ip_salt' => 'sesscheck-salt', 'primary_pin' => 'abcdef'];
}
set_error_handler(fn($no, $str) => $no === E_WARNING && str_contains($str, 'headers already sent'));

if (($argv[1] ?? '') === '--legacy') {
    $cfg = sc_sandbox();
    file_put_contents(pins_file($cfg), json_encode(['primary' => [], 'projects' => ['proj' => [['kind' => 'invite', 'id' => 'i1', 'token' => 'legacytok']]]]));
    file_put_contents(accounts_file($cfg), json_encode(['accounts' => [], 'pending' => [['token' => 'legacymig', 'source' => 'primary', 'expires_at' => '2999-01-01T00:00:00+00:00', 'used' => false]]]));
    sc_ck(invite_find($cfg, 'proj', 'legacytok') !== null, '舊的明文邀請 token 仍可使用');
    sc_ck(!str_contains((string)file_get_contents(pins_file($cfg)), 'legacytok'), '舊的明文邀請 token 載入後已改存雜湊');
    sc_ck(account_migrate_find($cfg, 'legacymig') !== null, '舊的明文轉換 token 仍可使用');
    sc_ck(!str_contains((string)file_get_contents(accounts_file($cfg)), 'legacymig'), '舊的明文轉換 token 載入後已改存雜湊');
    echo json_encode(['n' => $n, 'fails' => $fails]);
    exit(0);
}

$cfg = sc_sandbox();
$pins = ['primary' => [['pin_hash' => pin_hash_of($cfg, '246810'), 'label' => '', 'id' => 'p1']], 'projects' => []];
pins_save($cfg, $pins);

sc_ck(primary_pin_ref($cfg, 'abcdef') === 'cfg', '設定檔 PIN 對應 cfg');
sc_ck(primary_pin_ref($cfg, '246810') === 'pin:p1', '清單 PIN 對應 pin:<id>');
sc_ck(primary_pin_ref($cfg, '000000') === null, '錯的 PIN 不對應任何憑證');

$tok = primary_session_issue($cfg, 'pin:p1');
$_COOKIE[PRIMARY_COOKIE] = $tok;
sc_ck(primary_authed($cfg), '有效 token 通過');
sc_ck(primary_csrf($cfg) === primary_csrf_for_token($cfg, $tok), 'CSRF 綁登入 token');
sc_ck(!str_contains((string)file_get_contents(sessions_file($cfg)), $tok), 'token 不以明文落地');
$tok2 = primary_session_issue($cfg, 'pin:p1');
sc_ck(primary_csrf_for_token($cfg, $tok2) !== primary_csrf_for_token($cfg, $tok), '每個登入的 CSRF 不同');
$_COOKIE[PRIMARY_COOKIE] = 'x' . $tok;
sc_ck(!primary_authed($cfg), '錯的 token 不通過');

$_COOKIE[PRIMARY_COOKIE] = $tok;
$pins['primary'] = [];
pins_save($cfg, $pins);
sc_ck(!primary_authed($cfg), '移除該組 PIN 後，用它登入的工作階段立即失效');

$_COOKIE[PRIMARY_COOKIE] = primary_session_issue($cfg, 'cfg');
sc_ck(primary_authed($cfg), '設定檔 PIN 的工作階段通過');
$rot = ['primary_pin' => 'zzzzzz'] + $cfg;
sc_ck(!primary_authed($rot), '更換設定檔 PIN 後，既有工作階段失效');
primary_clear_cookie($cfg);
sc_ck(!primary_authed($cfg), '登出後工作階段失效');

_sessions_update($cfg, fn($r) => [['h' => hash('sha256', 'old'), 'via' => 'cfg', 'fp' => _cfg_pin_fingerprint($cfg), 'exp' => time() - 5]]);
$_COOKIE[PRIMARY_COOKIE] = 'old';
sc_ck(!primary_authed($cfg), '過期的工作階段不通過');

[$inv] = pins_invite_create($cfg, 'proj', null, null);
sc_ck(invite_find($cfg, 'proj', $inv) !== null, '邀請 token 可查到');
sc_ck(invite_find($cfg, 'proj', 'nope') === null, '錯的邀請 token 查不到');
sc_ck(!str_contains((string)file_get_contents(pins_file($cfg)), $inv), '邀請 token 不以明文落地');
$m = account_migrate_create($cfg, 'primary', null, 'p1', 'amy', '');
sc_ck(account_migrate_find($cfg, $m['token']) !== null, '轉換 token 可查到');
sc_ck(!str_contains((string)file_get_contents(accounts_file($cfg)), $m['token']), '轉換 token 不以明文落地');

// 快取是行程內的，舊資料搬遷要在乾淨行程裡驗
$out = json_decode((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --legacy'), true);
sc_ck(is_array($out), '舊資料搬遷子行程有回應');
if (is_array($out)) { $n += $out['n']; $fails = array_merge($fails, $out['fails']); }

foreach ($fails as $f) echo "FAIL $f\n";
echo $fails ? "\nsessioncheck：" . count($fails) . " 項失敗（共 $n 項）\n" : "sessioncheck：全部通過（$n 項）\n";
exit($fails ? 1 : 0);
