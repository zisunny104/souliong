<?php
/**
 * 權限單一入口：「這個請求是誰」與「能不能做這件事」全站只在這裡算一次。
 *
 * 端點（api/*.php、pages/*.php）只透過 Auth::require() / Auth::can() / Auth::actor() 問權限與 CSRF，
 * 不直接碰 cookie、perms 檔或衍生簽章（tools/authlint.php 會掃描這條規則）。
 *
 * 身分解析順序（單一順序，權限與 CSRF 共用同一個 Actor）：
 *   1. primary        主 PIN cookie，或角色為 primary 的帳號
 *   2. account        在「此專案」有成員資格的帳號
 *   3. pin            此專案仍有效的專案 PIN cookie
 *   4. anon
 * 取到第一個就定案。舊版 perm_check() 是 primary → PIN → 帳號，CSRF 衍生卻是 primary → 帳號 → PIN，
 * 瀏覽器同時帶帳號與專案 PIN cookie 時權限吃 PIN、CSRF 吃帳號而對不上；現在兩者都吃同一個 Actor，
 * 順序統一為 primary → 帳號 → PIN，這是刻意的修正。
 *
 * 權限鍵註冊表 auth_registry() 是所有權限鍵的唯一來源：primary 的全開表、新建 PIN／帳號的預設表、
 * 既有身分缺鍵時的回填與舊鍵名搬遷都由它產生。新增權限鍵只需在註冊表加一行。
 *
 * 能力與身分分開：isMember() 是純身分（顯示、CSRF 發放用），不是放行條件；放行一律問 can()。
 *
 * 載入：security.php 檔尾會載入本檔，本檔開頭也會載入 security.php（都是 require_once），
 * 所以不論端點先載入哪一支都行；不要用 require 重複載入。
 */
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/i18n.php';

/**
 * 權限鍵註冊表。欄位：
 *   scope    'project'（逐專案，可下放給 PIN／帳號）｜'site'（全站，僅 primary，不存進 PIN／帳號的 perms）
 *   label    後台開關的 lang 鍵（全站鍵不出現在後台的下放開關，為 null）
 *   backfill 上線前就存在的 PIN／帳號缺此鍵時是否回填 true（保留既有能力）；新建身分一律預設 false
 *   was      舊鍵名，讀取時自動搬遷成新鍵名
 */
function auth_registry(): array {
    static $r = [
        'delete_others'   => ['scope' => 'project', 'label' => 'perm_delete_others',   'backfill' => false],
        'edit_others'     => ['scope' => 'project', 'label' => 'perm_edit_others',     'backfill' => false],
        'edit_spots'      => ['scope' => 'project', 'label' => 'perm_edit_spots',      'backfill' => false, 'was' => 'edit_points'],
        'grant_access'    => ['scope' => 'project', 'label' => 'perm_grant_access',    'backfill' => false, 'was' => 'delegate_admin'],
        'edit_3d_regions' => ['scope' => 'project', 'label' => 'perm_edit_3d_regions', 'backfill' => false],
        'edit_meta'       => ['scope' => 'project', 'label' => 'perm_edit_meta',       'backfill' => true],
        'edit_layers'     => ['scope' => 'project', 'label' => 'perm_edit_layers',     'backfill' => true],
        'manage_contrib'  => ['scope' => 'project', 'label' => 'perm_manage_contrib',  'backfill' => true],
        'export_backup'   => ['scope' => 'project', 'label' => 'perm_export_backup',   'backfill' => true],
        'bypass_code'     => ['scope' => 'project', 'label' => 'perm_bypass_code',     'backfill' => true],
        'manage_layers'   => ['scope' => 'site',    'label' => null, 'backfill' => false],
        'fix_exif'        => ['scope' => 'site',    'label' => null, 'backfill' => false],
        'fix_thumbnails'  => ['scope' => 'site',    'label' => null, 'backfill' => false],
        'view_stats'      => ['scope' => 'site',    'label' => null, 'backfill' => false],
        'migrate_spots'   => ['scope' => 'site',    'label' => null, 'backfill' => false],
        'manage_site'     => ['scope' => 'site',    'label' => null, 'backfill' => false],
    ];
    return $r;
}
/** 註冊表裡某個 scope 的權限鍵清單（不指定 scope 則全部）。 */
function auth_perm_keys(?string $scope = null): array {
    $keys = [];
    foreach (auth_registry() as $k => $def) { if ($scope === null || $def['scope'] === $scope) $keys[] = $k; }
    return $keys;
}
/** 新建 PIN／帳號授權的預設權限表：專案層級鍵全關，需 primary 逐項開啟下放。 */
function auth_perms_default(): array {
    return array_fill_keys(auth_perm_keys('project'), false);
}
/** primary 的權限表：註冊表內每個鍵（專案層級與全站層級）皆開。 */
function auth_perms_primary(): array {
    return array_fill_keys(auth_perm_keys(), true);
}
/**
 * 既有身分的 perms 自我修復：舊鍵名搬遷成新鍵名、backfill 鍵缺席時回填 true。有改動回傳 true（呼叫端負責存檔）。
 * pins_load() 與 project_perms_load() 共用，兩邊不再各寫一份迴圈。
 */
