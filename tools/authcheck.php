<?php
/**
 * 權限單一化的行為測試（CLI）：身分 x 權限鍵 x CSRF 表格，加上端點接線。
 *
 * 用法：php tools/authcheck.php      全部通過結束碼 0，有任何一項失敗為 1。
 *
 * 全程在臨時沙盒跑（sys_get_temp_dir()/authcheck_*，結束時清掉），不碰真正的 state/ 與 projects/：
 *   - 沙盒內有 alpha／beta／gamma／delta 四個專案、17 個身分情境（含同時帶帳號與專案 PIN cookie、
 *     邀請型 PIN、已撤銷 PIN、簽章錯誤、跨專案偽造、停用帳號、舊鍵名 perms）。
 *   - 行程內：註冊表、預設與回填、Actor 的 kind／audit／can／csrf／isMember、Contributor。
 *   - 子行程（因為 Auth::require／contrib_gate 失敗會 exit）：Auth::require 逐情境逐鍵逐 CSRF 型態，
 *     以及 editspot／spotcontent／upload／newspot 四支端點的實際回應（沙盒內的 api/ 副本，逐字複製）。
 *   - view.php：對真正的 _packdemo 頁面在各情境下輸出 APP.actor／perms／csrf，並確認 APP.csrf
 *     餵給 Auth::require 會被接受；身分資料以預先植入快取的方式提供，不寫真實 state。
 */
error_reporting(E_ALL);
$root = dirname(__DIR__);

// ── 註冊表與情境資料（行程內與子行程共用）──────────────────────────────

function fx_data(string $A, string $B): array {
    $none = array_fill_keys(auth_perm_keys('project'), false);
    $on = fn(array $keys) => array_merge($none, array_fill_keys($keys, true));
    $acct = fn(string $id, string $role = 'member', bool $off = false) => ['id' => $id, 'userid' => $id, 'role' => $role, 'label' => $id] + ($off ? ['disabled' => true] : []);
    return [
        'accounts' => [$acct('a_prim', 'primary'), $acct('a_mem'), $acct('a_out'), $acct('a_off', 'member', true), $acct('a_both'), $acct('a_leg')],
        'pins' => [
            $A => [
                ['id' => 'p_spot',   'kind' => 'pin',    'perms' => $on(['edit_spots', 'bypass_code'])],
                ['id' => 'p_none',   'kind' => 'pin',    'perms' => $none],
                ['id' => 'p_inv',    'kind' => 'invite', 'perms' => $on(['edit_spots', 'bypass_code'])],
                ['id' => 'p_legacy', 'kind' => 'pin',    'perms' => ['edit_points' => true, 'delegate_admin' => false, 'delete_others' => false]],
            ],
            $B => [['id' => 'p_beta', 'kind' => 'pin', 'perms' => $on(['edit_spots'])]],
        ],
        'members' => [
            $A => [
                ['account_id' => 'a_mem',  'perms' => $on(['edit_spots', 'export_backup'])],
                ['account_id' => 'a_off',  'perms' => $on(['edit_spots'])],
                ['account_id' => 'a_both', 'perms' => $on(['edit_meta'])],
                ['account_id' => 'a_leg',  'perms' => ['edit_points' => true]],
            ],
            $B => [['account_id' => 'a_out', 'perms' => $on(['edit_meta'])]],
        ],
    ];
}

