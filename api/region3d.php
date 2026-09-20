<?php
// 常駐工具：3D 區域／自訂模型編輯器（projects/<proj>/regions3d/<id>/，見 api/regions3d.php 的資料結構說明）。
//
// 跟 tilecut.php 的差異：這裡沒有圖磚金字塔，一個區域固定只有兩個檔案（region.json + model.glb），
// 所以不需要「清乾淨舊磚避免殘影」那套邏輯——model.glb 一律整檔覆蓋（srcput 最後一塊到齊就 rename
// 蓋過去），region.json 一律整份重寫，沒有「上一版部分殘留」這種中間狀態。也因此 begin 動作
// 不需要清空舊資料夾：只編輯多邊形／參數、不重新上傳模型時，舊的 model.glb 必須原封不動留著
// ——這是刻意的（呼應「編輯不可靜默遺失既有欄位」的原則），不是遺漏。
//
// ── 排除清單怎麼算 ──
// 這支工具內嵌一個管理端專用的 MapLibre 地圖，接的是跟公開端 assets/js/plugins/map3d.js 完全
// 相同的 provider（api/config.php 的 map3d_style_url）。畫完多邊形、按下「儲存」的當下，那個區域
// 的建物圖磚保證已經完整載入在畫面上，前端用 queryRenderedFeatures 把落在多邊形內的建物抓出來，
// 連同多邊形本身（recovery path）一起送給 finish 動作寫進 region.json。伺服器端不重算、只驗證與
// 落地——vector tile 的建物幾何在 PHP 這邊完全拿不到，這步天生只能在瀏覽器做。
//
// ── 待驗證事項（尚未在真實瀏覽器跑過，見 magical-plotting-ladybug 計畫「驗證方式」第 1 點）──
// OpenFreeMap 的 building 圖層 feature.id 是否穩定（同一棟建物重整頁面後 id 不變）目前只是設計假設。
// 若某次 queryRenderedFeatures 抓到的建物沒有 feature.id（top-level id，不是 properties 裡的欄位），
// 排除機制對那些建物就是做不到，前端會用 region3d_missing_id_warn 當場提示管理員，但不會擋下存檔
// ——這不是靜默失敗，是留給人判斷。真的發生大規模 id 失效，復原路徑是「重新掃描」（action=rescan）：
// 多邊形不變，只重新查一次、覆寫排除清單。
require __DIR__ . '/store.php';
require __DIR__ . '/security.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/routes.php';
require_once __DIR__ . '/regions3d.php';
$cfg = require __DIR__ . '/config.php';
rate_limit($cfg, 'manage');
[$LANG, $DICT] = i18n_init();
$t  = fn(string $key, array $vars = []): string => htmlspecialchars(i18n_t($DICT, $key, $vars), ENT_QUOTES);
$tr = fn(string $key, array $vars = []): string => i18n_t($DICT, $key, $vars);
$esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$backProject = preg_replace('/[^a-z0-9_-]/', '', $_GET['project'] ?? '');
$adminUrl = $esc(Route::abs(Route::manager($backProject, 'tools')));

// 寫入動作（begin/srcput/finish/rescan）與頁面都看 edit_3d_regions，這把鑰匙可下放給特定專案 PIN
// （security.php 的 pin_default_perms()）。寫入動作的關卡由 Auth::require 一次做完專案存在、權限與 CSRF。
$requireProj = function (string $p) use ($cfg, $tr): Actor {
    if ($p === '' || preg_match('/^[a-z0-9_-]+$/', $p) !== 1 || !is_dir(project_dir($cfg, $p))) {
        json_out(['error' => $tr('no_permission_title')], 403);
    }
    return Auth::require($cfg, $p, 'edit_3d_regions', true, $tr('no_permission_title'));
};

/** GeoJSON Polygon 驗證：單一外環、經緯度範圍合理、點數有上限。回傳整理過（只留 [lon,lat]）的結構，不合法回 null。 */
function region3d_valid_polygon($raw): ?array
{
    $p = json_decode((string)$raw, true);
    if (!is_array($p) || ($p['type'] ?? '') !== 'Polygon' || !is_array($p['coordinates'] ?? null) || !is_array($p['coordinates'][0] ?? null)) {
        return null;
    }
    $ring = $p['coordinates'][0];
    if (count($ring) < 4 || count($ring) > 500) {
        return null;   // 三個點才畫得出面，加上收尾點至少 4 個；上限是防呆，不是實際需求
    }
    $clean = [];
    foreach ($ring as $pt) {
        if (!is_array($pt) || count($pt) < 2 || !is_numeric($pt[0]) || !is_numeric($pt[1])) {
            return null;
        }
        $lon = (float)$pt[0];
        $lat = (float)$pt[1];
        if (abs($lat) > 85.0511 || abs($lon) > 180) {
            return null;   // Web Mercator 的實際上下限，同 souliong_layer_bounds_valid() 的判準
        }
        $clean[] = [$lon, $lat];
    }
    return ['type' => 'Polygon', 'coordinates' => [$clean]];
}

/** 排除建物 id 清單驗證：只留 int/string、去重、設一個防呆上限。 */
function region3d_valid_ids($raw): array
{
    $ids = json_decode((string)$raw, true);
    if (!is_array($ids)) {
        return [];
    }
    $out = [];
    foreach ($ids as $v) {
        if ((is_int($v) || is_string($v)) && count($out) < 20000) {
            $out[] = $v;
        }
    }
    return array_values(array_unique($out, SORT_REGULAR));
}