function auth_perms_migrate(array &$perms): bool {
    $dirty = false;
    foreach (auth_registry() as $key => $def) {
        $was = $def['was'] ?? null;
        if ($was !== null && array_key_exists($was, $perms)) {
            if (!array_key_exists($key, $perms)) $perms[$key] = $perms[$was];
            unset($perms[$was]);
            $dirty = true;
        }
        if ($def['scope'] === 'project' && !empty($def['backfill']) && !array_key_exists($key, $perms)) {
            $perms[$key] = true;
            $dirty = true;
        }
    }
    return $dirty;
}

/** 這個請求在某個專案的身分（每請求每專案解析一次並快取，不可變）。只由 Auth::actor() 建立。 */
final class Actor {
    private function __construct(
        private array $cfg,
        private ?string $bound,   // 解析時綁定的專案；null＝全站層級
        private string $kind,     // 'primary' | 'account' | 'pin' | 'anon'
        private ?string $id,      // account 的帳號 id／pin 的 PIN id
        private ?array $perms     // account／pin 在 $bound 的權限表；primary／anon 為 null
    ) {}

    /** @internal 只給 Auth::actor() 呼叫。 */
    public static function resolve(array $cfg, ?string $project): Actor {
        if (primary_authed($cfg)) return new Actor($cfg, $project, 'primary', null, null);
        $acct = account_current($cfg);
        if ($project === null) {
            return $acct !== null ? new Actor($cfg, null, 'account', (string)$acct['id'], null) : new Actor($cfg, null, 'anon', null, null);
        }
        if ($acct !== null) {
            $perms = project_account_perms($cfg, $project, (string)$acct['id']);
            if ($perms !== null) return new Actor($cfg, $project, 'account', (string)$acct['id'], $perms);
        }
        $pinId = pin_current_id($cfg, $project);
        if ($pinId !== null) {
            foreach (pins_load($cfg)['projects'][$project] ?? [] as $e) {
                if ((string)($e['id'] ?? '') === $pinId && ($e['kind'] ?? 'pin') === 'pin') {
                    return new Actor($cfg, $project, 'pin', $pinId, is_array($e['perms'] ?? null) ? $e['perms'] : []);
                }
            }
        }
        return new Actor($cfg, $project, 'anon', null, null);
    }

    public function kind(): string { return $this->kind; }

    /** 稽核紀錄用的操作者識別。 */
    public function audit(): string {
        return match ($this->kind) {
            'primary' => 'primary',
            'account' => 'acct:' . $this->id,
            'pin'     => 'pin:' . $this->id,
            default   => 'anon',
        };
    }

