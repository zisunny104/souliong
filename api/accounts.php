<?php
/**
 * 帳號系統（userid + 密碼），疊加在既有 PIN 機制之上、不取代它：
 * - 帳密本體只存一份：state/accounts.json（全域，換密碼不用同步多檔）。
 * - 各專案的授權只存 perms + account_id 參照：projects/<project>/perms.json——
 *   不含密碼等任何機密，專案資料夾單獨備份/搬到別的部署也不會外流帳密；
 *   帳號在新部署的 accounts.json 裡若不存在，這份授權就自動失效（fail closed）。
 * - 舊 PIN 要「轉換為帳號」須先證明持有舊 PIN（見檔尾 account_migrate_*），
 *   帳號建好後舊 PIN 不會被自動撤銷，由 primary 在既有 PIN 管理介面手動清。
 * - primary 帳號視同 primary_authed()：對所有專案永遠通過，這是檔案系統擁有者本來就有
 *   的權限，應用層擋不住蓄意的 primary，因此不做「限制 primary 跨專案」這種安全假象，
 *   改用 audit_log() 留下事後可查的紀錄。
 */
require_once __DIR__ . '/store.php';

// ── state/accounts.json：{accounts:[...], pending:[...]（轉換/邀請 token）} ──
function accounts_file(array $cfg): string { return rtrim($cfg['state_dir'], '/\\') . '/accounts.json'; }
// account_current() 會被逐專案呼叫（例如 manager.php 列出多個
// 專案時），一次請求內用行程內靜態快取避免每個專案都重讀整份 accounts.json；accounts_save()
// 寫入後同步更新快取。
function _accounts_cache(?array $set = null, bool $reset = false): ?array {
    static $cache = null;
    if ($reset) $cache = null;
    if ($set !== null) $cache = $set;
    return $cache;
}
function accounts_load(array $cfg): array {
    $cached = _accounts_cache();
    if ($cached !== null) return $cached;
    $d = is_file(accounts_file($cfg)) ? json_decode((string)@file_get_contents(accounts_file($cfg)), true) : null;
    if (!is_array($d)) $d = [];
    $d['accounts'] = $d['accounts'] ?? [];
    $d['pending']  = $d['pending']  ?? [];
    // 舊資料 role master → primary 改名搬遷、明文 token → token_hash（一次性、自我修復）
    $dirty = false;
    foreach ($d['pending'] as &$pp) {
        if (isset($pp['token'])) { $pp['token_hash'] = hash('sha256', (string)$pp['token']); unset($pp['token']); $dirty = true; }
    }
    unset($pp);
    foreach ($d['accounts'] as &$a) {
        if (($a['role'] ?? '') === 'master') { $a['role'] = 'primary'; $dirty = true; }
    }
    unset($a);
    if ($dirty) accounts_save($cfg, $d);
    return _accounts_cache($d);
}
function accounts_save(array $cfg, array $d): void {
    if (@file_put_contents(accounts_file($cfg), json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
        error_log('souliong: accounts_save 寫入失敗：' . accounts_file($cfg));
    }
    _accounts_cache($d);
}

/** 在檔案鎖內重讀、修改、寫回，並行請求不會互相覆蓋。$fn 收到最新資料的參照，回 ['ok'=>true,…] 或帶 'save'=>true 才寫入。 */
function accounts_mutate(array $cfg, callable $fn): array {
    $lock = @fopen(accounts_file($cfg) . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) { if ($lock) fclose($lock); return ['ok' => false, 'error' => 'busy']; }
    try {
        _accounts_cache(null, true);
        $d = accounts_load($cfg);
        $r = $fn($d);
        if (!empty($r['ok']) || !empty($r['save'])) accounts_save($cfg, $d);
        return $r;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function account_find_by_userid(array $cfg, string $userid): ?array {
    $userid = strtolower(trim($userid));
    foreach (accounts_load($cfg)['accounts'] as $a) { if (strtolower((string)($a['userid'] ?? '')) === $userid) return $a; }
    return null;
}
/** 登入用：先比完整帳號；沒有 @ 的輸入再比電子郵件帳號 @ 前的部分，唯一符合才採用。 */
function account_find_for_login(array $cfg, string $input): ?array {
    $a = account_find_by_userid($cfg, $input);
    if ($a !== null) return $a;
    $in = strtolower(trim($input));
    if ($in === '' || str_contains($in, '@')) return null;
    $hit = null;
    foreach (accounts_load($cfg)['accounts'] as $e) {
        $u = strtolower((string)($e['userid'] ?? ''));
        if (($at = strpos($u, '@')) !== false && substr($u, 0, $at) === $in) {
            if ($hit !== null) return null;
            $hit = $e;
        }
    }
    return $hit;
}
/** 沒填顯示名稱時的預設：電子郵件帳號取 @ 前，其他沿用帳號。 */
function account_default_label(string $userid): string {
    $at = strpos($userid, '@');
    return $at ? substr($userid, 0, $at) : $userid;
}
function account_find_by_id(array $cfg, string $id): ?array {
    foreach (accounts_load($cfg)['accounts'] as $a) { if ((string)($a['id'] ?? '') === $id) return $a; }
    return null;
}
function account_userid_normalize(string $s): string {
    return substr(preg_replace('/[^a-z0-9_.@+-]/', '', strtolower(trim($s))), 0, 80);
}
/** 撞名自動加後綴（xiaoming、xiaoming-2、xiaoming-3…），供轉換流程預填時使用。 */
function account_userid_dedupe(array $cfg, string $base): string {
    $base = account_userid_normalize($base) ?: 'user';
    $u = $base; $n = 1;
    while (account_find_by_userid($cfg, $u) !== null) { $n++; $u = $base . '-' . $n; }
    return $u;
}

// ── 密碼規則：長度優先於複雜度（符合現代建議），不強制符號/大小寫/定期更換 ──
function account_password_blocklist(): array {
    return ['12345678', '123456789', 'password', 'password1', 'qwertyui', '11111111', '00000000', 'iloveyou', 'admin1234', 'letmein1', 'qwerty123', '1qaz2wsx', 'abcd1234'];
}
/** 密碼不合格回傳錯誤代碼字串；合格回傳 null。 */
function account_password_check(string $userid, string $pw): ?string {
    if (strlen($pw) < 8) return 'pw_short';
    if (strtolower($pw) === strtolower($userid)) return 'pw_same_as_userid';
    if (in_array(strtolower($pw), account_password_blocklist(), true)) return 'pw_weak';
    return null;
}

// ── 登入 cookie：簽章綁 account id，事後可歸責（primary 帳號登入也不例外）──
define('ACCOUNT_COOKIE', 'souliong_acct');
function account_derived(array $cfg, string $accountId): string { return hash_hmac('sha256', 'souliong-acct', $accountId . '|' . (string)($cfg['ip_salt'] ?? '')); }
function account_set_cookie(array $cfg, string $accountId): void { setcookie(ACCOUNT_COOKIE, $accountId . '.' . account_derived($cfg, $accountId), _cookie_opts()); }
function account_clear_cookie(): void { setcookie(ACCOUNT_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']); }
/** 解析目前登入的帳號 cookie；未登入、簽章不符、帳號已停用一律回 null。 */
function account_current(array $cfg): ?array {
    $raw = (string)($_COOKIE[ACCOUNT_COOKIE] ?? '');
    $dot = strrpos($raw, '.');
    if ($dot === false) return null;
    $id = substr($raw, 0, $dot);
    $sig = substr($raw, $dot + 1);
    if ($id === '' || !hash_equals(account_derived($cfg, $id), $sig)) return null;
    $a = account_find_by_id($cfg, $id);
    if ($a === null || !empty($a['disabled'])) return null;
    return $a;
}

// ── 登入（含失敗鎖定，userid 常常等於公開暱稱、可預測，靠這個擋暴力破解）──
function _account_default_perms(): array { return auth_perms_default(); }
// 帳號不存在時仍跑一次 password_verify()（比對這組固定的假雜湊），耗時比照真的驗證，
// 避免「帳號不存在」比「帳號存在但密碼錯」明顯更快，讓人用回應時間差猜中哪些 userid 有註冊。
define('ACCOUNT_DUMMY_HASH', '$2y$10$dTLVeAjwEKpp0FwAqcPLUuP/YC2K5g4zJ/yQDyGTjXv8YDV/M9GEm');
/** 回傳 ['ok'=>true,'account'=>...] 或 ['ok'=>false,'error'=>'invalid'|'locked']。 */
function account_login(array $cfg, string $userid, string $pw): array {
    $a = account_find_for_login($cfg, $userid);
    if ($a === null || !empty($a['disabled']) || ($a['password_hash'] ?? '') === '') {
        password_verify($pw, ACCOUNT_DUMMY_HASH);
        return ['ok' => false, 'error' => 'invalid'];
    }
    if (!empty($a['locked_until']) && $a['locked_until'] > time()) return ['ok' => false, 'error' => 'locked'];
    $pwOk = password_verify($pw, (string)$a['password_hash']);
    return accounts_mutate($cfg, function (array &$d) use ($a, $pwOk) {
        foreach ($d['accounts'] as $i => $e) {
            if ((string)($e['id'] ?? '') !== (string)$a['id']) continue;
            if (!$pwOk) {
                $fails = (int)($e['fail_count'] ?? 0) + 1;
                $d['accounts'][$i]['fail_count'] = $fails;
                if ($fails >= 8) $d['accounts'][$i]['locked_until'] = time() + 900;   // 8 次失敗鎖 15 分鐘
                return ['ok' => false, 'error' => 'invalid', 'save' => true];
            }
            $d['accounts'][$i]['fail_count'] = 0;
            $d['accounts'][$i]['locked_until'] = null;
            return ['ok' => true, 'account' => $d['accounts'][$i]];
        }
        return ['ok' => false, 'error' => 'invalid'];
    });
}

// ── 自助註冊（僅 souliong_registration_open($cfg) 開啟時允許；新帳號預設無任何專案權限，
//    要由 primary 在專案授權清單裡加進去才生效，就算誤開註冊也不會憑空多出管理權限）──
function account_register(array $cfg, string $userid, string $pw, string $label): array {
    $userid = account_userid_normalize($userid);
    if ($userid === '') return ['ok' => false, 'error' => 'userid_invalid'];
    if (account_find_by_userid($cfg, $userid) !== null) return ['ok' => false, 'error' => 'userid_taken'];
    if (($err = account_password_check($userid, $pw)) !== null) return ['ok' => false, 'error' => $err];
    $d = accounts_load($cfg);
    $acc = [
        'id' => 'acc_' . bin2hex(random_bytes(6)),
        'userid' => $userid,
        'password_hash' => password_hash($pw, PASSWORD_DEFAULT),
        'label' => substr(trim($label) !== '' ? trim($label) : account_default_label($userid), 0, 80),
        'role' => 'user',
        'created_at' => gmdate('c'),
        'disabled' => false,
        'fail_count' => 0,
        'locked_until' => null,
    ];
    $d['accounts'][] = $acc;
    accounts_save($cfg, $d);
    return ['ok' => true, 'account' => $acc];
}

// ── 各專案授權：projects/<project>/perms.json（只含 account_id + perms，無機密）──
function project_perms_file(array $cfg, string $project): string {
    $dir = project_dir($cfg, $project);
    return $dir . '/perms.json';
}
// 權限檢查對同一專案在單次請求內常被呼叫多次（例如 manager.php 逐一渲染多項權限旗標），
// 依專案 id 分開快取，避免重複讀檔／解碼同一份 perms.json；project_perms_save() 寫入後同步更新。
function _project_perms_cache(string $project, ?array $set = null): ?array {
    static $cache = [];
    if ($set !== null) $cache[$project] = $set;
    return $cache[$project] ?? null;
}
function project_perms_load(array $cfg, string $project): array {
    $cached = _project_perms_cache($project);
    if ($cached !== null) return $cached;
    $f = project_perms_file($cfg, $project);
    $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    $d = is_array($d) ? $d : [];
    $d['members'] = $d['members'] ?? [];
    // 舊鍵名搬遷與缺鍵回填的規則都在註冊表（api/auth.php 的 auth_perms_migrate()），跟 pins_load() 共用
    $dirty = false;
    foreach ($d['members'] as &$m) {
        if (isset($m['perms']) && is_array($m['perms']) && auth_perms_migrate($m['perms'])) $dirty = true;
    }
    unset($m);
    if ($dirty) project_perms_save($cfg, $project, $d);
    return _project_perms_cache($project, $d);
}
function project_perms_save(array $cfg, string $project, array $d): void {
    if (@file_put_contents(project_perms_file($cfg, $project), json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
        error_log('souliong: project_perms_save 寫入失敗：' . project_perms_file($cfg, $project));
    }
    _project_perms_cache($project, $d);
}
/** 該帳號在此專案的權限；未被授權回 null（跟「有授權但全關」不同，$viewProjects 用這個判斷看不看得到）。 */
function project_account_perms(array $cfg, string $project, string $accountId): ?array {
    foreach (project_perms_load($cfg, $project)['members'] as $m) { if ((string)($m['account_id'] ?? '') === $accountId) return $m['perms'] ?? _account_default_perms(); }
    return null;
}
function project_account_set(array $cfg, string $project, string $accountId, array $perms): void {
    $d = project_perms_load($cfg, $project);
    $found = false;
    foreach ($d['members'] as &$m) { if ((string)($m['account_id'] ?? '') === $accountId) { $m['perms'] = $perms; $found = true; break; } }
    unset($m);
    if (!$found) $d['members'][] = ['account_id' => $accountId, 'perms' => $perms, 'added_at' => gmdate('c')];
    project_perms_save($cfg, $project, $d);
}
function project_account_remove(array $cfg, string $project, string $accountId): void {
    $d = project_perms_load($cfg, $project);
    $d['members'] = array_values(array_filter($d['members'], fn($m) => (string)($m['account_id'] ?? '') !== $accountId));
    project_perms_save($cfg, $project, $d);
}
/** 此帳號有權限的專案清單；primary 角色不會用到（對所有專案永遠通過）。 */
function account_project_list(array $cfg, string $accountId): array {
    $out = [];
    foreach (store_projects($cfg) as $p) { if (project_account_perms($cfg, $p, $accountId) !== null) $out[] = $p; }
    return $out;
}

// ── 帳號停用／啟用：只切 disabled 旗標，不刪帳號、不動各專案 perms.json，重新啟用即恢復原權限 ──
// primary 角色不能從這裡停用：避免 primary 把自己（或唯一的主帳號）鎖在門外；要停用請直接改 accounts.json。
/** 實際改到才回 true；找不到、狀態本來就相同、或要停用的是 primary 一律回 false。 */
function _account_set_disabled(array $cfg, string $accountId, bool $disabled): bool {
    $d = accounts_load($cfg);
    foreach ($d['accounts'] as $i => $e) {
        if ((string)($e['id'] ?? '') !== $accountId) continue;
        if ($disabled && ($e['role'] ?? '') === 'primary') return false;
        if (!empty($e['disabled']) === $disabled) return false;
        $d['accounts'][$i]['disabled'] = $disabled;
        accounts_save($cfg, $d);
        return true;
    }
    return false;
}
function account_disable(array $cfg, string $accountId): bool { return _account_set_disabled($cfg, $accountId, true); }
function account_enable(array $cfg, string $accountId): bool { return _account_set_disabled($cfg, $accountId, false); }
/** 全站帳號一覽（含從未被授權到任何專案的），每筆附 projects；不含密碼雜湊等機密欄位。 */
function accounts_list_with_projects(array $cfg): array {
    $out = [];
    foreach (accounts_load($cfg)['accounts'] as $a) {
        $id = (string)($a['id'] ?? '');
        if ($id === '') continue;
        $isPrimary = ($a['role'] ?? '') === 'primary';
        $out[] = [
            'id' => $id,
            'userid' => (string)($a['userid'] ?? ''),
            'label' => (string)($a['label'] ?? ''),
            'role' => $isPrimary ? 'primary' : 'user',
            'created_at' => (string)($a['created_at'] ?? ''),
            'disabled' => !empty($a['disabled']),
            'projects' => $isPrimary ? [] : account_project_list($cfg, $id),
        ];
    }
    return $out;
}

// ── 舊 PIN 轉換為帳號：primary 產生一次性連結 → 對方輸入舊 PIN 證明本人 → 設 userid/密碼 ──
// pending token 只記「要轉換哪把舊 PIN」的參照（來源＋id），不複製 PIN 明文，直到啟用當下才去 pins.json 核對。
function account_migrate_create(array $cfg, string $source, ?string $project, ?string $legacyId, string $suggestedUserid, string $label): array {
    $d = accounts_load($cfg);
    $token = bin2hex(random_bytes(16));
    $pending = [
        'token_hash' => hash('sha256', $token),
        'source' => $source,               // 'bootstrap'｜'primary'｜'project'
        'project' => $project,
        'legacy_id' => $legacyId,          // pins.json 該筆的 id（bootstrap 沒有 id，為 null）
        'suggested_userid' => account_userid_dedupe($cfg, $suggestedUserid),
        'label' => substr(trim($label), 0, 80),
        'created_at' => gmdate('c'),
        'expires_at' => gmdate('c', time() + 7 * 86400),
        'used' => false,
    ];
    $d['pending'][] = $pending;
    accounts_save($cfg, $d);
    return $pending + ['token' => $token];
}
function account_migrate_find(array $cfg, string $token): ?array {
    foreach (accounts_load($cfg)['pending'] as $p) {
        if (!hash_equals((string)($p['token_hash'] ?? ''), hash('sha256', $token))) continue;
        if (!empty($p['used']) || gmdate('c') > (string)$p['expires_at']) return null;
        return $p;
    }
    return null;
}
/** 核對 pending 指向的舊 PIN 是否等於使用者輸入；同時回傳原本的 perms（僅 project 來源有意義）。 */
function _account_migrate_check_legacy_pin(array $cfg, array $pending, string $pin): ?array {
    if ($pending['source'] === 'bootstrap') {
        return _cfg_primary_pin_match($cfg, $pin) ? [] : null;
    }
    // 相容改名前（source 存 'master'）尚未過期的邀請連結，一併視為 primary 來源
    $list = in_array($pending['source'], ['master', 'primary'], true) ? pins_load($cfg)['primary'] : (pins_load($cfg)['projects'][$pending['project']] ?? []);
    foreach ($list as $e) {
        if ((string)($e['id'] ?? '') === (string)$pending['legacy_id'] && isset($e['pin_hash']) && hash_equals((string)$e['pin_hash'], pin_hash_of($cfg, $pin))) return $e['perms'] ?? _account_default_perms();
    }
    return null;
}
/** 回傳 ['ok'=>true,'account'=>...] 或 ['ok'=>false,'error'=>'invalid_token'|'legacy_pin_wrong'|userid/密碼錯誤代碼]。 */
function account_migrate_activate(array $cfg, string $token, string $legacyPin, string $userid, string $pw): array {
    $pending = account_migrate_find($cfg, $token);
    if ($pending === null) return ['ok' => false, 'error' => 'invalid_token'];
    $perms = _account_migrate_check_legacy_pin($cfg, $pending, $legacyPin);
    if ($perms === null) return ['ok' => false, 'error' => 'legacy_pin_wrong'];
    $userid = account_userid_normalize($userid);
    if ($userid === '') return ['ok' => false, 'error' => 'userid_invalid'];
    if (account_find_by_userid($cfg, $userid) !== null) return ['ok' => false, 'error' => 'userid_taken'];
    if (($err = account_password_check($userid, $pw)) !== null) return ['ok' => false, 'error' => $err];

    $d = accounts_load($cfg);
    $acc = [
        'id' => 'acc_' . bin2hex(random_bytes(6)),
        'userid' => $userid,
        'password_hash' => password_hash($pw, PASSWORD_DEFAULT),
        'label' => $pending['label'] !== '' ? $pending['label'] : account_default_label($userid),
        'role' => $pending['source'] === 'project' ? 'user' : 'primary',
        'created_at' => gmdate('c'),
        'disabled' => false,
        'fail_count' => 0,
        'locked_until' => null,
    ];
    $d['accounts'][] = $acc;
    foreach ($d['pending'] as &$p) { if (($p['token_hash'] ?? '') === $pending['token_hash']) { $p['used'] = true; break; } }
    unset($p);
    accounts_save($cfg, $d);

    if ($pending['source'] === 'project') { project_account_set($cfg, (string)$pending['project'], $acc['id'], $perms); }
    audit_log($cfg, 'acct:' . $acc['id'], 'migrate_activate', $pending['source'] === 'project' ? (string)$pending['project'] : null, $pending['source'] . ' -> ' . $acc['role']);
    return ['ok' => true, 'account' => $acc];
}

// ── 稽核紀錄：state/audit.log（JSONL 附加寫入），只記敏感/跨專案動作，供憑證外洩時事後追查 ──
function audit_log(array $cfg, string $who, string $action, ?string $project = null, string $detail = ''): void {
    $f = rtrim($cfg['state_dir'], '/\\') . '/audit.log';
    $line = json_encode(['at' => gmdate('c'), 'who' => $who, 'action' => $action, 'project' => $project, 'detail' => $detail, 'ip' => client_ip($cfg)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $fp = @fopen($f, 'ab');
    if (!$fp) { error_log('souliong: audit_log 開檔失敗，本筆稽核紀錄遺失：' . $line); return; }
    flock($fp, LOCK_EX);
    fwrite($fp, $line . "\n");
    flock($fp, LOCK_UN);
    fclose($fp);
}

// ── den 單一登入 ──
// den 只確認是誰，這裡換成本站原本的工作階段，PIN 與帳密登入不受影響。
// super 成為主要管理者；user 要有指定身分組，已綁定的帳號直接登入，第一次先到選擇頁綁定現有帳號或建立新帳號。
// 票證只能用一次，驗票後的待綁定狀態放進簽章 cookie，選擇頁的 CSRF 由它衍生。
const DEN_STATE_COOKIE = 'souliong_den_state';
const DEN_PENDING_COOKIE = 'souliong_den_pending';
const DEN_PENDING_TTL = 600;
function _den_key(array $cfg): string {
    static $keys = [];
    $f = (string)($cfg['den_sso']['key_file'] ?? '');
    return $keys[$f] ??= ($f !== '' && is_readable($f)) ? trim((string)@file_get_contents($f)) : '';
}
function _den_cookie_opts(int $ttl): array { return ['expires' => time() + $ttl] + _cookie_opts(); }
function den_sso_enabled(array $cfg): bool { return !empty($cfg['den_sso']['login_url']) && _den_key($cfg) !== ''; }
/** 導向 den 登入頁的網址並種下一次性 state；回呼固定落在後台根網址。 */
function den_sso_begin(array $cfg, string $project, string $backBase): ?string {
    if (!den_sso_enabled($cfg)) return null;
    $state = bin2hex(random_bytes(16));
    setcookie(DEN_STATE_COOKIE, $state, _den_cookie_opts(600));
    $back = $backBase . '?den_return=1&state=' . $state . ($project !== '' ? '&project=' . rawurlencode($project) : '');
    return rtrim((string)$cfg['den_sso']['login_url'], '?') . '?app=' . rawurlencode((string)($cfg['den_sso']['app'] ?? 'souliong')) . '&next=' . rawurlencode($back);
}
/** 向 den 驗票，任何異常都回 null。 */
function den_sso_verify(array $cfg, string $ticket): ?array {
    $key = _den_key($cfg);
    if ($key === '' || !preg_match('/^[A-Za-z0-9._~-]{16,2048}$/', $ticket)) return null;
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'timeout' => 4, 'ignore_errors' => true, 'follow_location' => 0,
        'header' => "Content-Type: application/json\r\nX-SSO-Key: $key\r\n",
        'content' => json_encode(['app' => (string)($cfg['den_sso']['app'] ?? 'souliong'), 'ticket' => $ticket]),
    ]]);
    $raw = @file_get_contents((string)$cfg['den_sso']['verify_url'], false, $ctx);
    $d = $raw !== false ? json_decode($raw, true) : null;
    if (!is_array($d) || ($d['ok'] ?? false) !== true || !in_array($d['kind'] ?? '', ['super', 'user'], true) || (string)($d['id'] ?? '') === '') return null;
    return ['kind' => $d['kind'], 'id' => (string)$d['id'], 'email' => (string)($d['email'] ?? ''), 'roles' => is_array($d['roles'] ?? null) ? array_map('strval', $d['roles']) : []];
}
function account_find_by_den_id(array $cfg, string $denId): ?array {
    if ($denId === '') return null;
    foreach (accounts_load($cfg)['accounts'] as $a) { if ((string)($a['den_id'] ?? '') === $denId) return $a; }
    return null;
}
// 待綁定狀態：base64url(json) . HMAC，只在驗票成功後簽發，過期或被改過一律視為不存在。
function _den_pending_sign(array $cfg, string $body): string { return hash_hmac('sha256', 'souliong-den-pending|' . $body, (string)($cfg['ip_salt'] ?? '')); }
function _den_pending_set(array $cfg, array $who): void {
    $body = rtrim(strtr(base64_encode(json_encode(['id' => $who['id'], 'email' => $who['email'], 'exp' => time() + DEN_PENDING_TTL])), '+/', '-_'), '=');
    $_COOKIE[DEN_PENDING_COOKIE] = $body . '.' . _den_pending_sign($cfg, $body);
    setcookie(DEN_PENDING_COOKIE, $_COOKIE[DEN_PENDING_COOKIE], _den_cookie_opts(DEN_PENDING_TTL));
}
function den_sso_pending_clear(): void {
    setcookie(DEN_PENDING_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
    unset($_COOKIE[DEN_PENDING_COOKIE]);
}
/** 目前待綁定的 den 身分：['id','email','csrf','suggest']；沒有、過期、被竄改回 null。 */
function den_sso_pending(array $cfg): ?array {
    $raw = (string)($_COOKIE[DEN_PENDING_COOKIE] ?? '');
    $dot = strrpos($raw, '.');
    if ($dot === false) return null;
    $body = substr($raw, 0, $dot);
    if (!hash_equals(_den_pending_sign($cfg, $body), substr($raw, $dot + 1))) return null;
    $p = json_decode((string)base64_decode(strtr($body, '-_', '+/')), true);
    if (!is_array($p) || (int)($p['exp'] ?? 0) <= time() || (string)($p['id'] ?? '') === '') return null;
    $local = strstr((string)$p['email'], '@', true);
    return ['id' => (string)$p['id'], 'email' => (string)$p['email'],
        'csrf' => hash_hmac('sha256', 'souliong-den-csrf|' . $raw, (string)($cfg['ip_salt'] ?? '')),
        'suggest' => account_userid_dedupe($cfg, 'den-' . ($local !== false && $local !== '' ? $local : (string)$p['id']))];
}
function _den_pending_check(array $cfg, string $csrf): ?array {
    $p = den_sso_pending($cfg);
    return ($p !== null && hash_equals($p['csrf'], $csrf)) ? $p : null;
}
/**
 * 處理 den 回呼。成功回 ['ok'=>true] 加下列其一：
 *   'primary'=>true            super，已種好主要管理者工作階段
 *   'account_id'=>…            已綁定帳號，已種好帳號 cookie
 *   'pending'=>true            第一次進來，已種好待綁定 cookie，呼叫端導向選擇頁
 * 失敗回 ['ok'=>false,'error'=>'denied'|'failed']。導向目標由呼叫端組，不吃任何輸入。
 */
function den_sso_complete(array $cfg, string $state, string $ticket): array {
    $cookie = (string)($_COOKIE[DEN_STATE_COOKIE] ?? '');
    setcookie(DEN_STATE_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
    if ($cookie === '' || !hash_equals($cookie, $state)) return ['ok' => false, 'error' => 'failed'];
    $who = den_sso_verify($cfg, $ticket);
    if ($who === null) return ['ok' => false, 'error' => 'failed'];
    if ($who['kind'] === 'super') {
        primary_set_cookie($cfg, 'den:' . $who['id']);
        audit_log($cfg, 'den:' . $who['id'], 'den_login', null, 'primary');
        return ['ok' => true, 'primary' => true];
    }
    if (!in_array((string)($cfg['den_sso']['role'] ?? 'souliong'), $who['roles'], true)) {
        audit_log($cfg, 'den:' . $who['id'], 'den_login_denied');
        return ['ok' => false, 'error' => 'denied'];
    }
    $acc = account_find_by_den_id($cfg, $who['id']);
    if ($acc === null) { _den_pending_set($cfg, $who); return ['ok' => true, 'pending' => true]; }
    if (!empty($acc['disabled'])) return ['ok' => false, 'error' => 'denied'];
    account_set_cookie($cfg, (string)$acc['id']);
    audit_log($cfg, 'den:' . $who['id'], 'den_login', null, 'acct:' . $acc['id']);
    return ['ok' => true, 'account_id' => (string)$acc['id']];
}
/** 綁定現有帳號：密碼驗證走 account_login，含失敗鎖定。回 ['ok'=>true,'account'=>…] 或 ['ok'=>false,'error'=>'expired'|'invalid'|'locked'|'bound']。 */
function den_sso_bind(array $cfg, string $csrf, string $userid, string $pw): array {
    $p = _den_pending_check($cfg, $csrf);
    if ($p === null) return ['ok' => false, 'error' => 'expired'];
    $r = account_login($cfg, $userid, $pw);
    if (!$r['ok']) return $r;
    $accId = (string)$r['account']['id'];
    $res = accounts_mutate($cfg, function (array &$d) use ($cfg, $p, $accId) {
        if (account_find_by_den_id($cfg, $p['id']) !== null) return ['ok' => false, 'error' => 'bound'];
        foreach ($d['accounts'] as $i => $e) {
            if ((string)($e['id'] ?? '') !== $accId) continue;
            if ((string)($e['den_id'] ?? '') !== '') return ['ok' => false, 'error' => 'bound'];
            $d['accounts'][$i]['den_id'] = $p['id'];
            return ['ok' => true, 'account' => $d['accounts'][$i]];
        }
        return ['ok' => false, 'error' => 'invalid'];
    });
    if (!$res['ok']) return $res;
    den_sso_pending_clear();
    account_set_cookie($cfg, $accId);
    audit_log($cfg, 'den:' . $p['id'], 'den_bind', null, 'acct:' . $accId);
    return $res;
}
/** 建立新帳號：userid 規則同 account_register，不設密碼，之後只能經 den 進來。userid 留空用建議值。 */
function den_sso_create(array $cfg, string $csrf, string $userid, string $label): array {
    $p = _den_pending_check($cfg, $csrf);
    if ($p === null) return ['ok' => false, 'error' => 'expired'];
    $userid = trim($userid) === '' ? $p['suggest'] : account_userid_normalize($userid);
    if ($userid === '') return ['ok' => false, 'error' => 'userid_invalid'];
    $res = accounts_mutate($cfg, function (array &$d) use ($cfg, $p, $userid, $label) {
        if (account_find_by_den_id($cfg, $p['id']) !== null) return ['ok' => false, 'error' => 'bound'];
        if (account_find_by_userid($cfg, $userid) !== null) return ['ok' => false, 'error' => 'userid_taken'];
        $acc = [
            'id' => 'acc_' . bin2hex(random_bytes(6)), 'userid' => $userid,
            'password_hash' => '',   // 空雜湊讓 account_login() 一律拒絕
            'label' => substr(trim($label) !== '' ? trim($label) : ($p['email'] !== '' ? $p['email'] : account_default_label($userid)), 0, 80),
            'role' => 'user', 'created_at' => gmdate('c'), 'disabled' => false, 'fail_count' => 0, 'locked_until' => null,
            'den_id' => $p['id'],
        ];
        $d['accounts'][] = $acc;
        return ['ok' => true, 'account' => $acc];
    });
    if (!$res['ok']) return $res;
    den_sso_pending_clear();
    account_set_cookie($cfg, (string)$res['account']['id']);
    audit_log($cfg, 'den:' . $p['id'], 'den_create', null, 'acct:' . $res['account']['id']);
    return $res;
}
/** 目前登入帳號的綁定狀態，給解除綁定按鈕用；未登入或沒綁定回 null。 */
function den_account_info(array $cfg): ?array {
    $a = account_current($cfg);
    if ($a === null || (string)($a['den_id'] ?? '') === '') return null;
    return ['csrf' => account_derived($cfg, (string)$a['id']), 'has_password' => (string)($a['password_hash'] ?? '') !== ''];
}
/** 解除目前帳號的綁定；沒有密碼的帳號不准解除，否則會失去所有登入方式。回 ['ok'=>true] 或 ['ok'=>false,'error'=>'csrf'|'none'|'needs_password']。 */
function den_sso_unbind(array $cfg, string $csrf): array {
    $a = account_current($cfg);
    if ($a === null || (string)($a['den_id'] ?? '') === '') return ['ok' => false, 'error' => 'none'];
    if (!hash_equals(account_derived($cfg, (string)$a['id']), $csrf)) return ['ok' => false, 'error' => 'csrf'];
    if ((string)($a['password_hash'] ?? '') === '') return ['ok' => false, 'error' => 'needs_password'];
    $accId = (string)$a['id'];
    $res = accounts_mutate($cfg, function (array &$d) use ($accId) {
        foreach ($d['accounts'] as $i => $e) {
            if ((string)($e['id'] ?? '') !== $accId) continue;
            unset($d['accounts'][$i]['den_id']);
            return ['ok' => true];
        }
        return ['ok' => false, 'error' => 'none'];
    });
    if ($res['ok']) audit_log($cfg, 'acct:' . $accId, 'den_unbind', null, 'den:' . $a['den_id']);
    return $res;
}