// ── 開工：建立（或確認可覆蓋）區域資料夾（POST，JSON 回應） ──
// 跟 tilecut 的 begin 不同：這裡不清空舊檔——只改多邊形或模型參數、不重新上傳 model.glb 時，
// 舊模型必須原封不動留著（見檔頭說明）。
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'begin') {
    $project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
    $actor = $requireProj($project);
    $id  = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '', $_POST['id'] ?? ''));
    $dir = souliong_region3d_dir($cfg, $project, $id);
    if ($dir === null) {
        json_out(['error' => $tr('region3d_bad_id_msg')], 400);
    }
    $existed = is_dir($dir);
    if ($existed && empty($_POST['overwrite'])) {
        json_out(['error' => $tr('region3d_exists_msg', ['id' => $id])], 409);
    }
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        json_out(['error' => $tr('region3d_mkdir_failed_msg')], 500);
    }
    json_out(['ok' => true, 'replaced' => $existed]);
}

// ── 收模型：分塊上傳 .glb（POST，JSON 回應） ──
// 一個區域固定只有一個模型檔，不像 tilecut 的原稿有多張、要靠 idx 對照，所以協定比照 srcput
// 但少了 idx 欄位；分塊道理同 tilecut：模型檔可能到幾 MB，單一 POST 容易撞 upload_max_filesize
// 或逾時，offset 對不上就回 409 附帶伺服器手上的長度，前端照那個續傳。
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'srcput') {
    $project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
    $actor = $requireProj($project);
    $id  = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '', $_POST['id'] ?? ''));
    $dir = souliong_region3d_dir($cfg, $project, $id);
    if ($dir === null || !is_dir($dir)) {
        json_out(['error' => $tr('region3d_not_found_msg')], 404);   // begin 沒跑過就不該有半成品
    }
    $off = (int)($_POST['offset'] ?? -1);
    $tmp = (string)($_FILES['chunk']['tmp_name'] ?? '');
    if ($off < 0 || ($_FILES['chunk']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
        json_out(['error' => $tr('region3d_chunk_failed_msg')], 400);
    }
    // 半成品先叫 .model.part：驗過 glTF 魔數才改名成 model.glb，中途斷線留下的殘骸不會被當成模型
    $part = $dir . '/.model.part';
    if ($off === 0) {
        @unlink($part);
    }
    $have = is_file($part) ? (int)filesize($part) : 0;
    if ($have !== $off) {
        json_out(['error' => $tr('region3d_chunk_resend_msg'), 'have' => $have], 409);
    }
    $lim  = (int)($cfg['model3d_max_bytes'] ?? 24 * 1024 * 1024);
    $size = (int)($_FILES['chunk']['size'] ?? 0);
    if ($off + $size > $lim) {
        json_out(['error' => $tr('region3d_model_too_big_msg', ['mb' => (string)(int)round($lim / 1048576)])], 413);
    }
    $in  = @fopen($tmp, 'rb');
    $out = $in ? @fopen($part, $off === 0 ? 'wb' : 'ab') : false;
    $ok  = $in && $out && stream_copy_to_stream($in, $out) !== false;
    if ($in) {
        fclose($in);
    }
    if ($out) {
        fclose($out);
    }
    if (!$ok) {
        json_out(['error' => $tr('region3d_chunk_failed_msg')], 500);
    }
    if (empty($_POST['last'])) {
        json_out(['ok' => true, 'have' => (int)filesize($part)]);
    }
    // 最後一塊到齊，整個檔在手上了才驗——分塊送到一半的檔案本來就認不出格式。
    // glTF 二進位規格的 magic 固定是 ASCII 字串 "glTF"（uint32 0x46546C67 用小端序拆開就是這四個
    //位元組），比對前 4 bytes 就夠，不需要真的解析整個 glb 容器。
    $head = (string)@file_get_contents($part, false, null, 0, 4);
    if ($head !== 'glTF') {
        @unlink($part);
        json_out(['error' => $tr('region3d_model_bad_type_msg')], 415);
    }
    if (!@rename($part, $dir . '/model.glb')) {
        json_out(['error' => $tr('region3d_mkdir_failed_msg')], 500);
    }
    json_out(['ok' => true]);
}

// ── 收尾：寫 region.json（POST，JSON 回應） ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'finish') {
    $project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
    $actor = $requireProj($project);
    $id  = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '', $_POST['id'] ?? ''));
    $dir = souliong_region3d_dir($cfg, $project, $id);
    if ($dir === null || !is_dir($dir)) {
        json_out(['error' => $tr('region3d_not_found_msg')], 404);
    }
    if (!is_file($dir . '/model.glb')) {
        json_out(['error' => $tr('region3d_model_missing_msg')], 400);   // 這次沒傳、之前也沒傳過
    }
    $polygon = region3d_valid_polygon($_POST['polygon'] ?? '');
    if ($polygon === null) {
        json_out(['error' => $tr('region3d_bad_polygon_msg')], 400);
    }
    $anchor = json_decode((string)($_POST['anchor'] ?? ''), true);
    if (
        !is_array($anchor) || count($anchor) < 2 || !is_numeric($anchor[0]) || !is_numeric($anchor[1])
        || abs((float)$anchor[0]) > 85.0511 || abs((float)$anchor[1]) > 180
    ) {
        json_out(['error' => $tr('region3d_bad_model_msg')], 400);
    }
    $num = fn($k, $d = 0.0) => is_numeric($_POST[$k] ?? null) ? (float)$_POST[$k] : $d;
    $rotation = fmod($num('rotationDeg'), 360);
    $scale = max(0.001, min(10000, $num('scale', 1.0)));
    $altOffset = max(-1000, min(10000, $num('altitudeOffset')));
    $label = trim((string)($_POST['label'] ?? ''));
    $attr = trim((string)($_POST['attribution'] ?? ''));
    $ids = region3d_valid_ids($_POST['excludedBuildingIds'] ?? '');

    $manifest = [
        'label' => mb_substr($label !== '' ? $label : $id, 0, 60),
        'polygon' => $polygon,
        'excludedBuildingIds' => $ids,
        'model' => [
            'anchor' => [(float)$anchor[0], (float)$anchor[1]],
            'rotationDeg' => $rotation,
            'scale' => $scale,
            'altitudeOffset' => $altOffset,
        ],
        'generated' => ['tool' => 'region3d', 'at' => gmdate('c')],
    ];
    if ($attr !== '') {
        $manifest['attribution'] = mb_substr($attr, 0, 500);
    }
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($dir . '/region.json', $json, LOCK_EX) === false) {
        json_out(['error' => $tr('region3d_mkdir_failed_msg')], 500);
    }
    audit_log($cfg, $actor->audit(), 'region3d_save', $project, $id . ' x' . count($ids));
    json_out(['ok' => true, 'id' => $id]);
}