/** 每個情境：要帶的 cookie，以及獨立於 Auth 實作、直接由設計文件推出的期望值（alpha／beta／全站）。 */
function fx_scenarios(array $cfg, string $A, string $B): array {
    $ALL = auth_perm_keys('project');
    $ks = fn(array $on) => array_values(array_filter($ALL, fn($k) => in_array($k, $on, true)));
    $prim = [PRIMARY_COOKIE => primary_derived($cfg)];
    $acct = fn(string $id, ?string $sig = null) => [ACCOUNT_COOKIE => $id . '.' . ($sig ?? account_derived($cfg, $id))];
    $pin  = fn(string $p, string $id, ?string $sig = null) => [pin_cookie_name($p) => $id . '.' . ($sig ?? pin_derived($cfg, $p, $id))];
    $anon    = ['kind' => 'anon', 'audit' => 'anon', 'perms' => [], 'member' => false, 'csrf' => null];
    $primary = ['kind' => 'primary', 'audit' => 'primary', 'perms' => $ALL, 'member' => true, 'csrf' => primary_derived($cfg)];
    $account = fn(string $id, array $on) => ['kind' => 'account', 'audit' => 'acct:' . $id, 'perms' => $ks($on), 'member' => true, 'csrf' => account_derived($cfg, $id)];
    $pinE    = fn(string $p, string $id, array $on) => ['kind' => 'pin', 'audit' => 'pin:' . $id, 'perms' => $ks($on), 'member' => true, 'csrf' => pin_derived($cfg, $p, $id)];
    $siteAnon = ['kind' => 'anon', 'csrf' => null];
    $sitePrim = ['kind' => 'primary', 'csrf' => primary_derived($cfg)];
    $siteAcct = fn(string $id) => ['kind' => 'account', 'csrf' => account_derived($cfg, $id)];
    $legacyOn = ['edit_spots', 'edit_meta', 'edit_layers', 'manage_contrib', 'export_backup', 'bypass_code'];   // 舊鍵搬遷 + 回填後的樣子
    $sc = fn(array $cookies, array $a, array $b, array $site) => ['cookies' => $cookies, 'A' => $a, 'B' => $b, 'site' => $site];
    return [
        'anon'              => $sc([], $anon, $anon, $siteAnon),
        'primary_cookie'    => $sc($prim, $primary, $primary, $sitePrim),
        'primary_account'   => $sc($acct('a_prim'), $primary, $primary, $sitePrim),
        'primary_and_pin'   => $sc($prim + $pin($A, 'p_spot'), $primary, $primary, $sitePrim),
        'acct_member'       => $sc($acct('a_mem'), $account('a_mem', ['edit_spots', 'export_backup']), $anon, $siteAcct('a_mem')),
        'acct_outsider'     => $sc($acct('a_out'), $anon, $account('a_out', ['edit_meta']), $siteAcct('a_out')),
        'acct_disabled'     => $sc($acct('a_off'), $anon, $anon, $siteAnon),
        'acct_badsig'       => $sc($acct('a_mem', 'deadbeef'), $anon, $anon, $siteAnon),
        'acct_legacy'       => $sc($acct('a_leg'), $account('a_leg', $legacyOn), $anon, $siteAcct('a_leg')),
        'pin'               => $sc($pin($A, 'p_spot'), $pinE($A, 'p_spot', ['edit_spots', 'bypass_code']), $anon, $siteAnon),
        'pin_none'          => $sc($pin($A, 'p_none'), $pinE($A, 'p_none', []), $anon, $siteAnon),
        'pin_invite'        => $sc($pin($A, 'p_inv'), $anon, $anon, $siteAnon),
        'pin_revoked'       => $sc($pin($A, 'p_gone'), $anon, $anon, $siteAnon),
        'pin_badsig'        => $sc($pin($A, 'p_spot', '00'), $anon, $anon, $siteAnon),
        'pin_cross_project' => $sc([pin_cookie_name($A) => 'p_beta.' . pin_derived($cfg, $B, 'p_beta')], $anon, $anon, $siteAnon),
        'pin_legacy'        => $sc($pin($A, 'p_legacy'), $pinE($A, 'p_legacy', $legacyOn), $anon, $siteAnon),
        'both_account_wins' => $sc($acct('a_both') + $pin($A, 'p_spot'), $account('a_both', ['edit_meta']), $anon, $siteAcct('a_both')),
        'both_outsider_pin' => $sc($acct('a_out') + $pin($A, 'p_spot'), $pinE($A, 'p_spot', ['edit_spots', 'bypass_code']), $account('a_out', ['edit_meta']), $siteAcct('a_out')),
    ];
}

function fx_can(array $e, string $key): bool {
    return isset(auth_registry()[$key]) && ($e['kind'] === 'primary' || in_array($key, $e['perms'], true));
}

/** 把情境資料預先植入三份快取（讀取時會先套 auth_perms_migrate，等同載入後的樣子），之後任何載入都不碰磁碟。 */
function fx_seed(string $A, string $B, array $data): void {
    $pins = ['primary' => [], 'projects' => []];
    foreach ($data['pins'] as $p => $list) {
        foreach ($list as $e) { auth_perms_migrate($e['perms']); $pins['projects'][$p][] = $e; }
    }
    _pins_cache($pins);
    _accounts_cache(['accounts' => $data['accounts'], 'pending' => []]);
    foreach ($data['members'] as $p => $list) {
        foreach ($list as &$m) { auth_perms_migrate($m['perms']); }
        unset($m);
        _project_perms_cache($p, ['members' => $list]);
    }
}

// ── 子行程 ──────────────────────────────────────────────────────

function ac_child_boot(): void {
    ini_set('display_errors', '1');
    set_error_handler(function ($no, $str, $file, $line) {
        if (!(error_reporting() & $no) || !in_array($no, [E_WARNING, E_NOTICE, E_USER_WARNING, E_USER_NOTICE, E_USER_ERROR], true)) return false;
        throw new ErrorException($str . ' @' . basename($file) . ':' . $line, 0, $no, $file, $line);
    });
}
function ac_child_sandbox(string $sb): array {
    $cfg = json_decode((string)file_get_contents($sb . '/cfg.json'), true);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    require_once $sb . '/api/security.php';
    return $cfg;
}
function ac_csrf_value(array $sc, string $mode, ?string $project = 'alpha'): ?string {
    $right = $project === null ? $sc['site']['csrf'] : $sc['A']['csrf'];
    return match ($mode) { 'right' => (string)$right, 'wrong' => 'not-the-token', default => null };
}

