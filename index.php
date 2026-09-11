<?php
/**
 * Souliong 循跡 — KoiLiSu app 入口
 * KoiLiSu 框架走「路徑」路由且不供應靜態檔，所以：
 *   - API 用路徑式：<base>/list/<project>、<base>/upload(POST)、<base>/photo/<project>/<file>、<base>/admin
 *   - CSS/JS/資料由 view.php 內嵌輸出
 * 路徑參數自 REQUEST_URI 解析（不依賴 query string 是否被保留）。
 */
// 錯誤一律寫進伺服器日誌、不回傳給瀏覽器：各 API 檔案內的例外處理已各自依 debug flag
// 決定要不要在回應裡帶內部細節，但未被攔到的致命錯誤／警告則完全看 php.ini 預設值，
// 這裡補上一個不依賴環境設定的底線。
ini_set('display_errors', '0');
error_reporting(E_ALL);
$config = include __DIR__ . '/config.php';
require_once __DIR__ . '/api/routes.php';   // 網址表：路徑段怎麼拆、網址怎麼組，全站只有這一份定義

// 判斷一個代號是不是真的存在的地圖。後台路徑拆解要靠它消歧義（見 Route::parseManager()）
$isProject = fn(string $id): bool =>
    $id !== '' && preg_match('/^[a-z0-9_-]+$/', $id) === 1 && is_dir(__DIR__ . '/projects/' . $id);

$appName = $_APP['name'] ?? basename(__DIR__);
$reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$m = strpos($reqPath, '/' . $appName);
if ($m !== false) {
    $after = ltrim(substr($reqPath, $m + strlen($appName) + 1), '/');
} else {
    $after = ltrim($reqPath, '/');   // 獨立部署
}
$seg = array_values(array_filter(explode('/', rawurldecode($after)), 'strlen'));
// 動作來源優先序：路徑段 → ?api= → 框架 action → index（兩種寫法都相容）
$action = $seg[0] ?? null;
if ($action === null || $action === '') { $action = $_GET['api'] ?? ($_APP['action'] ?? 'index'); }