// ── 重新掃描：多邊形與模型都不變，只重算排除清單並覆寫（POST，JSON 回應） ──
// 這是 feature.id 萬一失效的復原路徑（見檔頭說明）：前端重新對照目前畫面上的多邊形查一次
// queryRenderedFeatures，把新算出來的清單送過來，這裡只負責驗證與覆寫那一個欄位。
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rescan') {
    $project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
    $actor = $requireProj($project);
    $id  = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '', $_POST['id'] ?? ''));
    $dir = souliong_region3d_dir($cfg, $project, $id);
    $mf  = $dir !== null ? $dir . '/region.json' : null;
    if ($mf === null || !is_file($mf)) {
        json_out(['error' => $tr('region3d_not_found_msg')], 404);
    }
    $manifest = json_decode((string)@file_get_contents($mf), true);
    if (!is_array($manifest)) {
        json_out(['error' => $tr('region3d_not_found_msg')], 404);
    }
    $ids = region3d_valid_ids($_POST['excludedBuildingIds'] ?? '');
    $manifest['excludedBuildingIds'] = $ids;
    $manifest['rescannedAt'] = gmdate('c');
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($mf, $json, LOCK_EX) === false) {
        json_out(['error' => $tr('region3d_mkdir_failed_msg')], 500);
    }
    audit_log($cfg, $actor->audit(), 'region3d_rescan', $project, $id . ' x' . count($ids));
    json_out(['ok' => true, 'count' => count($ids)]);
}

// ── 頁面 ──
$allProjects = Auth::projectsWith($cfg, 'edit_3d_regions');
if (Auth::actor($cfg, null)->kind() !== 'primary' && !$allProjects) {
    http_response_code(401);
    header('Content-Type: text/html; charset=utf-8');
    echo '<p>' . $tr('region3d_login_required_msg', ['url' => $adminUrl]) . '</p>';
    exit;
}

$reqProject = in_array($backProject, $allProjects, true) ? $backProject : ($allProjects[0] ?? '');
$csrf = (string)Auth::actor($cfg, $reqProject)->csrf($reqProject);

// 分塊多大：跟 tilecut.php 同一套算法（取 upload_max_filesize 與 post_max_size 的較小者留兩成餘裕）。
$iniBytes = function (string $k): int {
    $v = trim((string)ini_get($k));
    $mul = ['k' => 1024, 'm' => 1048576, 'g' => 1073741824][strtolower(substr($v, -1))] ?? 1;
    $n = (int)((float)$v * $mul);
    return $n > 0 ? $n : PHP_INT_MAX;
};
$srcChunk = max(256 * 1024, min(4 * 1024 * 1024, (int)(min($iniBytes('upload_max_filesize'), $iniBytes('post_max_size')) * 0.8)));

// 「重新編輯」：region.json 本身就是可編輯狀態，不像 tilecut 需要另一份 edit.json——直接讀回來即可。
$EDIT = null;
$loadId = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['load'] ?? ''));
if ($loadId !== '' && $reqProject !== '') {
    $dir = souliong_region3d_dir($cfg, $reqProject, $loadId);
    $doc = $dir !== null && is_file($dir . '/region.json')
        ? json_decode((string)@file_get_contents($dir . '/region.json'), true) : null;
    if (is_array($doc)) {
        $doc['id'] = $loadId;
        $EDIT = $doc;
    }
}