if (($argv[1] ?? '') === '--child') {
    ac_child_boot();
    $p = json_decode((string)base64_decode($argv[3] ?? ''), true);
    if ($argv[2] === 'require') {
        $cfg = ac_child_sandbox($p['sb']);
        $sc = fx_scenarios($cfg, 'alpha', 'beta')[$p['scenario']];
        $_COOKIE = $sc['cookies'];
        $csrf = ac_csrf_value($sc, $p['csrf'], $p['project']);
        if ($csrf !== null) $_POST['csrf'] = $csrf;
        $actor = Auth::require($cfg, $p['project'], $p['key'], true, 'DENY_PERM');
        echo json_encode(['result' => 'pass', 'kind' => $actor->kind()]), "\n";
    } elseif ($argv[2] === 'endpoint') {
        $cfg = ac_child_sandbox($p['sb']);
        $sc = fx_scenarios($cfg, 'alpha', 'beta')[$p['scenario']];
        $_COOKIE = $sc['cookies'];
        $_POST = $p['post'] + ['project' => $p['project']];
        $csrf = ac_csrf_value($sc, $p['csrf']);
        if ($csrf !== null) $_POST['csrf'] = $csrf;
        $_SERVER['REMOTE_ADDR'] = '10.9.' . intdiv($p['n'], 250) . '.' . ($p['n'] % 250 + 1);
        include $p['sb'] . '/api/' . $p['file'];
        echo json_encode(['error' => 'endpoint returned without a response']), "\n";
    } elseif ($argv[2] === 'view') {
        $root = $p['root'];
        require_once $root . '/api/security.php';
        $keepCfg = require $root . '/api/config.php';
        $A = '_packdemo'; $B = '_authcheck_beta';
        $sc = fx_scenarios($keepCfg, $A, $B)[$p['scenario']];
        fx_seed($A, $B, fx_data($A, $B));
        $_COOKIE = $sc['cookies'];
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/souliong/view', 'HTTP_HOST' => 'localhost', 'SCRIPT_NAME' => '/index.php', 'REMOTE_ADDR' => '127.0.0.1'];
        $_GET['p'] = $A;
        ob_start();
        include $root . '/pages/view.php';
        $html = ob_get_clean();
        if (!preg_match('/window\.APP = (\{.*?\}); window\.I18N/s', $html, $m)) { echo json_encode(['error' => 'no APP']), "\n"; exit(1); }
        $app = json_decode($m[1], true);
        $e = $sc['A'];
        ob_start();
        echo json_encode([
            'actor' => ($app['actor'] ?? null) === $e['kind'],
            'perms' => ($app['perms'] ?? null) === $e['perms'],
            'csrf'  => ($app['csrf'] ?? null) === $e['csrf'],
            'member' => ($app['isManager'] ?? null) === $e['member'],
            'edit'  => ($app['canEditSpots'] ?? null) === in_array('edit_spots', $e['perms'], true),
            'fields' => is_array($app['spotFields'] ?? null) && $app['spotFields'] !== [],
        ]), "\n";
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf'] = (string)($app['csrf'] ?? '');
        Auth::require($keepCfg, $A, 'edit_spots', true, 'DENY_PERM');
        echo json_encode(['result' => 'pass']), "\n";
    }
    exit(0);
}

// ── 沙盒 ────────────────────────────────────────────────────────

