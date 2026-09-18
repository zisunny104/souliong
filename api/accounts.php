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
// account_current() 會在 perm_can()/perm_check() 內被逐專案呼叫（例如 manager.php 列出多個
// 專案時），一次請求內用行程內靜態快取避免每個專案都重讀整份 accounts.json；accounts_save()
// 寫入後同步更新快取。
function _accounts_cache(?array $set = null): ?array {
    static $cache = null;
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
    // 舊資料 role master → primary 改名搬遷（一次性、自我修復）
    $dirty = false;
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

function account_find_by_userid(array $cfg, string $userid): ?array {
    $userid = strtolower(trim($userid));
    foreach (accounts_load($cfg)['accounts'] as $a) { if (strtolower((string)($a['userid'] ?? '')) === $userid) return $a; }
    return null;
}
function account_find_by_id(array $cfg, string $id): ?array {
    foreach (accounts_load($cfg)['accounts'] as $a) { if ((string)($a['id'] ?? '') === $id) return $a; }
    return null;
}
function account_userid_normalize(string $s): string {
    return substr(preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($s))), 0, 40);
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
function _account_default_perms(): array { return ['delete_others' => false, 'edit_others' => false, 'edit_spots' => false, 'grant_access' => false, 'edit_3d_regions' => false, 'edit_meta' => false, 'edit_layers' => false, 'manage_contrib' => false, 'export_backup' => false]; }
// 帳號不存在時仍跑一次 password_verify()（比對這組固定的假雜湊），耗時比照真的驗證，
// 避免「帳號不存在」比「帳號存在但密碼錯」明顯更快，讓人用回應時間差猜中哪些 userid 有註冊。
define('ACCOUNT_DUMMY_HASH', '$2y$10$dTLVeAjwEKpp0FwAqcPLUuP/YC2K5g4zJ/yQDyGTjXv8YDV/M9GEm');
/** 回傳 ['ok'=>true,'account'=>...] 或 ['ok'=>false,'error'=>'invalid'|'locked']。 */
function account_login(array $cfg, string $userid, string $pw): array {
    $a = account_find_by_userid($cfg, $userid);
    if ($a === null || !empty($a['disabled']) || ($a['password_hash'] ?? '') === '') {
        password_verify($pw, ACCOUNT_DUMMY_HASH);
        return ['ok' => false, 'error' => 'invalid'];
    }
    if (!empty($a['locked_until']) && $a['locked_until'] > time()) return ['ok' => false, 'error' => 'locked'];
    $d = accounts_load($cfg);
    foreach ($d['accounts'] as $i => $e) {
        if ((string)($e['id'] ?? '') !== (string)$a['id']) continue;
        if (!password_verify($pw, (string)$e['password_hash'])) {
            $fails = (int)($e['fail_count'] ?? 0) + 1;
            $d['accounts'][$i]['fail_count'] = $fails;
            if ($fails >= 8) $d['accounts'][$i]['locked_until'] = time() + 900;   // 8 次失敗鎖 15 分鐘
            accounts_save($cfg, $d);
            return ['ok' => false, 'error' => 'invalid'];
        }
        $d['accounts'][$i]['fail_count'] = 0;
        $d['accounts'][$i]['locked_until'] = null;
        accounts_save($cfg, $d);
        return ['ok' => true, 'account' => $d['accounts'][$i]];
    }
    return ['ok' => false, 'error' => 'invalid'];
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
        'label' => substr(trim($label), 0, 80),
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

// ── 各專案授權：projects/<project>/perms.json（只含 account_id + perms，無機密；舊檔名 admins.json 首次讀取時自動搬遷）──
function project_perms_file(array $cfg, string $project): string {
    $dir = project_dir($cfg, $project);
    $new = $dir . '/perms.json';
    $legacy = $dir . '/admins.json';
    if (!is_file($new) && is_file($legacy)) @rename($legacy, $new);
    return $new;
}
// perm_check() 對同一專案在單次請求內常被呼叫多次（例如 manager.php 逐一渲染多項權限旗標），
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
    // 舊資料 delegate_admin → grant_access、edit_points → edit_spots 改名搬遷（一次性、自我修復）
    $dirty = false;
    foreach ($d['members'] as &$m) {
        if (isset($m['perms']) && is_array($m['perms']) && array_key_exists('delegate_admin', $m['perms']) && !array_key_exists('grant_access', $m['perms'])) {
            $m['perms']['grant_access'] = $m['perms']['delegate_admin'];
            unset($m['perms']['delegate_admin']);
            $dirty = true;
        }
        if (isset($m['perms']) && is_array($m['perms']) && array_key_exists('edit_points', $m['perms']) && !array_key_exists('edit_spots', $m['perms'])) {
            $m['perms']['edit_spots'] = $m['perms']['edit_points'];
            unset($m['perms']['edit_points']);
            $dirty = true;
        }
        // 同 pins_load()：新具名權限上線前就存在的帳號授權，缺鍵一律回填 true 保留現有能力
        if (isset($m['perms']) && is_array($m['perms'])) {
            foreach (['edit_meta', 'edit_layers', 'manage_contrib', 'export_backup'] as $gk) {
                if (!array_key_exists($gk, $m['perms'])) {
                    $m['perms'][$gk] = true;
                    $dirty = true;
                }
            }
        }
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
/** 此帳號有權限的專案清單；primary 角色不會用到（對所有專案永遠通過，見 perm_can()）。 */
function account_project_list(array $cfg, string $accountId): array {
    $out = [];
    foreach (store_projects($cfg) as $p) { if (project_account_perms($cfg, $p, $accountId) !== null) $out[] = $p; }
    return $out;
}

// ── 舊 PIN 轉換為帳號：primary 產生一次性連結 → 對方輸入舊 PIN 證明本人 → 設 userid/密碼 ──
// pending token 只記「要轉換哪把舊 PIN」的參照（來源＋id），不複製 PIN 明文，直到啟用當下才去 pins.json 核對。
function account_migrate_create(array $cfg, string $source, ?string $project, ?string $legacyId, string $suggestedUserid, string $label): array {
    $d = accounts_load($cfg);
    $token = bin2hex(random_bytes(16));
    $pending = [
        'token' => $token,
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
    return $pending;
}
function account_migrate_find(array $cfg, string $token): ?array {
    foreach (accounts_load($cfg)['pending'] as $p) {
        if (!hash_equals((string)$p['token'], $token)) continue;
        if (!empty($p['used']) || gmdate('c') > (string)$p['expires_at']) return null;
        return $p;
    }
    return null;
}
/** 核對 pending 指向的舊 PIN 是否等於使用者輸入；同時回傳原本的 perms（僅 project 來源有意義）。 */
function _account_migrate_check_legacy_pin(array $cfg, array $pending, string $pin): ?array {
    if ($pending['source'] === 'bootstrap') {
        return (_cfg_primary_pin($cfg) !== '' && hash_equals(_cfg_primary_pin($cfg), $pin)) ? [] : null;
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
        'label' => $pending['label'] !== '' ? $pending['label'] : $userid,
        'role' => $pending['source'] === 'project' ? 'user' : 'primary',
        'created_at' => gmdate('c'),
        'disabled' => false,
        'fail_count' => 0,
        'locked_until' => null,
    ];
    $d['accounts'][] = $acc;
    foreach ($d['pending'] as &$p) { if ($p['token'] === $pending['token']) { $p['used'] = true; break; } }
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
