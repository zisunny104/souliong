<?php
/**
 * Souliong 網址表 —— 全站唯一一份「網址長什麼樣」的定義。
 *
 * 為什麼要有這支：後台網址原本是各檔案自己黏字串黏出來的（`?api=admin` 一支檔案裡出現 47 次，
 * 「還原 app 掛載根目錄」那段計算被複製了七份，其中兩份的邊界情況還算得不一樣）。改一次網址形狀就要全域搜尋
 * 改一輪，而且漏改的地方不會報錯，只會在某些部署下靜靜連到錯的地方。這裡把「有哪些路徑段」與
 * 「怎麼組回網址」收在同一個檔案：
 *   - index.php 用 Route::parseManager() 把網址拆開
 *   - 其餘檔案一律用 Route::manager()／Route::backup*()／Route::tool()／Route::map() 組回來
 *   - 前端要用到的後台網址由 view.php 放進 $APP['manager']，viewer.core.js 只讀不拼
 * 拆與組共用同一組常數，所以不會出現「拆得開卻組不回去」的歪斜。
 *
 * 後台網址形狀（後台自成一區：總覽是根，單張地圖掛在它底下）：
 *   <base>/manager                          全部地圖總覽（登入後的落點）
 *   <base>/manager/<pane>                   總覽的分頁
 *   <base>/manager/<mapid>                  單張地圖
 *   <base>/manager/<mapid>/<pane>           單張地圖的分頁
 *   <base>/manager/logout                   登出
 *   <base>/manager/backup.zip               全站備份
 *   <base>/manager/<mapid>/backup.zip       單張地圖備份
 *   <base>/manager/packs/<id>.zip           主題包匯出
 *   <base>/manager/layers/<id>.zip          全站圖層匯出
 *   <base>/manager/<mapid>/layers/<id>.zip  專案圖層匯出
 *
 * 舊網址（`?api=admin`、`/admin`、`/<mapid>/manager|admin|edit`）仍然有效，由 manager.php 對 GET
 * 導向上面的正規形式，印出去的東西與既有書籤不會失效。
 */
final class Route
{
    /** 後台入口的正式名稱；別名是為了讓舊書籤與已經印出去的東西不失效而保留 */
    public const MANAGER = 'manager';
    public const MANAGER_ALIASES = ['manager', 'admin', 'edit'];

    /** 後台分頁。這份清單同時決定「網址上允許出現什麼」與前端 pane-<name> 的 id，只有這一份 */
    public const PANES = ['overview', 'records', 'access', 'tools'];

    /** 後台維護工具（各自是 index.php 的一條路由） */
    public const TOOLS = ['exiffix', 'thumbfix', 'tilecut', 'region3d', 'layermigrate'];

    /** manager 路徑裡的保留字。地圖代號撞到這些字時，parseManager() 的 $isProject 會讓真實資料優先 */
    public const LOGOUT = 'logout';
    public const BACKUP = 'backup.zip';   // 地圖代號只允許 [a-z0-9_-]，含點的字串永遠不可能撞名
    public const PACKS  = 'packs';
    public const LAYERS = 'layers';

    private static ?string $base = null;
    private static ?string $origin = null;

    // ── 掛載位置 ────────────────────────────────────────────────────────────

    /**
     * app 掛載根目錄，結尾一定有斜線（例：`/souliong/`、獨立部署時是 `/`）。
     * 不能用「目前網址去掉 query string」代替：後台可能是從 /manager/<mapid>/tools 這種深路徑
     * 進來的，那樣算出來的 base 會多黏後面幾段，組出的公開網址與分享連結會整個是壞的。
     */
    public static function base(): string
    {
        if (self::$base !== null) return self::$base;
        $appName = $GLOBALS['_APP']['name'] ?? basename(dirname(__DIR__));
        $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $pos = strpos($path, '/' . $appName);
        return self::$base = $pos !== false
            ? rtrim(substr($path, 0, $pos + strlen($appName) + 1), '/') . '/'
            : '/';
    }

    /** 協定＋主機（例：`https://example.org`），組要給人複製貼上的絕對網址用 */
    public static function origin(): string
    {
        if (self::$origin !== null) return self::$origin;
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        return self::$origin = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
    }

    /** 把本檔產出的站內路徑補成絕對網址。分享連結、跨端點的「回後台」才需要，站內導覽不必 */
    public static function abs(string $path): string
    {
        return self::origin() . $path;
    }

    // ── 前台 ────────────────────────────────────────────────────────────────

    /** 公開地圖頁；$project 留空就是平台首頁 */
    public static function map(string $project = ''): string
    {
        return self::base() . ($project !== '' ? rawurlencode($project) : '');
    }

    // ── 後台 ────────────────────────────────────────────────────────────────

    /**
     * 後台頁面網址。$project 留空＝全部地圖總覽，$pane 留空＝沿用使用者上次停留的分頁。
     * $frag 是要接在後面的 fragment（不含 #），目前只有一次性的 activate token 在用。
     */
    public static function manager(string $project = '', string $pane = '', string $frag = ''): string
    {
        $u = self::base() . self::MANAGER;
        if ($project !== '') $u .= '/' . rawurlencode($project);
        if ($pane !== '' && in_array($pane, self::PANES, true)) $u .= '/' . $pane;
        return $u . ($frag !== '' ? '#' . $frag : '');
    }

