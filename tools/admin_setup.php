<?php
// 設定管理者登入（CLI only）。秘密一律從終端機輸入，不經參數與指令歷史，只存雜湊於 state/。
//   php tools/admin_setup.php status
//   php tools/admin_setup.php pin
//   php tools/admin_setup.php account
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
require_once __DIR__ . '/../api/security.php';
require_once __DIR__ . '/../api/accounts.php';

$cfgFile = __DIR__ . '/../api/config.php';
$cfg = is_file($cfgFile) ? (require $cfgFile) : [];
if (empty($cfg['state_dir'])) $cfg['state_dir'] = __DIR__ . '/../state';
$cmd = $argv[1] ?? '';

function as_ask(string $label, bool $secret = false): string {
    $tty = DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec') && stream_isatty(STDIN);
    fwrite(STDERR, $label . ': ');
    if ($secret && $tty) shell_exec('stty -echo');
    $v = trim((string)fgets(STDIN));
    if ($secret && $tty) { shell_exec('stty echo'); fwrite(STDERR, "\n"); }
    return $v;
}
function as_secret_twice(string $label): string {
    $a = as_ask($label, true);
    $b = as_ask('再輸入一次', true);
    if ($a === '' || $a !== $b) { fwrite(STDERR, "兩次輸入不一致或為空\n"); exit(1); }
    return $a;
}
function as_has_login(array $cfg): bool {
    if (_cfg_primary_pin($cfg) !== '' && _cfg_primary_pin($cfg) !== 'CHANGE-ME') return true;
    if (!empty($cfg['primary_pin_hash'])) return true;
    if (pins_load($cfg)['primary']) return true;
    foreach (accounts_load($cfg)['accounts'] as $a) { if (($a['role'] ?? '') === 'primary' && empty($a['disabled'])) return true; }
    return false;
}

if ($cmd === 'status') exit(as_has_login($cfg) ? 0 : 3);

if (empty($cfg['ip_salt']) || str_starts_with((string)$cfg['ip_salt'], 'CHANGE-ME')) {
    fwrite(STDERR, "請先在 api/config.php 設定 ip_salt，PIN 的雜湊會用到它\n");
    exit(1);
}
if (!is_dir($cfg['state_dir'])) { fwrite(STDERR, "找不到 state/\n"); exit(1); }

if ($cmd === 'pin') {
    $pin = as_secret_twice('新的主要 PIN（至少 6 碼）');
    $label = as_ask('暱稱（可留空）');
    if (!pin_length_ok($pin)) { fwrite(STDERR, 'PIN 至少 ' . PIN_MIN_LEN . " 碼
"); exit(1); }
    $d = pins_load($cfg);
    if (_pin_in($cfg, $d['primary'], $pin)) { fwrite(STDERR, "這組 PIN 已存在\n"); exit(1); }
    $d['primary'][] = ['pin_hash' => pin_hash_of($cfg, $pin), 'label' => substr($label, 0, 80), 'id' => bin2hex(random_bytes(4))];
    pins_save($cfg, $d);
    echo "已新增主要 PIN\n";
    exit(0);
}
if ($cmd === 'account') {
    $userid = account_userid_normalize(as_ask('帳號（英數字與 _ - . @ +，可用電子郵件）'));
    if ($userid === '') { fwrite(STDERR, "帳號格式不符\n"); exit(1); }
    if (account_find_by_userid($cfg, $userid) !== null) { fwrite(STDERR, "帳號已存在\n"); exit(1); }
    $pw = as_secret_twice('密碼（至少 8 字元）');
    $label = as_ask('顯示名稱（可留空）');
    $r = account_register($cfg, $userid, $pw, $label);
    if (!$r['ok']) { fwrite(STDERR, "無法建立：{$r['error']}\n"); exit(1); }
    $d = accounts_load($cfg);
    foreach ($d['accounts'] as &$a) { if ($a['id'] === $r['account']['id']) $a['role'] = 'primary'; }
    unset($a);
    accounts_save($cfg, $d);
    echo "已建立主要帳號 {$userid}\n";
    exit(0);
}
fwrite(STDERR, "usage: php admin_setup.php status | pin | account\n");
exit(1);