$map3dStyleUrl = (string)($cfg['map3d_style_url'] ?? '');
$map3dKey = (string)($cfg['map3d_key'] ?? '');
?>
<!doctype html>
<html lang="<?= $LANG === 'en' ? 'en' : 'zh-Hant' ?>">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex">
  <title><?= $t('region3d_title') ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <!-- 版本要跟 pages/view.php 內嵌給訪客端的那份一致，兩邊各自升級的話管理端畫的區域跟訪客端
       實際套用的建物圖層可能對不起來 -->
  <link rel="stylesheet" href="https://unpkg.com/maplibre-gl@6.6.0/dist/maplibre-gl.css">
  <style>
    :root {
      --bg: #f6f5f2;
      --fg: #1c1a17;
      --muted: #7a756c;
      --line: #e2ddd3;
      --card: #fff;
      --accent: #b5482e;
      --accent-fg: #fff;
      --sp-1: 0.25rem;
      --sp-2: 0.5rem;
      --sp-3: 0.75rem;
      --sp-4: 1rem;
      --sp-5: 1.5rem;
      --tap: 1.75rem
    }

    @media (prefers-color-scheme:dark) {
      :root {
        --bg: #17140f;
        --fg: #f1ede6;
        --muted: #a69d8e;
        --line: #322c22;
        --card: #211c15;
        --accent: #e0663f;
        --accent-fg: #1c1a17
      }
    }

    * {
      box-sizing: border-box
    }

    body {
      margin: 0;
      background: var(--bg);
      color: var(--fg);
      font: 0.9375rem/1.6 system-ui, -apple-system, "Noto Sans TC", sans-serif;
      padding: var(--sp-4) var(--sp-4) 3.75rem
    }

    .wrap {
      max-width: 52rem;
      margin: 0 auto
    }

    .langsw {
      max-width: 52rem;
      margin: 0 auto var(--sp-2);
      display: flex;
      justify-content: flex-end;
      gap: var(--sp-1);
      font-size: 0.75rem
    }

    .langsw a {
      display: inline-flex;
      align-items: center;
      min-height: var(--tap);
      color: var(--muted);
      text-decoration: none;
      padding: 0 var(--sp-3);
      border-radius: 999px;
      border: 1px solid transparent
    }

    .langsw a.on {
      color: var(--fg);
      font-weight: 700;
      background: var(--card);
      border-color: var(--line)
    }

    h1 {
      font-size: 1.125rem;
      line-height: 1.4;
      margin: 0 0 var(--sp-4);
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 0 var(--sp-2)
    }

    h2 {
      font-size: 0.875rem;
      margin: 0 0 var(--sp-2);
      display: flex;
      align-items: center;
      gap: var(--sp-2)
    }

    .warn {
      border: 1px solid var(--accent);
      color: var(--accent);
      border-radius: var(--sp-3);
      padding: var(--sp-3) var(--sp-4);
      font-size: 0.8125rem;
      margin-bottom: var(--sp-4)
    }

    .card {
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: 0.875rem;
      padding: var(--sp-4) var(--sp-5);
      margin-bottom: var(--sp-4)
    }

    label {
      display: block;
      font-size: 0.8125rem;
      color: var(--muted);
      margin-bottom: var(--sp-1)
    }

    select,
    input[type=text],
    input[type=number] {
      width: 100%;
      border: 1px solid var(--line);
      border-radius: 0.625rem;
      background: var(--bg);
      color: var(--fg);
      padding: var(--sp-2) var(--sp-3);
      font-size: 0.875rem;
      min-height: 2.25rem
    }

    label span {
      font-weight: 700;
      color: var(--fg)
    }

    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr));
      gap: var(--sp-3);
      margin-bottom: var(--sp-3)
    }

    .grid>div {
      min-width: 0
    }

    button {
      border: none;
      background: var(--accent);
      color: var(--accent-fg);
      border-radius: 0.625rem;
      padding: var(--sp-2) var(--sp-4);
      min-height: 2.25rem;
      font-size: 0.875rem;
      font-weight: 700;
      cursor: pointer
    }

    button.ghost {
      background: var(--card);
      color: var(--fg);
      border: 1px solid var(--line);
      font-weight: 600
    }

    button.ghost.active {
      border-color: var(--accent);
      color: var(--accent)
    }

    button:disabled {
      opacity: .5;
      cursor: default
    }

    .row {
      display: flex;
      flex-wrap: wrap;
      gap: var(--sp-2);
      align-items: center
    }

    .hint {
      font-size: 0.75rem;
      color: var(--muted)
    }

    .hint:empty {
      display: none
    }

    .hint.ok {
      color: #2a8a4a
    }

    .hint.warn2 {
      color: var(--accent)
    }

    .filebtn {
      display: inline-flex;
      align-items: center;
      gap: var(--sp-2);
      cursor: pointer;
      margin: 0
    }

    .filebtn span {
      display: inline-flex;
      align-items: center;
      gap: var(--sp-2);
      border: 1px solid var(--line);
      border-radius: .625rem;
      padding: .5rem 1rem;
      background: var(--card)
    }

    .chk {
      display: flex;
      align-items: center;
      gap: var(--sp-2);
      font-size: 0.8125rem;
      color: var(--fg);
      margin: 0
    }

    .chk input {
      width: auto;
      min-height: 0
    }

    #map {
      height: 30rem;
      border-radius: 0.75rem;
      border: 1px solid var(--line);
      margin-bottom: var(--sp-3);
      background: var(--bg)
    }

    .r3d-anchor {
      width: 20px;
      height: 20px;
      border-radius: 50% 50% 50% 0;
      transform: rotate(-45deg);
      background: var(--accent);
      border: 2px solid #fff;
      box-shadow: 0 1px 3px rgba(0, 0, 0, .4)
    }

    a {
      color: var(--accent)
    }

    .backlink {
      margin-top: var(--sp-5)
    }

    .backlink a {
      display: inline-flex;
      align-items: center;
      gap: var(--sp-2);
      min-height: 2.25rem;
      padding: 0 var(--sp-4);
      border: 1px solid var(--line);
      border-radius: 999px;
      background: var(--card);
      color: var(--fg);
      font-size: 0.8125rem;
      font-weight: 600;
      text-decoration: none
    }

    .backlink a:hover {
      border-color: var(--accent);
      color: var(--accent)
    }

    a:focus-visible,
    button:focus-visible,
    select:focus-visible,
    input:focus-visible {
      outline: 2px solid var(--accent);
      outline-offset: 2px
    }
  </style>