    public static function logout(): string
    {
        return self::base() . self::MANAGER . '/' . self::LOGOUT;
    }

    /** 全站備份（所有地圖＋狀態檔） */
    public static function backupAll(): string
    {
        return self::base() . self::MANAGER . '/' . self::BACKUP;
    }

    /** 單張地圖備份 */
    public static function backupProject(string $project): string
    {
        return self::base() . self::MANAGER . '/' . rawurlencode($project) . '/' . self::BACKUP;
    }

    /** 主題包匯出。$project 留空＝全站層，非空＝該地圖的專案層（兩層作用域見 api/packs.php） */
    public static function backupPack(string $packId, string $project = ''): string
    {
        $u = self::base() . self::MANAGER;
        if ($project !== '') $u .= '/' . rawurlencode($project);
        return $u . '/' . self::PACKS . '/' . rawurlencode($packId) . '.zip';
    }

    /**
     * 圖層匯出。$project 留空＝全站層，非空＝該地圖的專案層（兩層作用域見 api/layers.php）。
     * $withSrc＝true 時多帶 `?src=1`，後端會把 layersrc/ 的原稿一併打包（只有專案層才有原稿，
     * 全站層即使傳 true 也不影響輸出——沒東西可帶）。
     */
    public static function backupLayer(string $layerId, string $project = '', bool $withSrc = false): string
    {
        $u = self::base() . self::MANAGER;
        if ($project !== '') $u .= '/' . rawurlencode($project);
        $u .= '/' . self::LAYERS . '/' . rawurlencode($layerId) . '.zip';
        return $withSrc ? $u . '?src=1' : $u;
    }

    /**
     * query 形式的 API 端點（photo／media／list…）。這些不是「頁面」而是資料出口，
     * 參數常含斜線（`f=<project>/<file>`），維持 ?api= 形式比路徑式好轉義，所以刻意不改。
     */
    public static function api(string $action, array $qs = []): string
    {
        return self::base() . '?' . http_build_query(['api' => $action] + $qs);
    }

    /** 維護工具頁（exiffix／thumbfix／tilecut）；$extra 是該工具自己的參數 */
    public static function tool(string $tool, string $project = '', array $extra = []): string
    {
        $qs = [];
        if ($project !== '') $qs['project'] = $project;
        $qs += $extra;
        return self::base() . $tool . ($qs ? '?' . http_build_query($qs) : '');
    }

    // ── 拆網址（index.php 用）───────────────────────────────────────────────

    /**
     * 把 manager 之後的路徑段拆成一組 GET 參數，回傳的鍵就是 manager.php 讀的那些。
     *
     * $tail       manager 這一段之後的路徑段（已 rawurldecode）
     * $isProject  判斷一個代號是不是真的存在的地圖。用途是消歧義：地圖代號跟 PANES／PACKS／
     *             LAYERS 撞名時，**真實資料優先**——不然一張叫 tools 的地圖會永遠打不開後台。
     */
    public static function parseManager(array $tail, callable $isProject): array
    {
        $out = [];
        $seg = array_values(array_filter($tail, 'strlen'));
        if (!$seg) return $out;

        $head = $seg[0];
        $rest = array_slice($seg, 1);

        // 全域層級的保留字（都不帶地圖代號）
        if ($head === self::BACKUP) return ['backup' => 'all'];
        if (!$isProject($head)) {
            if ($head === self::LOGOUT && !$rest) return ['logout' => '1'];
            if ($head === self::PACKS && $rest) {
                return ['backup' => 'pack', 'pack' => self::unzip($rest[0])];
            }
            if ($head === self::LAYERS && $rest) {
                return ['backup' => 'layer', 'layer' => self::unzip($rest[0])];
            }
            // 總覽自己的分頁：/manager/<pane>
            if (in_array($head, self::PANES, true) && !$rest) {
                return ['pane' => $head];
            }
        }

        // 其餘一律當地圖代號，後面再接該地圖底下的動作
        $out['project'] = $head;
        if (!$rest) return $out;
        $sub = $rest[0];
        if ($sub === self::BACKUP) {
            $out['backup'] = 'project';
        } elseif ($sub === self::LAYERS && isset($rest[1])) {
            $out['backup'] = 'layer';
            $out['layer'] = self::unzip($rest[1]);
        } elseif ($sub === self::PACKS && isset($rest[1])) {
            $out['backup'] = 'pack';
            $out['pack'] = self::unzip($rest[1]);
        } elseif (in_array($sub, self::PANES, true)) {
            $out['pane'] = $sub;
        }
        return $out;
    }

    /** `<id>.zip` → `<id>`；沒有副檔名就原樣回傳（實際的字元把關留給呼叫端既有的白名單） */
    private static function unzip(string $s): string
    {
        return preg_match('/^(.*)\.zip$/i', $s, $m) ? $m[1] : $s;
    }
}
