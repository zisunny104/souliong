<?php
/**
 * 簡易資安/韌性工具：每 IP 檔案式速率限制（滑動視窗），緩解洪水與掃描。
 * 無外部相依；限流本身失敗時「放行」而非拒服務（避免自我 DoS）。
 * 位於 Nginx 反代後，需在 config 開 trust_forwarded 才會用 X-Forwarded-For。
 */
require_once __DIR__ . '/accounts.php';   // primary_authed/admin_can/admin_perm 疊加帳號登入判斷，需要 account_current() 等函式

function client_ip(array $cfg): string {
    if (!empty($cfg['trust_forwarded']) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($parts[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * 管理登入：多 PIN + 簡易權限管理。
 * - 主 PIN：config['admin_pin']（bootstrap）+ state/admin_pins.json 的 primary 清單，皆為全域權限。
 * - 各專案 PIN：state/admin_pins.json 的 projects[<id>] 清單，僅該專案。
 * - 每把 PIN 可帶暱稱 label。
 * - cookie 由「鹽值」衍生（代表已通過某範圍），與特定 PIN 解耦；移除某 PIN 不影響既有登入，
 *   需全面登出可更換 ip_salt。
 */
define('PRIMARY_COOKIE', 'souliong_primary');
function primary_derived(array $cfg): string { return hash_hmac('sha256', 'souliong-primary', (string)($cfg['ip_salt'] ?? '')); }
function padm_cookie_name(string $project): string { return 'souliong_padm_' . preg_replace('/[^a-z0-9_-]/', '', $project); }
// cookie 值＝"<pinId>.<簽章>"：簽章綁定 project+pinId，讓 cookie 記得「用哪一把專案 PIN 登入」，
// 才能做到權限旗標可個別下放到特定專案 PIN（而非只要有登入任一把就視為同權）。
function padm_derived(array $cfg, string $project, string $pinId): string { return hash_hmac('sha256', 'souliong-padm', $project . '|' . $pinId . '|' . (string)($cfg['ip_salt'] ?? '')); }

// PIN 登入與帳號登入（見檔尾「帳號系統」）並存：只要任一種通過就算通過，帳號是 PIN 之上疊加的
// 一層，不取代——舊 PIN 在完成「轉換為帳號」前持續有效，不會有人被迫中斷登入。
function primary_authed(array $cfg): bool {
    if (hash_equals(primary_derived($cfg), (string)($_COOKIE[PRIMARY_COOKIE] ?? ''))) return true;
    $acc = account_current($cfg);
    return $acc !== null && ($acc['role'] ?? '') === 'primary';
}

/** 解析目前這個專案的 padm cookie，回傳驗證通過的 pinId；未登入或簽章不符回傳 null。 */
function padm_pin_id(array $cfg, string $project): ?string {
    $raw = (string)($_COOKIE[padm_cookie_name($project)] ?? '');
    $dot = strrpos($raw, '.');
    if ($dot === false) return null;
    $pinId = substr($raw, 0, $dot);
    $sig = substr($raw, $dot + 1);
    if ($pinId === '' || !hash_equals(padm_derived($cfg, $project, $pinId), $sig)) return null;
    return $pinId;
}
function admin_can(array $cfg, string $project): bool {
    if (primary_authed($cfg)) return true;
    if (padm_pin_id($cfg, $project) !== null) return true;
    $acc = account_current($cfg);
    return $acc !== null && project_admin_perms($cfg, $project, (string)$acc['id']) !== null;
}
/** primary／primary 帳號在每個具名權限下皆視為開啟，供 admin_perm()／site_perm() 統一查表，
 *  而非各自硬編碼略過檢查——新權限鍵一律要在這裡明列才會對 primary 生效，不會無聲預設全開。 */
function primary_perms(): array {
    return [
        'delete_others' => true, 'edit_others' => true, 'edit_points' => true,
        'grant_access' => true, 'edit_3d_regions' => true,
        'manage_layers' => true, 'fix_exif' => true, 'fix_thumbnails' => true, 'view_stats' => true,
    ];
}
/** 專案層級具名權限判斷：primary 查 primary_perms()；專案 PIN 或專案帳號則需 perms[$permKey] 已被開啟才通過。 */
function admin_perm(array $cfg, string $project, string $permKey): bool {
    if (primary_authed($cfg)) return !empty(primary_perms()[$permKey]);
    $pinId = padm_pin_id($cfg, $project);
    if ($pinId !== null) {
        foreach (pins_load($cfg)['projects'][$project] ?? [] as $e) {
            if ((string)($e['id'] ?? '') === $pinId) return !empty($e['perms'][$permKey]);
        }
    }
    $acc = account_current($cfg);
    if ($acc !== null) {
        $perms = project_admin_perms($cfg, $project, (string)$acc['id']);
        if ($perms !== null) return !empty($perms[$permKey]);
    }
    return false;
}
/** 全站層級（跨專案）具名權限：目前僅 primary 具備，供圖層搬遷／EXIF／縮圖修復／統計等維護工具使用。 */
function site_perm(array $cfg, string $permKey): bool {
    return primary_authed($cfg) && !empty(primary_perms()[$permKey]);
}
function _cookie_opts(): array { return ['expires' => time() + 7 * 86400, 'path' => '/', 'httponly' => true, 'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), 'samesite' => 'Lax']; }
function primary_set_cookie(array $cfg): void { setcookie(PRIMARY_COOKIE, primary_derived($cfg), _cookie_opts()); }
function padm_set_cookie(array $cfg, string $project, string $pinId): void { setcookie(padm_cookie_name($project), $pinId . '.' . padm_derived($cfg, $project, $pinId), _cookie_opts()); }
function primary_clear_cookie(): void {
    setcookie(PRIMARY_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
    foreach ($_COOKIE as $k => $v) { if (strpos($k, 'souliong_padm_') === 0) setcookie($k, '', ['expires' => time() - 3600, 'path' => '/']); }
}

// ── PIN 清單（state/admin_pins.json） ──
function pins_file(array $cfg): string { return rtrim($cfg['state_dir'], '/\\') . '/admin_pins.json'; }
/** 新專案 PIN 的預設權限：一律從全關始（等同僅主 PIN 才能動別人的東西），需主 PIN 逐項開啟下放。 */
function pin_default_perms(): array { return ['delete_others' => false, 'edit_others' => false, 'edit_points' => false, 'grant_access' => false, 'edit_3d_regions' => false]; }
function pins_load(array $cfg): array {
    $d = is_file(pins_file($cfg)) ? json_decode((string)@file_get_contents(pins_file($cfg)), true) : null;
    if (!is_array($d)) $d = [];
    $d['projects'] = $d['projects'] ?? [];
    // 舊資料補齊 id/perms（一次性、自我修復），含 delegate_admin → grant_access、master → primary 改名搬遷
    $dirty = false;
    if (array_key_exists('master', $d)) {
        $d['primary'] = $d['master'];
        unset($d['master']);
        $dirty = true;
    }
    $d['primary'] = $d['primary'] ?? [];
    foreach ($d['projects'] as $p => &$list) {
        foreach ($list as &$e) {
            if (empty($e['id'])) { $e['id'] = bin2hex(random_bytes(4)); $dirty = true; }
            if (!isset($e['perms']) || !is_array($e['perms'])) { $e['perms'] = pin_default_perms(); $dirty = true; }
            if (!isset($e['kind'])) { $e['kind'] = 'pin'; $dirty = true; }
            if (array_key_exists('delegate_admin', $e['perms']) && !array_key_exists('grant_access', $e['perms'])) {
                $e['perms']['grant_access'] = $e['perms']['delegate_admin'];
                unset($e['perms']['delegate_admin']);
                $dirty = true;
            }
        }
        unset($e);
    }
    unset($list);
    if ($dirty) pins_save($cfg, $d);
    return $d;
}
function pins_save(array $cfg, array $d): void { @file_put_contents(pins_file($cfg), json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX); }
function _pin_in(array $list, string $pin): bool { foreach ($list as $e) { if (isset($e['pin']) && $pin !== '' && hash_equals((string)$e['pin'], $pin)) return true; } return false; }
function check_primary_pin(array $cfg, string $pin): bool {
    if ($pin === '') return false;
    if (($cfg['admin_pin'] ?? '') !== '' && hash_equals((string)$cfg['admin_pin'], $pin)) return true;
    return _pin_in(pins_load($cfg)['primary'], $pin);
}
/** 找出符合此 PIN 的專案 PIN 紀錄（含 id/perms），供登入時決定 cookie 要記哪把；不符合回傳 null。 */
function project_pin_match(array $cfg, string $project, string $pin): ?array {
    if ($pin === '') return null;
    foreach (pins_load($cfg)['projects'][$project] ?? [] as $e) {
        if (isset($e['pin']) && hash_equals((string)$e['pin'], $pin)) return $e;
    }
    return null;
}
/**
 * 登入時的到期/次數檢查（僅對有設定 expires_at/max_uses 的專案 PIN 有效，例如分享建立的「管理PIN」連結；
 * 一般專案 PIN 兩者皆為 null，恆放行）。超過限制回傳 false；否則（若有 max_uses）used_count++ 並存檔。
 */
function pins_check_and_bump(array $cfg, string $project, string $pinId): bool {
    $d = pins_load($cfg);
    $list = $d['projects'][$project] ?? [];
    foreach ($list as $i => $e) {
        if ((string)($e['id'] ?? '') !== $pinId) continue;
        if (!empty($e['expires_at']) && gmdate('c') > (string)$e['expires_at']) return false;
        $max = $e['max_uses'] ?? null;
        $used = (int)($e['used_count'] ?? 0);
        if ($max !== null && $used >= (int)$max) return false;
        if ($max !== null) {
            $d['projects'][$project][$i]['used_count'] = $used + 1;
            pins_save($cfg, $d);
        }
        return true;
    }
    return true;   // 找不到 id（理論上不會發生）：不因此擋登入
}
/**
 * 後台「分享邀請連結」用：寫入一筆 kind:"invite" entry，尚未有 PIN／暱稱——由收到連結的人自己兌換時填入。
 * 呼叫端須自行檢查「只有主 PIN 或已被授權 grant_access 的專案 PIN 才能建立」。
 * 回傳 [token, id]：token 是連結裡帶的祕密，id 是後台列表／刪除用的公開識別碼。
 */
function pins_invite_create(array $cfg, string $project, ?string $expiresAt, ?int $maxUses): array {
    $token = bin2hex(random_bytes(16));
    $d = pins_load($cfg);
    $entry = [
        'kind'       => 'invite',
        'id'         => bin2hex(random_bytes(4)),
        'token'      => $token,
        'expires_at' => $expiresAt,
        'max_uses'   => $maxUses,
        'used_count' => 0,
        'created_at' => gmdate('c'),
    ];
    $d['projects'][$project] = $d['projects'][$project] ?? [];
    $d['projects'][$project][] = $entry;
    pins_save($cfg, $d);
    return [$token, (string)$entry['id']];
}
/** 依 token 找出尚未兌換的邀請（kind==='invite'）；找不到回傳 null。 */
function invite_find(array $cfg, string $project, string $token): ?array {
    if ($token === '') return null;
    foreach (pins_load($cfg)['projects'][$project] ?? [] as $e) {
        if (($e['kind'] ?? '') === 'invite' && hash_equals((string)$e['token'], $token)) return $e;
    }
    return null;
}
/**
 * 兌換管理 PIN 邀請：收件人自己輸入 PIN／暱稱，成功後寫入一筆 kind:"pin" entry（perms 全關，
 * expires_at/max_uses 皆為 null——限制只發生在兌換這一關，不對已兌換出來的 PIN 疊加登入次數限制）。
 * 回傳 ['ok'=>true,'id'=>...,'label'=>...] 或 ['ok'=>false,'error'=>'pin_len'|'invalid'|'expired_or_used_up'|'pin_taken']。
 */
function pins_redeem(array $cfg, string $project, string $token, string $pin, ?string $label): array {
    if (strlen($pin) < 4 || strlen($pin) > 64) return ['ok' => false, 'error' => 'pin_len'];
    $invite = invite_find($cfg, $project, $token);
    if ($invite === null) return ['ok' => false, 'error' => 'invalid'];
    if (!pins_check_and_bump($cfg, $project, (string)$invite['id'])) return ['ok' => false, 'error' => 'expired_or_used_up'];
    $d = pins_load($cfg);
    if (_pin_in($d['projects'][$project] ?? [], $pin)) return ['ok' => false, 'error' => 'pin_taken'];
    $label = $label !== null ? substr(trim($label), 0, 80) : '';
    $entry = [
        'kind'       => 'pin',
        'pin'        => $pin,
        'label'      => $label,
        'id'         => bin2hex(random_bytes(4)),
        'perms'      => pin_default_perms(),
        'expires_at' => null,
        'max_uses'   => null,
        'used_count' => 0,
        'via_link'   => true,
        'invite_id'  => (string)$invite['id'],
    ];
    $d['projects'][$project][] = $entry;
    pins_save($cfg, $d);
    return ['ok' => true, 'id' => $entry['id'], 'label' => $label];
}
function _label_in(array $list, string $pin): string {
    foreach ($list as $e) { if (isset($e['pin']) && $pin !== '' && hash_equals((string)$e['pin'], $pin)) return trim((string)($e['label'] ?? '')); }
    return '';
}
/** 登入用的這把 PIN 若有設定暱稱，回傳暱稱；bootstrap 主 PIN 對應 config['admin_pin_label']。供登入後帶入投稿身分。 */
function primary_pin_label(array $cfg, string $pin): string {
    if (($cfg['admin_pin'] ?? '') !== '' && hash_equals((string)$cfg['admin_pin'], $pin)) {
        return trim((string)($cfg['admin_pin_label'] ?? ''));
    }
    return _label_in(pins_load($cfg)['primary'], $pin);
}
function project_pin_label(array $cfg, string $project, string $pin): string {
    return _label_in(pins_load($cfg)['projects'][$project] ?? [], $pin);
}

/**
 * 產生投稿碼：純數字，方便手機數字鍵盤輸入、避免自動大寫/自動更正把英數碼改壞。
 * 純數字＋速率限制（每分鐘上限）對「社群上傳閘門」這類低風險場景足夠；碼本就以連結/QR 公開分享。
 */
function gen_code(int $len = 6): string {
    $s = '';
    for ($i = 0; $i < $len; $i++) $s .= (string)random_int(0, 9);
    return $s;
}

/**
 * 投稿碼：可建多組，各自可設到期時間／次數上限（皆留空＝不限期不限次數），
 * 達到即失效；存 projects/<project>/codes.json = [{code, label, created, expires_at, max_uses, used_count}]。
 * 有沒有還有效的碼就是這張地圖的投稿開關（見 contrib_open）。
 */
function codes_file(array $cfg, string $project): string { return project_dir($cfg, $project) . '/codes.json'; }
function codes_load(array $cfg, string $project): array {
    $f = codes_file($cfg, $project);
    $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    $d = is_array($d) ? $d : [];
    // 一次性遷移：舊版常駐碼存在 code.txt，併入清單成一筆不限期不限次數的碼，避免已發出去的碼失效。
    $legacy = project_dir($cfg, $project) . '/code.txt';
    if (is_file($legacy)) {
        $c = trim((string)@file_get_contents($legacy));
        if ($c !== '' && !array_filter($d, fn($e) => hash_equals((string)($e['code'] ?? ''), $c))) {
            $d[] = ['code' => $c, 'label' => '', 'created' => gmdate('c'), 'expires_at' => null, 'max_uses' => null, 'used_count' => 0];
            @file_put_contents($f, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        }
        @unlink($legacy);
    }
    return $d;
}
function codes_save(array $cfg, string $project, array $d): void { @file_put_contents(codes_file($cfg, $project), json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX); }
function codes_grant_create(array $cfg, string $project, ?string $code, ?string $label, ?string $expiresAt, ?int $maxUses): string {
    $code = $code !== null ? preg_replace('/\D/', '', $code) : '';
    if ($code === '') $code = gen_code();
    $d = codes_load($cfg, $project);
    $d[] = [
        'code'       => $code,
        'label'      => $label !== null ? substr(trim((string)$label), 0, 80) : '',
        'created'    => gmdate('c'),
        'expires_at' => $expiresAt,
        'max_uses'   => $maxUses,
        'used_count' => 0,
    ];
    codes_save($cfg, $project, $d);
    return $code;
}
/** 驗證附加投稿碼；$bump=true（實際上傳）時計一次使用。到期／用罄／不存在回 false。 */
function code_check(array $cfg, string $project, string $given, bool $bump): bool {
    if ($given === '') return false;
    $d = codes_load($cfg, $project);
    foreach ($d as $i => $e) {
        if (!hash_equals((string)($e['code'] ?? ''), $given)) continue;
        if (!empty($e['expires_at']) && gmdate('c') > (string)$e['expires_at']) return false;
        $max = $e['max_uses'] ?? null;
        if ($max !== null && (int)($e['used_count'] ?? 0) >= (int)$max) return false;
        if ($bump) {
            $d[$i]['used_count'] = (int)($e['used_count'] ?? 0) + 1;
            codes_save($cfg, $project, $d);
        }
        return true;
    }
    return false;
}

/**
 * 投稿開關＝有沒有還有效的投稿碼。碼是唯一的開關：
 *   一組有效碼都沒有 → 這張地圖現在未開放投稿（管理者不受限，方便主辦者自己補資料）
 *   有 → 要碼才能投稿，且各碼自己的到期／次數上限照常生效
 * 舊版的 meta.gated 已停用：那個旗標後台沒有任何地方能設，等於投稿碼形同虛設。
 */
function codes_active(array $cfg, string $project): array {
    $now = gmdate('c');
    return array_values(array_filter(codes_load($cfg, $project), function ($e) use ($now) {
        if (!empty($e['expires_at']) && $now > (string)$e['expires_at']) return false;
        $max = $e['max_uses'] ?? null;
        return !($max !== null && (int)($e['used_count'] ?? 0) >= (int)$max);
    }));
}
function contrib_open(array $cfg, string $project): bool { return codes_active($cfg, $project) !== []; }

/**
 * 投稿者身分（可選，設 PIN 才有；匿名投稿者無此身分）：可用一組 PIN 建立跨裝置的身分，用來在別的裝置管理自己的投稿。
 * - token：由 PIN 衍生（同 PIN 同 token），存於使用者 localStorage，是真正的祕密。
 * - contrib_id：token 的短雜湊，作為對外可見的「投稿者ID」（假名、可分組，無法反推 PIN）。
 * - contrib_hash：token 的完整雜湊，存於投稿以驗證刪除；list 不外流。
 */
function contrib_token(array $cfg, string $project, string $pin): string {
    return hash_hmac('sha256', 'souliong-contrib', $project . '|' . $pin . '|' . (string)($cfg['ip_salt'] ?? ''));
}
function contrib_id_of(string $token): string { return substr(hash('sha256', 'cid|' . $token), 0, 12); }
function contrib_hash_of(string $token): string { return hash('sha256', $token); }

// 投稿者名冊：projects/<project>/contrib.json = { <contrib_id>: {label, created} }
// 純自助：身分只在使用者自己於解鎖視窗設 PIN 時建立，不帶配額——能不能投稿只看當次用的投稿碼。
function contrib_file(array $cfg, string $project): string { return project_dir($cfg, $project) . '/contrib.json'; }
function contrib_load(array $cfg, string $project): array {
    $f = contrib_file($cfg, $project);
    $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    return is_array($d) ? $d : [];
}
function contrib_save(array $cfg, string $project, array $d): void { @file_put_contents(contrib_file($cfg, $project), json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX); }
/** 註冊或取得投稿者；回傳 [id, label]。label 僅在首次或本人更新時寫入。 */
function contrib_register(array $cfg, string $project, string $token, ?string $label): array {
    $id = contrib_id_of($token);
    $d = contrib_load($cfg, $project);
    $label = $label !== null ? preg_replace('/^(.{0,40}).*$/su', '$1', trim($label)) : null;
    if ($label === null) $label = '';
    if (!isset($d[$id])) {
        $d[$id] = ['label' => $label, 'created' => gmdate('c')];
        contrib_save($cfg, $project, $d);
    }
    elseif ($label !== '' && ($d[$id]['label'] ?? '') !== $label) { $d[$id]['label'] = $label; contrib_save($cfg, $project, $d); }
    return [$id, $d[$id]['label'] ?? ''];
}

/**
 * 停權名單：擋掉特定身分（有 PIN 的投稿者用 contrib_id、匿名裝置用 owner_hash）繼續投稿，
 * 不影響已投稿內容（那是刪除的事）。存 projects/<project>/blocked.json = {owners:[owner_hash…], contribs:[contrib_id…]}。
 */
function blocked_file(array $cfg, string $project): string { return project_dir($cfg, $project) . '/blocked.json'; }
function blocked_load(array $cfg, string $project): array {
    $f = blocked_file($cfg, $project);
    $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    $d = is_array($d) ? $d : [];
    $d['owners'] = array_values(array_map('strval', $d['owners'] ?? []));
    $d['contribs'] = array_values(array_map('strval', $d['contribs'] ?? []));
    return $d;
}
function blocked_save(array $cfg, string $project, array $d): void { @file_put_contents(blocked_file($cfg, $project), json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX); }
function is_blocked(array $cfg, string $project, ?string $ownerHash, ?string $contribId): bool {
    $d = blocked_load($cfg, $project);
    if ($ownerHash !== null && in_array($ownerHash, $d['owners'], true)) return true;
    if ($contribId !== null && in_array($contribId, $d['contribs'], true)) return true;
    return false;
}
function block_add(array $cfg, string $project, ?string $ownerHash, ?string $contribId): void {
    $d = blocked_load($cfg, $project);
    if ($ownerHash !== null && !in_array($ownerHash, $d['owners'], true)) $d['owners'][] = $ownerHash;
    if ($contribId !== null && !in_array($contribId, $d['contribs'], true)) $d['contribs'][] = $contribId;
    blocked_save($cfg, $project, $d);
}
function block_remove(array $cfg, string $project, ?string $ownerHash, ?string $contribId): void {
    $d = blocked_load($cfg, $project);
    if ($ownerHash !== null) $d['owners'] = array_values(array_diff($d['owners'], [$ownerHash]));
    if ($contribId !== null) $d['contribs'] = array_values(array_diff($d['contribs'], [$contribId]));
    blocked_save($cfg, $project, $d);
}

/** 超過限制時直接以 429 結束請求。個別 bucket 可在 config['rate_limits'][$bucket] 覆寫 max/window（例如批次投稿量遠高於刪除/換鎖等低頻動作）。 */
function rate_limit(array $cfg, string $bucket = 'default'): void {
    $override = $cfg['rate_limits'][$bucket] ?? [];
    $max = $override['max']    ?? $cfg['rate_max']    ?? 40;
    $win = $override['window'] ?? $cfg['rate_window'] ?? 60;
    $dir = rtrim($cfg['state_dir'], '/\\') . '/.rate';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $ip = preg_replace('/[^0-9a-f:.]/i', '', client_ip($cfg));
    $f = $dir . '/' . substr(hash('sha256', $bucket . '|' . $ip), 0, 32) . '.txt';
    $now = time();
    $fp = @fopen($f, 'c+');
    if (!$fp) return;                       // 開檔失敗 → 放行
    if (!flock($fp, LOCK_EX)) { fclose($fp); return; }
    $raw = stream_get_contents($fp);
    $hits = array_values(array_filter(array_map('intval', array_filter(explode(',', trim((string)$raw)), 'strlen')), fn($t) => $t > $now - $win));
    if (count($hits) >= $max) {
        flock($fp, LOCK_UN); fclose($fp);
        header('Retry-After: ' . $win);
        json_out(['error' => '請求過於頻繁，請稍後再試'], 429);
    }
    $hits[] = $now;
    ftruncate($fp, 0); rewind($fp); fwrite($fp, implode(',', $hits));
    flock($fp, LOCK_UN); fclose($fp);
}