    /** 全站唯一的 CSRF 衍生點：這個身分在 $project 該送出的值；anon 為 null。 */
    public function csrf(?string $project): ?string {
        if ($this->kind === 'primary') return primary_derived($this->cfg);
        if ($project !== $this->bound) return Auth::actor($this->cfg, $project)->csrf($project);
        return match ($this->kind) {
            'account' => account_derived($this->cfg, (string)$this->id),
            'pin'     => pin_derived($this->cfg, (string)$this->bound, (string)$this->id),
            default   => null,
        };
    }

    /**
     * 全站唯一的權限查詢點。project=null 為全站層級（只有 primary 可能為真）。
     * 註冊表沒有的鍵一律 false（fail-closed），primary 也不例外。
     */
    public function can(?string $project, string $key): bool {
        $def = auth_registry()[$key] ?? null;
        if ($def === null) return false;
        if ($this->kind === 'primary') return true;
        if ($def['scope'] !== 'project' || $project === null) return false;
        if ($project !== $this->bound) return Auth::actor($this->cfg, $project)->can($project, $key);
        return $this->perms !== null && !empty($this->perms[$key]);
    }

    /** 身分屬於此專案（primary、專案成員帳號、此專案的 PIN）。純身分，不是能力：不可當放行條件。 */
    public function isMember(string $project): bool {
        if ($this->kind === 'primary') return true;
        if ($project !== $this->bound) return Auth::actor($this->cfg, $project)->isMember($project);
        return $this->kind === 'account' || $this->kind === 'pin';
    }

    /** 此身分在專案 $project 具備的專案層級權限鍵（給前端 APP.perms）。 */
    public function grantedKeys(string $project): array {
        return array_values(array_filter(auth_perm_keys('project'), fn($k) => $this->can($project, $k)));
    }
}

/** 權限／CSRF 拒絕訊息，依請求語言取字典。 */
function auth_msg(string $key): string {
    return i18n_t(i18n_dict(i18n_resolve()), $key);
}

final class Auth {
    /** @var array<string, Actor> */
    private static array $actors = [];

    public static function actor(array $cfg, ?string $project): Actor {
        $k = $project ?? '';
        return self::$actors[$k] ??= Actor::resolve($cfg, $project);
    }

    /** 換了 cookie 或權限資料之後（同一請求內）要重新解析時才需要。 */
    public static function reset(): void { self::$actors = []; }

    public static function can(array $cfg, ?string $project, string $key): bool {
        return self::actor($cfg, $project)->can($project, $key);
    }

    /**
     * 端點的唯一關卡：先權限、後 CSRF（POST 的 csrf 欄位對上 Actor 的衍生值），任一失敗直接 403 結束。
     * $denyMessage 是缺權限時給使用者看的訊息；CSRF 失敗訊息固定。
     * 預設以 JSON 403 結束；表單頁可傳 $onFail(string $reason, string $message)（$reason 為 'deny'｜'csrf'），
     * 由呼叫端自己輸出頁面式錯誤並結束請求。
     */
    public static function require(array $cfg, ?string $project, string $key, bool $csrf = true, ?string $denyMessage = null, ?callable $onFail = null): Actor {
        $fail = $onFail ?? fn(string $reason, string $message) => json_out(['error' => $message], 403);
        $actor = self::actor($cfg, $project);
        if (!$actor->can($project, $key)) {
            $fail('deny', $denyMessage ?? auth_msg('auth_deny_default'));
        }
        if ($csrf) {
            $expected = $actor->csrf($project);
            if ($expected === null || !hash_equals($expected, (string)($_POST['csrf'] ?? ''))) {
                $fail('csrf', auth_msg('auth_csrf_invalid'));
            }
        }
        return $actor;
    }

    /** 此請求身分具備某專案層級權限鍵的所有專案。 */
    public static function projectsWith(array $cfg, string $key): array {
        return array_values(array_filter(store_projects($cfg), fn($p) => self::can($cfg, $p, $key)));
    }
}