switch ($action) {
    case 'list':
        $_GET['project'] = $seg[1] ?? ($_GET['project'] ?? '');
        require __DIR__ . '/api/list.php';
        return;
    case 'upload':
        require __DIR__ . '/api/upload.php';
        return;
    case 'photo':
        $_GET['f'] = count($seg) > 1 ? implode('/', array_slice($seg, 1)) : ($_GET['f'] ?? '');
        require __DIR__ . '/api/photo.php';
        return;
    case 'media':
        $_GET['f'] = count($seg) > 1 ? implode('/', array_slice($seg, 1)) : ($_GET['f'] ?? '');
        require __DIR__ . '/api/media.php';   // 影片／音訊，支援 Range（見該檔說明）
        return;
    case 'layer':
        $_GET['f'] = count($seg) > 1 ? implode('/', array_slice($seg, 1)) : ($_GET['f'] ?? '');
        require __DIR__ . '/api/layerfile.php';   // 圖層圖檔：<base>/layer/<project>/<id>/<路徑>
        return;
    case 'model3d':
        $_GET['f'] = count($seg) > 1 ? implode('/', array_slice($seg, 1)) : ($_GET['f'] ?? '');
        require __DIR__ . '/api/model3dfile.php';   // 自訂 3D 模型檔：<base>/model3d/<project>/<id>/model.glb
        return;
    case 'appasset':
        require __DIR__ . '/api/appasset.php';   // 第一方 CSS／JS 靜態資源：<base>?api=appasset&f=...
        return;
    case 'cover':
        // 只在真的有路徑段時才覆寫：POST 時 project 是夾在表單裡的（見 api/cover.php 的
        // $_GET['project'] ?? $_POST['project'] ?? ''），這裡若像其他 case 一樣強制補一個
        // 空字串，會讓那個 ?? 永遠命中這個空字串、吃不到 POST 過來的值。
        if (isset($seg[1])) $_GET['project'] = $seg[1];
        require __DIR__ . '/api/cover.php';   // 專案封面／地圖快照：GET 輸出圖檔，POST 限管理者 upload/auto/reset
        return;
    case 'pinmark':
        if (isset($seg[1])) $_GET['project'] = $seg[1];
        require __DIR__ . '/api/pinmark.php';   // 地圖標記自訂圖片：GET 輸出圖檔（上傳/重設在後台 manager.php）
        return;
    case 'delete':
        require __DIR__ . '/api/delete.php';
        return;
    case 'editentry':
        require __DIR__ . '/api/editentry.php';
        return;
    case 'editpoint':
        require __DIR__ . '/api/editpoint.php';
        return;
    case 'newpoint':
        require __DIR__ . '/api/newpoint.php';   // 訪客／管理者建立新地點（權限見 meta.json 的 contrib.newPoint）
        return;
    case 'unlock':
        require __DIR__ . '/api/unlock.php';
        return;
    case 'exiffix':
        require __DIR__ . '/api/exiffix.php';   // 常駐維護工具：修復投稿缺少相機 EXIF 資訊
        return;
    case 'thumbfix':
        require __DIR__ . '/api/thumbfix.php';  // 備援維護工具：自動產縮圖失敗時，由瀏覽器補產舊投稿縮圖
        return;
    case 'tilecut':
        require __DIR__ . '/api/tilecut.php';  // 常駐工具：把一張自繪插畫切成圖磚金字塔，落地成專案層
        return;
    case 'region3d':
        require __DIR__ . '/api/region3d.php';  // 常駐工具：3D 區域／自訂模型編輯器
        return;
    case 'layermigrate':
        require __DIR__ . '/api/layermigrate.php';  // 常駐工具：把跟隨全站預設的舊專案圖層設定凍結明確
        return;
    case 'stat':
        require __DIR__ . '/api/stat.php';
        return;
    case 'admin':
    case 'manager':
        // 後台：<base>/manager[/<mapid>][/<pane>|/backup.zip|/layers/<id>.zip]、<base>/manager/logout。
        // 完整清單與拆解規則都在 api/routes.php，這裡只把拆出來的結果餵進 $_GET；路徑贏過 query string，
        // 這樣 /manager/100chairs?project=別的 不會出現兩個真相。相容 /admin 與 ?api=admin（manager.php 會導正）。
        $_GET = Route::parseManager(array_slice($seg, 1), $isProject) + $_GET;
        require __DIR__ . '/api/manager.php';
        return;
    case 'privacy':
        include __DIR__ . '/pages/privacy.php';    // 隱私與資料說明：<base>/privacy
        return;
    default:
        // 地圖或首頁：<base>/<mapid> 開該地圖；<base>/ 顯示地圖清單
        $proj = ($action !== 'index' && $action !== '') ? $action : ($_GET['p'] ?? '');
        $proj = preg_replace('/[^a-z0-9_-]/', '', $proj);
        if ($proj !== '' && is_dir(__DIR__ . '/projects/' . $proj)) {
            // 舊形狀 <base>/<mapid>/manager|edit|admin → 該專案管理。正規網址已改成 <base>/manager/<mapid>，
            // 這條留著讓既有書籤與印出去的東西不失效；manager.php 收到後會把 GET 導向正規網址。
            if (isset($seg[1]) && in_array($seg[1], Route::MANAGER_ALIASES, true)) {
                $_GET = Route::parseManager(array_merge([$proj], array_slice($seg, 2)), $isProject) + $_GET;
                require __DIR__ . '/api/manager.php';
                return;
            }
            $_GET['p'] = $proj;
            include __DIR__ . '/pages/view.php';
        } else {
            include __DIR__ . '/pages/landing.php';
        }
        return;
}