</head>

<body>
  <div class="langsw">
    <a href="<?= $esc(Route::tool('region3d', $backProject, ['lang' => 'zh_TW'])) ?>" class="<?= $LANG === 'zh_TW' ? 'on' : '' ?>">中文</a>
    <a href="<?= $esc(Route::tool('region3d', $backProject, ['lang' => 'en'])) ?>" class="<?= $LANG === 'en' ? 'on' : '' ?>">English</a>
  </div>
  <div class="wrap">
    <h1><i class="fa-solid fa-cube"></i> <?= $t('region3d_h1') ?></h1>
    <div class="warn"><?= $t('region3d_warn') ?></div>

    <div class="card">
      <h2><i class="fa-solid fa-1"></i> <?= $t('region3d_step_project') ?></h2>
      <div class="grid">
        <div>
          <label for="project"><?= $t('tool_select_project_label') ?></label>
          <select id="project">
            <?php foreach ($allProjects as $p): ?><option value="<?= $esc($p) ?>" <?= $p === $reqProject ? 'selected' : '' ?>><?= $esc($p) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="rid"><?= $t('region3d_id_label') ?></label>
          <input type="text" id="rid" value="<?= $esc($EDIT !== null ? $EDIT['id'] : 'landmark') ?>" pattern="[a-z0-9][a-z0-9_\-]*" maxlength="32" spellcheck="false">
        </div>
        <div>
          <label for="rlabel"><?= $t('region3d_label_label') ?></label>
          <input type="text" id="rlabel" maxlength="60" value="<?= $esc($EDIT['label'] ?? '') ?>">
        </div>
      </div>
      <div class="hint" style="margin-bottom:var(--sp-3)"><?= $t('region3d_id_hint') ?></div>
      <?php if ($EDIT !== null): ?>
      <div class="hint ok" style="margin-bottom:var(--sp-3)"><i class="fa-solid fa-rotate-left"></i> <?= $t('region3d_loaded_msg', ['id' => $EDIT['id']]) ?></div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2><i class="fa-solid fa-2"></i> <?= $t('region3d_step_draw') ?></h2>
      <div class="hint" style="margin-bottom:var(--sp-3)"><?= $t('region3d_draw_hint') ?></div>
      <div id="map"></div>
      <div class="row" style="margin-bottom:var(--sp-3)">
        <button type="button" class="ghost" id="drawbtn"><i class="fa-solid fa-draw-polygon"></i> <?= $t('region3d_draw_btn') ?></button>
        <button type="button" class="ghost" id="undobtn" disabled><i class="fa-solid fa-rotate-left"></i> <?= $t('region3d_undo_point_btn') ?></button>
        <button type="button" class="ghost" id="finishbtn" disabled><i class="fa-solid fa-check"></i> <?= $t('region3d_finish_polygon_btn') ?></button>
        <button type="button" class="ghost" id="redrawbtn"><i class="fa-solid fa-eraser"></i> <?= $t('region3d_redraw_btn') ?></button>
      </div>
      <div class="hint" style="margin-bottom:var(--sp-3)"><?= $t('region3d_anchor_hint') ?></div>
      <div class="row" style="margin-bottom:var(--sp-3)">
        <button type="button" class="ghost" id="anchorbtn"><i class="fa-solid fa-map-pin"></i> <?= $t('region3d_place_anchor_btn') ?></button>
      </div>
      <div class="grid">
        <div><label for="rot"><?= $t('region3d_rotation_label') ?></label><input type="number" id="rot" step="1" value="<?= $esc($EDIT['model']['rotationDeg'] ?? 0) ?>"></div>
        <div><label for="scale"><?= $t('region3d_scale_label') ?></label><input type="number" id="scale" step="0.01" min="0.001" value="<?= $esc($EDIT['model']['scale'] ?? 1) ?>"></div>
        <div><label for="alt"><?= $t('region3d_altitude_label') ?></label><input type="number" id="alt" step="0.1" value="<?= $esc($EDIT['model']['altitudeOffset'] ?? 0) ?>"></div>
      </div>
    </div>

    <div class="card">
      <h2><i class="fa-solid fa-3"></i> <?= $t('region3d_step_save') ?></h2>
      <div class="row">
        <label class="filebtn"><span><i class="fa-solid fa-cube"></i> <?= $t('region3d_choose_model_btn') ?></span>
          <input type="file" id="modelfile" accept=".glb,model/gltf-binary" hidden></label>
        <span class="hint" id="modelinfo"></span>
      </div>
      <div class="hint" style="margin-top:var(--sp-2)"><?= $t('region3d_model_hint') ?></div>
      <?php if ($EDIT !== null): ?>
      <div class="hint" style="margin-top:var(--sp-1)"><?= $t('region3d_model_keep_hint') ?></div>
      <?php endif; ?>
      <div style="margin-top:var(--sp-3)">
        <label for="attr"><?= $t('tilecut_attr_label') ?></label>
        <input type="text" id="attr" maxlength="500" value="<?= $esc($EDIT['attribution'] ?? '') ?>">
      </div>
      <div class="row" style="margin-top:var(--sp-3)">
        <label class="chk"><input type="checkbox" id="overwrite" <?= $EDIT !== null ? 'checked' : '' ?>> <?= $t('region3d_overwrite_label') ?></label>
      </div>
      <div class="row" style="margin-top:var(--sp-3)">
        <button id="savebtn"><i class="fa-solid fa-floppy-disk"></i> <?= $t('region3d_save_btn') ?></button>
        <?php if ($EDIT !== null): ?>
        <button type="button" class="ghost" id="rescanbtn"><i class="fa-solid fa-magnifying-glass"></i> <?= $t('region3d_rescan_btn') ?></button>
        <?php endif; ?>
      </div>
      <div class="hint" id="status" style="margin-top:var(--sp-2)"></div>
    </div>

    <p class="backlink"><a href="<?= $adminUrl ?>"><i class="fa-solid fa-arrow-left"></i> <?= $t("back_to_admin") ?></a></p>
  </div>

  <?php /* MapLibre v6 只出 ESM 版,沒有 <script src> 吃得下去的全域版本——內嵌 type="module" 匯入後
       手動掛回 window.maplibregl,下面這段用到 maplibregl 的主程式也要標成 type="module",才會排進
       同一批「文件解析完才依序執行」,晚於這段 shim（詳見 pages/view.php 對應位置的註解）。 */ ?>
  <script type="module">