function ac_rm(string $dir): void {
    if (!is_dir($dir) || !str_starts_with(basename($dir), 'authcheck_')) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($dir);
}
function ac_copy_dir(string $from, string $to): void {
    if (!is_dir($to)) mkdir($to, 0777, true);
    foreach ((array)scandir($from) as $n) {
        if ($n === '.' || $n === '..') continue;
        is_dir("$from/$n") ? ac_copy_dir("$from/$n", "$to/$n") : copy("$from/$n", "$to/$n");
    }
}
function ac_write(string $path, $data): void {
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
    file_put_contents($path, is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

$sb = str_replace('\\', '/', sys_get_temp_dir()) . '/authcheck_' . bin2hex(random_bytes(4));
register_shutdown_function(fn() => ac_rm($sb));
$real = require $root . '/api/config.php';
$cfg = array_merge($real, [
    'ip_salt' => 'authcheck-' . bin2hex(random_bytes(8)), 'primary_pin' => '', 'primary_pin_label' => '',
    'state_dir' => "$sb/state", 'projects_dir' => "$sb/projects",
]);
unset($cfg['admin_pin'], $cfg['admin_pin_label']);
mkdir("$sb/state", 0777, true);
ac_copy_dir($root . '/api', "$sb/api");
ac_copy_dir($root . '/lang', "$sb/lang");
ac_write("$sb/api/config.php", "<?php\nreturn json_decode(file_get_contents(__DIR__ . '/../cfg.json'), true);\n");
ac_write("$sb/cfg.json", $cfg);

require_once $root . '/api/security.php';
require_once $root . '/api/contribgate.php';
set_error_handler(function ($no, $str, $file, $line) {
    if (!(error_reporting() & $no) || !in_array($no, [E_WARNING, E_NOTICE, E_USER_WARNING, E_USER_NOTICE], true)) return false;
    throw new ErrorException($str . ' @' . basename($file) . ':' . $line, 0, $no, $file, $line);
});

$data = fx_data('alpha', 'beta');
ac_write("$sb/state/pins.json", ['primary' => [], 'projects' => $data['pins']]);
ac_write("$sb/state/accounts.json", ['accounts' => $data['accounts'], 'pending' => []]);
foreach (['alpha', 'beta'] as $p) { ac_write("$sb/projects/$p/perms.json", ['members' => $data['members'][$p]]); }
$codeRow = fn(string $c, ?int $max) => ['code' => $c, 'label' => '', 'created' => gmdate('c'), 'expires_at' => null, 'max_uses' => $max, 'used_count' => 0];
ac_write("$sb/projects/alpha/meta.json", ['contrib' => ['newPoint' => 'admin']]);
ac_write("$sb/projects/alpha/codes.json", [$codeRow('111111', null), $codeRow('222222', 1)]);
ac_write("$sb/projects/alpha/blocked.json", ['owners' => [hash('sha256', 'blockedowner')], 'contribs' => []]);
ac_write("$sb/projects/beta/meta.json", []);
ac_write("$sb/projects/gamma/meta.json", ['contrib' => ['newPoint' => 'contributor']]);
ac_write("$sb/projects/gamma/codes.json", [$codeRow('333333', null)]);
ac_write("$sb/projects/delta/meta.json", []);

// ── 檢查登錄 ────────────────────────────────────────────────────

$AC = ['groups' => [], 'fails' => []];
function ck(string $group, bool $ok, string $what): void {
    global $AC;
    $AC['groups'][$group] = $AC['groups'][$group] ?? [0, 0];
    $AC['groups'][$group][1]++;
    if ($ok) { $AC['groups'][$group][0]++; } else { $AC['fails'][] = "[$group] $what"; }
}
function ac_guard(string $group, callable $fn): void {
    try { $fn(); } catch (Throwable $t) { ck($group, false, '例外：' . $t->getMessage()); }
}
function ac_jobs_run(array $jobs, int $par = 8): array {
    $res = []; $running = []; $queue = $jobs;
    while ($queue || $running) {
        while ($queue && count($running) < $par) {
            $k = array_key_first($queue); $params = $queue[$k]; unset($queue[$k]);
            $tmp = tempnam(sys_get_temp_dir(), 'ac');
            $proc = proc_open([PHP_BINARY, __FILE__, '--child', $params['mode'], base64_encode(json_encode($params['p']))],
                [0 => ['pipe', 'r'], 1 => ['file', $tmp, 'w'], 2 => ['redirect', 1]], $pipes);
            fclose($pipes[0]);
            $running[$k] = [$proc, $tmp];
        }
        foreach ($running as $k => [$proc, $tmp]) {
            $st = proc_get_status($proc);
            if ($st['running']) continue;
            $res[$k] = trim((string)file_get_contents($tmp));
            proc_close($proc); @unlink($tmp); unset($running[$k]);
        }
        usleep(4000);
    }
    return $res;
}
function ac_lines(string $out): array {
    $rows = [];
    foreach (preg_split('/
|
|/', $out) as $l) { $d = json_decode(trim($l), true); if (is_array($d)) $rows[] = $d; else if (trim($l) !== '') $rows[] = ['garbage' => mb_substr(trim($l), 0, 160)]; }
    return $rows;
}
function ac_classify(string $out): string {
    $rows = ac_lines($out);
    $last = $rows ? end($rows) : [];
    if (isset($last['garbage'])) return 'crash: ' . mb_substr(preg_replace('/\s+/', ' ', $out), 0, 400);
    if (($last['result'] ?? '') === 'pass') return 'pass';
    $m = (string)($last['error'] ?? '');
    return match (true) {
        $m === 'DENY_PERM' => 'deny_perm',
        str_contains($m, '憑證失效') => 'deny_csrf',
        str_contains($m, '沒有權限'), str_contains($m, '只有管理者') => 'noperm',
        str_contains($m, '停權') => 'blocked',
        str_contains($m, '需要正確的投稿代碼') => 'nocode',
        str_contains($m, '未開放投稿') => 'closed',
        str_contains($m, '沒有開放建立地點') => 'noflag',
        $m === 'bad request', $m === 'bad kind' => 'passed',
        default => 'other: ' . ($m !== '' ? $m : $out),
    };
}

$S = fx_scenarios($cfg, 'alpha', 'beta');
$regKeys = array_keys(auth_registry());
$probeKeys = array_merge($regKeys, ['nope', 'edit_points', 'delegate_admin']);
$projKeys = auth_perm_keys('project');

// 1. 註冊表
ac_guard('registry', function () use ($root, $regKeys, $projKeys) {
    $def = auth_perms_default();
    ck('registry', array_keys($def) === $projKeys && !in_array(true, $def, true), '預設權限表＝專案層級鍵全關');
    ck('registry', $def['bypass_code'] === false, '新建身分 bypass_code 預設 false');
    $pp = auth_perms_primary();
    ck('registry', array_keys($pp) === $regKeys && !in_array(false, $pp, true), 'primary 權限表＝註冊表全鍵全開（含全站鍵）');
    ck('registry', pin_default_perms() === $def && _account_default_perms() === $def && primary_perms() === $pp, '舊函式只是註冊表的薄封裝');
    foreach (auth_registry() as $k => $d) {
        ck('registry', in_array($d['scope'], ['project', 'site'], true) && !isset(auth_registry()[$d['was'] ?? '']), "鍵 $k 的定義形狀");
    }
    $zh = require $root . '/lang/zh_TW.php';
    $en = require $root . '/lang/en.php';
    foreach (auth_registry() as $k => $d) {
        if ($d['scope'] !== 'project') { ck('registry', $d['label'] === null, "全站鍵 $k 沒有後台開關"); continue; }
        ck('registry', isset($zh[$d['label']]) && isset($en[$d['label']]), "鍵 $k 的 lang 標籤 {$d['label']} 在 zh_TW／en 都有");
    }
    $legacy = ['edit_points' => true, 'delegate_admin' => false, 'delete_others' => true, 'edit_meta' => false];
    $dirty = auth_perms_migrate($legacy);
    ck('registry', $dirty && !isset($legacy['edit_points']) && !isset($legacy['delegate_admin']) && $legacy['edit_spots'] === true && $legacy['grant_access'] === false, '舊鍵名搬遷成新鍵名並保留原值');
    ck('registry', $legacy['edit_meta'] === false && $legacy['delete_others'] === true, '已存在的鍵不被回填覆蓋');
    ck('registry', $legacy['edit_layers'] === true && $legacy['manage_contrib'] === true && $legacy['export_backup'] === true && $legacy['bypass_code'] === true, '缺席的 backfill 鍵回填 true（含 bypass_code）');
    ck('registry', !isset($legacy['edit_3d_regions']) && !isset($legacy['delete_others']) === false, '非 backfill 鍵不回填');
    $again = $legacy;
    ck('registry', auth_perms_migrate($again) === false && $again === $legacy, '搬遷可重複執行（冪等）');
    $both = ['edit_points' => true, 'edit_spots' => false];
    auth_perms_migrate($both);
    ck('registry', $both['edit_spots'] === false && !isset($both['edit_points']), '新舊鍵並存時以新鍵為準並移除舊鍵');
});

// 2. 身分解析（行程內）：kind／audit／csrf／isMember／can 全表
$dirty0 = null;
foreach ($S as $name => $sc) {
    ac_guard('actor', function () use ($cfg, $name, $sc, $probeKeys) {
        $_COOKIE = $sc['cookies'];
        Auth::reset();
        foreach (['A' => 'alpha', 'B' => 'beta'] as $slot => $proj) {
            $e = $sc[$slot];
            $a = Auth::actor($cfg, $proj);
            ck('actor', $a->kind() === $e['kind'], "$name/$proj kind＝{$e['kind']}（實際 {$a->kind()}）");
            ck('actor', $a->csrf($proj) === $e['csrf'], "$name/$proj csrf 衍生值");
            ck('actor', $a->isMember($proj) === $e['member'], "$name/$proj isMember");
            ck('actor', $a->grantedKeys($proj) === $e['perms'], "$name/$proj grantedKeys＝" . implode(',', $e['perms']));
            if ($slot === 'A') ck('actor', $a->audit() === $e['audit'], "$name audit＝{$e['audit']}（實際 {$a->audit()}）");
            foreach ($probeKeys as $k) {
                ck('actor', Auth::can($cfg, $proj, $k) === fx_can($e, $k), "$name/$proj can($k)＝" . (fx_can($e, $k) ? 'true' : 'false'));
            }
        }
        $a = Auth::actor($cfg, 'alpha');
        ck('actor', $a->csrf('beta') === $sc['B']['csrf'] && $a->can('beta', 'edit_meta') === fx_can($sc['B'], 'edit_meta'), "$name 以 alpha 綁定的 Actor 問 beta 也回 beta 的答案");
        $site = Auth::actor($cfg, null);
        ck('actor', $site->kind() === $sc['site']['kind'] && $site->csrf(null) === $sc['site']['csrf'], "$name 全站層級 kind／csrf");
        foreach ($probeKeys as $k) {
            $want = $sc['A']['kind'] === 'primary' && isset(auth_registry()[$k]);
            ck('actor', Auth::can($cfg, null, $k) === $want, "$name 全站 can($k)＝" . ($want ? 'true' : 'false'));
        }
    });
}
// 舊資料自我修復已落地到沙盒磁碟
$pinsRaw = (string)file_get_contents("$sb/state/pins.json");
$permsRaw = (string)file_get_contents("$sb/projects/alpha/perms.json");
ck('migrate', !str_contains($pinsRaw, 'edit_points') && !str_contains($pinsRaw, 'delegate_admin'), 'pins.json 載入後舊鍵名已被寫回新鍵名');
ck('migrate', !str_contains($permsRaw, 'edit_points'), 'perms.json 載入後舊鍵名已被寫回新鍵名');
ck('migrate', str_contains($pinsRaw, 'bypass_code') && str_contains($permsRaw, 'bypass_code'), '既有 PIN／帳號授權已回填 bypass_code');
ac_guard('projects', function () use ($cfg, $S) {
    $_COOKIE = $S['primary_cookie']['cookies']; Auth::reset();
    $all = Auth::projectsWith($cfg, 'edit_spots'); sort($all);
    ck('projects', $all === ['alpha', 'beta', 'delta', 'gamma'], 'primary 對所有專案具備 edit_spots');
    $_COOKIE = $S['acct_member']['cookies']; Auth::reset();
    ck('projects', Auth::projectsWith($cfg, 'edit_spots') === ['alpha'], 'acct_member 只在 alpha 具備 edit_spots');
    $_COOKIE = []; Auth::reset();
    ck('projects', Auth::projectsWith($cfg, 'edit_spots') === [], 'anon 沒有任何專案');
});

// 3. Contributor（行程內）
ac_guard('contributor', function () {
    $_POST = ['owner' => '0', 'ctoken' => ''];
    $c = Contributor::fromRequest();
    ck('contributor', $c->ownerHash() === hash('sha256', '0') && $c->contribId() === null && !$c->hasIdentity(), 'owner="0" 仍算有 owner、無 ctoken 沒有穩定身分');
    $_POST = ['owner' => '', 'ctoken' => 'tok'];
    $c = Contributor::fromRequest();
    ck('contributor', $c->ownerHash() === null && $c->contribId() === contrib_id_of('tok') && $c->contribHash() === contrib_hash_of('tok') && $c->hasIdentity(), 'ctoken 衍生 contribId／contribHash');
    $_POST = ['owner' => 'dev1', 'ctoken' => 'tok'];
    $c = Contributor::fromRequest();
    ck('contributor', $c->owns(['owner_hash' => hash('sha256', 'dev1')]) && $c->owns(['contrib_hash' => contrib_hash_of('tok')]), 'owns：owner 或 ctoken 任一相符');
    ck('contributor', !$c->owns(['owner_hash' => hash('sha256', 'other'), 'contrib_hash' => 'x']) && !$c->owns([]), 'owns：都不符或記錄沒有身分欄位為 false');
    $_POST = [];
    ck('contributor', !Contributor::fromRequest()->owns(['owner_hash' => hash('sha256', '')]), 'owns：沒送任何身分不會比對到空字串雜湊');
});

// 4. 接線（靜態）：轉換後的端點必須走單一關卡
foreach (['editspot' => 'Auth::require(', 'spotcontent' => 'Auth::require(', 'newspot' => 'contrib_gate(', 'upload' => 'contrib_gate('] as $f => $needle) {
    $src = (string)file_get_contents("$root/api/$f.php");
    ck('wiring', str_contains($src, $needle), "$f.php 走 $needle 關卡");
}
foreach (['editspot', 'spotcontent'] as $f) {
    ck('wiring', (bool)preg_match("/Auth::require\(\s*\\\$cfg,\s*\\\$project,\s*'edit_spots'/", (string)file_get_contents("$root/api/$f.php")), "$f.php 要求的是 edit_spots");
}

// 5. 子行程：Auth::require 逐情境
$jobs = []; $expect = [];
$add = function (string $id, array $job, $exp) use (&$jobs, &$expect) { $jobs[$id] = $job; $expect[$id] = $exp; };
$reqExp = function (array $sc, ?string $project, string $key, string $mode): string {
    $e = $project === null ? ['kind' => $sc['site']['kind'] === 'primary' ? 'primary' : 'x', 'perms' => [], 'csrf' => $sc['site']['csrf']] : $sc['A'];
    if (!fx_can($e, $key)) return 'deny_perm';
    return ($e['csrf'] !== null && $mode === 'right') ? 'pass' : 'deny_csrf';
};
foreach ($S as $name => $sc) {
    foreach (['right', 'wrong', 'none'] as $mode) {
        $add("req|$name|alpha|edit_spots|$mode", ['mode' => 'require', 'p' => ['sb' => $sb, 'scenario' => $name, 'project' => 'alpha', 'key' => 'edit_spots', 'csrf' => $mode]], $reqExp($sc, 'alpha', 'edit_spots', $mode));
    }
    $add("req|$name|alpha|bypass_code|right", ['mode' => 'require', 'p' => ['sb' => $sb, 'scenario' => $name, 'project' => 'alpha', 'key' => 'bypass_code', 'csrf' => 'right']], $reqExp($sc, 'alpha', 'bypass_code', 'right'));
    $add("req|$name|site|manage_layers|right", ['mode' => 'require', 'p' => ['sb' => $sb, 'scenario' => $name, 'project' => null, 'key' => 'manage_layers', 'csrf' => 'right']], $reqExp($sc, null, 'manage_layers', 'right'));
}
foreach ([['primary_cookie', 'nope'], ['primary_cookie', 'edit_points'], ['pin', 'edit_points']] as [$n, $k]) {
    $add("req|$n|alpha|$k|right", ['mode' => 'require', 'p' => ['sb' => $sb, 'scenario' => $n, 'project' => 'alpha', 'key' => $k, 'csrf' => 'right']], 'deny_perm');
}
$reqRes = ac_jobs_run($jobs);
foreach ($expect as $id => $want) {
    $got = ac_classify($reqRes[$id] ?? '');
    ck('require', $got === $want, "$id 期望 {$want}，實際 {$got}");
}

// 6. 子行程：四支端點（沙盒內的 api/ 副本）
$n = 0;
$ep = function (string $file, string $scenario, string $project, array $post, string $csrf) use (&$n, $sb) {
    return ['mode' => 'endpoint', 'p' => ['sb' => $sb, 'file' => $file, 'scenario' => $scenario, 'project' => $project, 'post' => $post, 'csrf' => $csrf, 'n' => $n++]];
};
$epJobs = []; $epExp = [];
foreach (['editspot', 'spotcontent'] as $file) {
    foreach ($S as $name => $sc) {
        foreach (['right', 'wrong'] as $mode) {
            $id = "$file|$name|$mode";
            $epJobs[$id] = $ep("$file.php", $name, 'alpha', [], $mode);
            $epExp[$id] = !fx_can($sc['A'], 'edit_spots') ? 'noperm' : (($sc['A']['csrf'] !== null && $mode === 'right') ? 'passed' : 'deny_csrf');
        }
    }
}
foreach (['newspot|alpha|anon|right' => 'noperm', 'newspot|alpha|primary_cookie|wrong' => 'deny_csrf', 'newspot|alpha|primary_cookie|right' => 'passed',
          'newspot|alpha|pin|right' => 'passed', 'newspot|alpha|pin|none' => 'deny_csrf', 'newspot|alpha|pin_none|right' => 'noperm',
          'newspot|alpha|both_account_wins|right' => 'noperm', 'newspot|alpha|acct_member|right' => 'passed'] as $id => $want) {
    [, $proj, $scn, $mode] = explode('|', $id);
    $epJobs[$id] = $ep('newspot.php', $scn, $proj, [], $mode);
    $epExp[$id] = $want;
}
$epRes = ac_jobs_run($epJobs);
foreach ($epExp as $id => $want) {
    $got = ac_classify($epRes[$id] ?? '');
    ck('endpoint', $got === $want, "$id 期望 {$want}，實際 {$got}");
}

// 投稿軸（會計次，逐一循序）：upload.php 與 newspot.php contributor 模式共用 contrib_gate()
$bogus = ['kind' => 'bogus'];
$gate = [
    ['upload', 'alpha', 'anon',              $bogus,                                              'nocode',  '匿名無碼'],
    ['upload', 'alpha', 'anon',              $bogus + ['code' => '999999'],                       'nocode',  '匿名錯碼'],
    ['upload', 'alpha', 'anon',              $bogus + ['code' => '111111'],                       'passed',  '匿名有效碼'],
    ['upload', 'alpha', 'anon',              $bogus + ['code' => '222222'],                       'passed',  '匿名限次碼第一次'],
    ['upload', 'alpha', 'anon',              $bogus + ['code' => '222222'],                       'nocode',  '匿名限次碼用罄後失效'],
    ['upload', 'alpha', 'anon',              $bogus + ['code' => '111111', 'owner' => 'blockedowner'], 'blocked', '停權名單先於投稿代碼'],
    ['upload', 'alpha', 'pin',               $bogus,                                              'passed',  '有 bypass_code 的 PIN 免碼'],
    ['upload', 'alpha', 'pin_none',          $bogus,                                              'nocode',  '沒有 bypass_code 的 PIN 仍要碼'],
    ['upload', 'alpha', 'primary_cookie',    $bogus,                                              'passed',  'primary 免碼'],
    ['upload', 'alpha', 'primary_cookie',    $bogus + ['owner' => 'blockedowner'],                'blocked', '停權對 primary 也先生效'],
    ['upload', 'alpha', 'acct_member',       $bogus,                                              'nocode',  '沒有 bypass_code 的帳號要碼'],
    ['upload', 'alpha', 'acct_legacy',       $bogus,                                              'passed',  '舊帳號授權回填 bypass_code 後免碼'],
    ['upload', 'alpha', 'both_account_wins', $bogus,                                              'nocode',  '同帶帳號與 PIN：吃帳號（無 bypass_code），PIN 的 bypass_code 不生效'],
    ['upload', 'alpha', 'pin_invite',        $bogus,                                              'nocode',  '邀請型 PIN 不是身分'],
    ['upload', 'delta', 'anon',              $bogus,                                              'closed',  '沒有有效碼的地圖未開放投稿'],
    ['upload', 'delta', 'primary_cookie',    $bogus,                                              'passed',  'primary 在未開放的地圖也可投稿（bypass_code 先於開放判斷）'],
    ['newspot', 'gamma', 'anon',             [],                                                  'nocode',  '訪客建點無碼'],
    ['newspot', 'gamma', 'anon',             ['code' => '333333'],                                'passed',  '訪客建點有效碼'],
    ['newspot', 'gamma', 'primary_cookie',   [],                                                  'passed',  'contributor 模式 primary 免碼'],
    ['newspot', 'delta', 'anon',             [],                                                  'noflag',  'newPoint 預設 off'],
];
foreach ($gate as $i => [$file, $proj, $scn, $post, $want, $label]) {
    $res = ac_jobs_run(['g' => $ep("$file.php", $scn, $proj, $post, 'none')]);
    $got = ac_classify($res['g'] ?? '');
    ck('gate', $got === $want, "$file/$proj/$scn {$label}：期望 {$want}，實際 {$got}");
}
$codesAfter = json_decode((string)file_get_contents("$sb/projects/alpha/codes.json"), true);
$used = array_column($codesAfter, 'used_count', 'code');
ck('gate', ($used['222222'] ?? -1) === 1, '限次碼只被計一次（第二次是被擋、不是再計）');
$blockedAlpha = json_decode((string)file_get_contents("$sb/projects/alpha/codes.json"), true);

// 7. view.php：APP.actor／perms／csrf 與 Actor 一致，且 APP.csrf 會被 Auth::require 接受
$viewJobs = [];
foreach ($S as $name => $sc) { $viewJobs[$name] = ['mode' => 'view', 'p' => ['root' => $root, 'scenario' => $name]]; }
$viewRes = ac_jobs_run($viewJobs, 6);
foreach ($S as $name => $sc) {
    $rows = ac_lines($viewRes[$name] ?? '');
    $first = $rows[0] ?? [];
    if (isset($first['garbage']) || isset($first['error'])) { ck('view', false, "$name view.php 沒有正常輸出：" . json_encode($first, JSON_UNESCAPED_UNICODE)); continue; }
    foreach (['actor', 'perms', 'csrf', 'member', 'edit', 'fields'] as $f) {
        ck('view', ($first[$f] ?? false) === true, "$name APP.$f 與期望一致");
    }
    $got = ac_classify($viewRes[$name] ?? '');
    $want = fx_can($sc['A'], 'edit_spots') ? 'pass' : 'deny_perm';
    ck('view', $got === $want, "$name 把 APP.csrf 餵給 Auth::require(edit_spots)：期望 {$want}，實際 {$got}");
}

// ── 報表 ────────────────────────────────────────────────────────

echo "身分情境（alpha）：\n";
printf("  %-20s %-8s %-4s %s\n", '情境', 'kind', 'csrf', '權限');
foreach ($S as $name => $sc) {
    $e = $sc['A'];
    printf("  %-20s %-8s %-4s %s\n", $name, $e['kind'], $e['csrf'] === null ? '-' : 'yes', $e['kind'] === 'primary' ? '(全部)' : ($e['perms'] ? implode(',', $e['perms']) : '(無)'));
}
echo "\n";
$total = 0; $okTotal = 0;
foreach ($AC['groups'] as $g => [$ok, $all]) {
    printf("  %-12s %d/%d\n", $g, $ok, $all);
    $total += $all; $okTotal += $ok;
}
foreach ($AC['fails'] as $f) echo "FAIL $f\n";
echo "\nauthcheck：" . ($AC['fails'] ? count($AC['fails']) . " 項失敗（共 $total 項）" : "全部通過（$total 項）") . "\n";
exit($AC['fails'] ? 1 : 0);