import * as maplibregl from 'https://unpkg.com/maplibre-gl@6.6.0/dist/maplibre-gl.mjs';
window.maplibregl = maplibregl;
  </script>
  <script type="module">
    const I18N = <?= json_encode([
      'need_id'        => i18n_t($DICT, 'region3d_need_id_msg'),
      'need_polygon'   => i18n_t($DICT, 'region3d_need_polygon_msg'),
      'need_anchor'    => i18n_t($DICT, 'region3d_need_anchor_msg'),
      'need_model'     => i18n_t($DICT, 'region3d_need_model_msg'),
      'rate_limited'   => i18n_t($DICT, 'region3d_rate_limited_msg'),
      'uploading'      => i18n_t($DICT, 'region3d_uploading_msg'),
      'scanning'       => i18n_t($DICT, 'region3d_scanning_msg'),
      'scan_result'    => i18n_t($DICT, 'region3d_scan_result_msg'),
      'missing_id_warn' => i18n_t($DICT, 'region3d_missing_id_warn'),
      'saving'         => i18n_t($DICT, 'region3d_saving_msg'),
      'complete'       => i18n_t($DICT, 'region3d_complete_msg'),
      'rescanning'     => i18n_t($DICT, 'region3d_rescanning_msg'),
      'rescan_complete' => i18n_t($DICT, 'region3d_rescan_complete_msg'),
      'error_prefix'   => i18n_t($DICT, 'error_prefix_label'),
      'conn_failed'    => i18n_t($DICT, 'connection_failed_retry_msg'),
    ], JSON_UNESCAPED_UNICODE) ?>;
    const fmt = (str, vars) => str.replace(/\{(\w+)\}/g, (_, k) => (vars[k] != null ? vars[k] : ''));
    const csrf = <?= json_encode($csrf) ?>;
    const ROUTES = <?= json_encode(['api' => Route::abs(Route::api('region3d'))], JSON_UNESCAPED_SLASHES) ?>;
    const EDIT = <?= json_encode($EDIT, JSON_UNESCAPED_UNICODE) ?>;
    const SRCCHUNK = <?= (int)$srcChunk ?>;
    const STYLE_URL = <?= json_encode($map3dStyleUrl) ?>;
    const STYLE_KEY = <?= json_encode($map3dKey) ?>;

    const $ = id => document.getElementById(id);
    const statusEl = $('status');

    // ── 地圖：接的是跟公開端 map3d.js 完全相同的 provider，這樣畫面上看到的建物圖磚
    // 就是訪客實際會看到的那一份，查出來的 feature id 才有意義 ──
    const styleUrl = STYLE_KEY ? STYLE_URL + (STYLE_URL.includes('?') ? '&' : '?') + 'key=' + encodeURIComponent(STYLE_KEY) : STYLE_URL;
    const map = new maplibregl.Map({
      container: 'map',
      style: styleUrl,
      center: [120.69, 23.95],
      zoom: 16,
      pitch: 55,
    });
    map.addControl(new maplibregl.NavigationControl({ visualizePitch: true }), 'top-right');

    let buildingLayerId = null;
    let mode = 'idle';       // 'idle' | 'polygon' | 'anchor'
    let drawPoints = [];     // [[lng,lat], ...]，未收尾
    let completedPolygon = false;
    let anchorMarker = null;

    function closedRing(pts) {
      if (!pts.length) return pts;
      const a = pts[0], b = pts[pts.length - 1];
      return (a[0] !== b[0] || a[1] !== b[1]) ? [...pts, a] : pts;
    }

    function drawFeatureCollection() {
      const feats = [];
      if (completedPolygon && drawPoints.length >= 3) {
        feats.push({ type: 'Feature', geometry: { type: 'Polygon', coordinates: [closedRing(drawPoints)] } });
      } else if (drawPoints.length >= 2) {
        feats.push({ type: 'Feature', geometry: { type: 'LineString', coordinates: drawPoints } });
      }
      drawPoints.forEach(pt => feats.push({ type: 'Feature', geometry: { type: 'Point', coordinates: pt } }));
      return { type: 'FeatureCollection', features: feats };
    }

    function refreshDraw() {
      const src = map.getSource('draw3d');
      if (src) src.setData(drawFeatureCollection());
      $('undobtn').disabled = !(mode === 'polygon' && drawPoints.length > 0);
      $('finishbtn').disabled = !(mode === 'polygon' && drawPoints.length >= 3);
    }

    function updateModeButtons() {
      $('drawbtn').classList.toggle('active', mode === 'polygon');
      $('anchorbtn').classList.toggle('active', mode === 'anchor');
    }

    function placeAnchor(lngLat) {
      if (anchorMarker) {
        anchorMarker.setLngLat(lngLat);
      } else {
        const el = document.createElement('div');
        el.className = 'r3d-anchor';
        anchorMarker = new maplibregl.Marker({ element: el, draggable: true, anchor: 'bottom' }).setLngLat(lngLat).addTo(map);
      }
    }

    function locateBuildingLayer() {
      const style = map.getStyle();
      const layer = style && style.layers && style.layers.find(
        l => l.type === 'fill-extrusion' && (l['source-layer'] === 'building' || /building/i.test(l.id))
      );
      buildingLayerId = layer ? layer.id : null;
    }

    // ── 點在多邊形內判定：只看質心，不是嚴格的幾何相交，跟建物這種小面積的用途相稱
    // （沒有另外拉 turf.js 只為了這一個檢查，理由同 tilecut.php 那幾個自帶的座標函式）──
    function pointInRing(pt, ring) {
      const [x, y] = pt;
      let inside = false;
      for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
        const [xi, yi] = ring[i], [xj, yj] = ring[j];
        if (((yi > y) !== (yj > y)) && (x < (xj - xi) * (y - yi) / (yj - yi) + xi)) inside = !inside;
      }
      return inside;
    }
    function centroidOf(coords) {
      let sx = 0, sy = 0, n = 0;
      coords.forEach(([x, y]) => { sx += x; sy += y; n++; });
      return n ? [sx / n, sy / n] : null;
    }
    function polygonsIntersectRing(geom, ring) {
      if (!geom) return false;
      const polys = geom.type === 'Polygon' ? [geom.coordinates]
        : geom.type === 'MultiPolygon' ? geom.coordinates : null;
      if (!polys) return false;
      return polys.some(rings => {
        const c = centroidOf(rings[0] || []);
        return c && pointInRing(c, ring);
      });
    }

    /** 存檔／重新掃描共用：對照目前畫面上的多邊形查一次落在裡面的建物 id。 */
    function scanExcludedBuildingIds() {
      if (!buildingLayerId || drawPoints.length < 3) return { ids: [], missing: 0 };
      const ring = closedRing(drawPoints);
      let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
      ring.forEach(([lng, lat]) => {
        const p = map.project([lng, lat]);
        minX = Math.min(minX, p.x); maxX = Math.max(maxX, p.x);
        minY = Math.min(minY, p.y); maxY = Math.max(maxY, p.y);
      });
      const feats = map.queryRenderedFeatures([[minX, minY], [maxX, maxY]], { layers: [buildingLayerId] });
      const ids = [];
      const seen = new Set();
      let missing = 0;
      feats.forEach(f => {
        if (!polygonsIntersectRing(f.geometry, ring)) return;
        if (f.id === undefined || f.id === null) { missing++; return; }
        if (!seen.has(f.id)) { seen.add(f.id); ids.push(f.id); }
      });
      return { ids, missing };
    }

    map.on('load', () => {
      locateBuildingLayer();
      map.addSource('draw3d', { type: 'geojson', data: drawFeatureCollection() });
      map.addLayer({ id: 'draw3d-fill', type: 'fill', source: 'draw3d', filter: ['==', '$type', 'Polygon'], paint: { 'fill-color': '#b5482e', 'fill-opacity': 0.25 } });
      map.addLayer({ id: 'draw3d-line', type: 'line', source: 'draw3d', filter: ['!=', '$type', 'Point'], paint: { 'line-color': '#b5482e', 'line-width': 2 } });
      map.addLayer({ id: 'draw3d-pts', type: 'circle', source: 'draw3d', filter: ['==', '$type', 'Point'], paint: { 'circle-radius': 5, 'circle-color': '#fff', 'circle-stroke-color': '#b5482e', 'circle-stroke-width': 2 } });

      if (EDIT && EDIT.polygon && EDIT.polygon.coordinates && EDIT.polygon.coordinates[0]) {
        const ring = EDIT.polygon.coordinates[0];
        drawPoints = ring.slice(0, -1).map(p => [p[0], p[1]]);
        completedPolygon = drawPoints.length >= 3;
        refreshDraw();
        const b = new maplibregl.LngLatBounds();
        ring.forEach(p => b.extend(p));
        map.fitBounds(b, { padding: 60, duration: 0 });
      }
      if (EDIT && EDIT.model && Array.isArray(EDIT.model.anchor)) {
        placeAnchor([EDIT.model.anchor[1], EDIT.model.anchor[0]]);
      }
    });

    map.on('click', (e) => {
      if (mode === 'polygon') {
        drawPoints.push([e.lngLat.lng, e.lngLat.lat]);
        completedPolygon = false;
        refreshDraw();
      } else if (mode === 'anchor') {
        placeAnchor(e.lngLat);
        mode = 'idle';
        updateModeButtons();
      }
    });

    $('drawbtn').onclick = () => { mode = 'polygon'; drawPoints = []; completedPolygon = false; refreshDraw(); updateModeButtons(); };
    $('undobtn').onclick = () => { drawPoints.pop(); refreshDraw(); };
    $('finishbtn').onclick = () => { if (drawPoints.length < 3) return; completedPolygon = true; mode = 'idle'; refreshDraw(); updateModeButtons(); };
    $('redrawbtn').onclick = () => { mode = 'polygon'; drawPoints = []; completedPolygon = false; refreshDraw(); updateModeButtons(); };
    $('anchorbtn').onclick = () => { mode = 'anchor'; updateModeButtons(); };
    $('modelfile').onchange = () => {
      const f = $('modelfile').files[0];
      $('modelinfo').textContent = f ? (f.name + ' (' + Math.round(f.size / 1024) + ' KB)') : '';
    };

    async function post(body, soft) {
      // 跟 tilecut.php 同一套限流重試：manage bucket 撞到 429 時照 Retry-After 等一下再送
      for (let attempt = 0; attempt < 6; attempt++) {
        const res = await fetch(ROUTES.api, { method: 'POST', body });
        if (res.status !== 429) {
          const j = await res.json().catch(() => ({}));
          if (soft && soft.indexOf(res.status) >= 0) { j.status = res.status; return j; }
          if (!res.ok || !j.ok) throw new Error(j.error || ('HTTP ' + res.status));
          return j;
        }
        const wait = parseInt(res.headers.get('Retry-After') || '10', 10) || 10;
        statusEl.textContent = fmt(I18N.rate_limited, { wait });
        await new Promise(r => setTimeout(r, wait * 1000));
      }
      throw new Error('429');
    }

    async function saveRegion() {
      const project = $('project').value;
      const id = $('rid').value.trim().toLowerCase();
      if (!/^[a-z0-9][a-z0-9_-]*$/.test(id)) { statusEl.textContent = I18N.need_id; return; }
      if (!completedPolygon || drawPoints.length < 3) { statusEl.textContent = I18N.need_polygon; return; }
      if (!anchorMarker) { statusEl.textContent = I18N.need_anchor; return; }
      const file = $('modelfile').files[0] || null;
      const editingSame = EDIT && EDIT.id === id;
      if (!file && !editingSame) { statusEl.textContent = I18N.need_model; return; }

      const btn = $('savebtn');
      btn.disabled = true;
      try {
        const overwrite = $('overwrite').checked || editingSame;
        const fd0 = new FormData();
        fd0.append('action', 'begin'); fd0.append('csrf', csrf);
        fd0.append('project', project); fd0.append('id', id);
        if (overwrite) fd0.append('overwrite', '1');
        await post(fd0);

        if (file) {
          let off = 0, stuck = 0;
          while (off < file.size) {
            const end = Math.min(file.size, off + SRCCHUNK);
            statusEl.textContent = fmt(I18N.uploading, { pct: Math.round(end / file.size * 100) });
            const fd = new FormData();
            fd.append('action', 'srcput'); fd.append('csrf', csrf);
            fd.append('project', project); fd.append('id', id);
            fd.append('offset', off);
            if (end >= file.size) fd.append('last', '1');
            fd.append('chunk', file.slice(off, end), 'model.glb');
            const j = await post(fd, [409]);
            if (j.status === 409) {
              if (++stuck > 3) throw new Error(j.error || '409');
              off = Math.max(0, j.have | 0);
              continue;
            }
            stuck = 0;
            off = end;
          }
        }

        statusEl.textContent = I18N.scanning;
        const { ids, missing } = scanExcludedBuildingIds();
        let scanMsg = fmt(I18N.scan_result, { n: ids.length });
        if (missing) scanMsg += ' ' + fmt(I18N.missing_id_warn, { n: missing });

        const anchor = anchorMarker.getLngLat();
        const fdF = new FormData();
        fdF.append('action', 'finish'); fdF.append('csrf', csrf);
        fdF.append('project', project); fdF.append('id', id);
        fdF.append('polygon', JSON.stringify({ type: 'Polygon', coordinates: [closedRing(drawPoints)] }));
        fdF.append('excludedBuildingIds', JSON.stringify(ids));
        fdF.append('anchor', JSON.stringify([anchor.lat, anchor.lng]));
        fdF.append('rotationDeg', $('rot').value || '0');
        fdF.append('scale', $('scale').value || '1');
        fdF.append('altitudeOffset', $('alt').value || '0');
        fdF.append('label', $('rlabel').value || '');
        fdF.append('attribution', $('attr').value || '');
        statusEl.textContent = I18N.saving;
        await post(fdF);
        statusEl.textContent = I18N.complete + ' ' + scanMsg;
      } catch (e) {
        statusEl.textContent = I18N.error_prefix + (e.message || I18N.conn_failed);
      } finally {
        btn.disabled = false;
      }
    }
    $('savebtn').onclick = saveRegion;

    <?php if ($EDIT !== null): ?>
    async function rescanRegion() {
      const project = $('project').value;
      const id = <?= json_encode($EDIT['id']) ?>;
      const btn = $('rescanbtn');
      btn.disabled = true;
      try {
        statusEl.textContent = I18N.rescanning;
        const { ids, missing } = scanExcludedBuildingIds();
        const fd = new FormData();
        fd.append('action', 'rescan'); fd.append('csrf', csrf);
        fd.append('project', project); fd.append('id', id);
        fd.append('excludedBuildingIds', JSON.stringify(ids));
        await post(fd);
        let msg = fmt(I18N.rescan_complete, { n: ids.length });
        if (missing) msg += ' ' + fmt(I18N.missing_id_warn, { n: missing });
        statusEl.textContent = msg;
      } catch (e) {
        statusEl.textContent = I18N.error_prefix + (e.message || I18N.conn_failed);
      } finally {
        btn.disabled = false;
      }
    }
    $('rescanbtn').onclick = rescanRegion;
    <?php endif; ?>
  </script>
</body>

</html>
