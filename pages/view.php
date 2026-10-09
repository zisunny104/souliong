<?php
/**
 * Souliong 主視圖。base 路徑自動推導、資料伺服器端內嵌（框架不供應靜態檔）。
 * ?embed=1 → 精簡檢視模式（僅供瀏覽）。?p=<project> → 切換專案。
 */
$cfg = include __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/routes.php';   // 網址表：掛載根路徑與各種網址的演算法，全站只有這一份
$base = Route::base();

$b       = htmlspecialchars($base, ENT_QUOTES);
$embed   = (($_GET['embed'] ?? '') === '1');
// 嵌入參數（僅 embed=1 有效）。bare＝純地圖：不輸出任何 UI 與選用模組，只留地圖、標記與版權標示，
// 由 assets/js/embed-bridge.js 以 postMessage 接受父頁控制。
$bare    = $embed && (($_GET['ui'] ?? '') === 'bare');
$submit  = $embed && (($_GET['ui'] ?? '') === 'submit');
$bodyCls = trim(($embed ? 'embed' : '') . ($bare ? ' embed-bare' : '') . ($submit ? ' embed-submit' : '')
    . ($bare && ($_GET['interactive'] ?? '') !== '1' ? ' embed-static' : '')
    . ($embed && ($_GET['bg'] ?? '') === 'transparent' ? ' embed-bg-transparent' : ''));
$proj    = preg_replace('/[^a-z0-9_-]/', '', $_GET['p'] ?? ($cfg['default_project'] ?? 'chairs'));
$metaF   = __DIR__ . '/../projects/' . $proj . '/meta.json';
$meta    = is_file($metaF) ? json_decode(file_get_contents($metaF), true) : null;

require_once __DIR__ . '/../api/security.php';   // 權限一律問 Auth（api/auth.php）：身分只解析一次，能力與 CSRF 從同一個 Actor 來
require_once __DIR__ . '/../api/i18n.php';
require_once __DIR__ . '/../api/features.php';
require_once __DIR__ . '/../api/pinicons.php';
require_once __DIR__ . '/../api/licenses.php';
require_once __DIR__ . '/../api/markdown.php';
require_once __DIR__ . '/../api/packs.php';
require_once __DIR__ . '/../api/layers.php';
require_once __DIR__ . '/../api/navlinks.php';
require_once __DIR__ . '/../api/labellang.php';
require_once __DIR__ . '/../api/regions3d.php';
require_once __DIR__ . '/../api/osmdata.php';
$apiCfg    = require __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/spotlib.php';
require_once __DIR__ . '/../api/embedorigins.php';
// 允許嵌入的來源（全站＋專案聯集）：?embed=1 且清單非空才放寬 frame-ancestors／移除 XFO，其餘維持原狀。
$embedOrigins = embed_origins_allowed($apiCfg, $meta);
if ($embed) embed_send_frame_headers($embedOrigins);
require_once __DIR__ . '/../api/uploadlib.php';
// 點位：spots.jsonl 裡的起點紀錄（一定有 num、無 edit_of），api/newspot.php 動態建立的——
// spots.jsonl 是點位唯一的真相來源，跟 assets/js/viewer.core.js 的 effectiveSpots() 同一套判斷式。
// 位置與內容的版本疊加交給前端讀 CONTRIB 做，這裡只給起點紀錄；起點自帶的 content 區塊附加
// 衍生欄位 html（見 spot_content_render()）。
$spots     = $meta ? array_values(array_filter(store_all($apiCfg, $proj), fn($r) => ($r['kind'] ?? null) === 'spot' && empty($r['edit_of']) && isset($r['num']))) : [];
foreach ($spots as &$spotRec) {
    if (is_array($spotRec['content'] ?? null)) $spotRec['content'] = spot_content_render($spotRec['content']);
    $spotRec['content_rev'] = $spotRec['id'] ?? null;
}
unset($spotRec);
// 這個請求在這張地圖的身分：能力（APP.perms）與 CSRF 都從同一個 Actor 來。
// isMember 只是「這個身分屬於這張地圖」的純身分，只供顯示（APP.isManager），
// 任何動作能不能做一律看具體權限鍵（APP.perms／APP.can(key)）。
$actor     = Auth::actor($apiCfg, $proj);
$isManager = $actor->isMember($proj);
$canEditSpots = $actor->can($proj, 'edit_spots');   // 伺服端判斷用；同時輸出到 APP.canEditSpots 供 authcheck 核對
// 投稿開放條件由免碼設定與有效投稿碼組成；前端不取得真正的碼。
// APP.gated 因此變成「現在有碼可解鎖」：一組都沒有時前端連解鎖鈕都不出現。
$gated = codes_active($apiCfg, $proj) !== [];
$freeAccess = contrib_free_state($meta);
// 寫入類端點（Auth::require）比對的 CSRF 值：跟端點用同一個 Actor 衍生，anon 為 null。
$csrfTok   = $actor->csrf($proj);
[$LANG, $DICT] = i18n_init();
$t = fn(string $key, array $vars = []): string => htmlspecialchars(i18n_t($DICT, $key, $vars), ENT_QUOTES);
$mod = fn(string $key): bool => !$bare && souliong_module_on($meta, $key);   // bare 一律不載入選用模組
// 每個模組解析後（含未來的相依關係）的開關結果，前端 MOD() 直接讀這份、不再自己重算預設值邏輯，
// 避免 PHP 端 $mod() 與 JS 端各自判斷、日後模組間有相依時兩邊算出不同答案。
$moduleState = array_combine(array_keys(souliong_modules()), array_map($mod, array_keys(souliong_modules())));
$pack = souliong_pack_for($apiCfg, $meta, $proj);
// 這張地圖由下往上要疊哪幾層圖磚／插畫。跟 $pack 同樣是「用哪一包」而非布林開關，差別只在
// 圖層是有序陣列。相對路徑的圖檔在這裡就被改寫成 <base>/layer/... 絕對網址，前端不必分辨。
// 嵌入網址 ?layer=id1,id2：點名要用的圖層（含「僅限嵌入」的）；只認專案已勾選的，其餘忽略
$embedLayerIds = $embed ? array_values(array_filter(array_map('trim', explode(',', (string)($_GET['layer'] ?? ''))), fn($v) => preg_match('/^[A-Za-z0-9_-]{1,64}$/', $v))) : [];
$layers = souliong_layers_public($apiCfg, $meta, $proj, $base, $embedLayerIds);
// 勾選的圖層就是訪客可挑的範圍：多張底圖擇一、多張疊圖各自開關；只有一張就沒得挑
$layerExtra = souliong_layers_alternates($apiCfg, $meta, $proj, $base, $embedLayerIds);
// 這張地圖開放哪些投稿型別、對話框預設開哪一頁、誰能建立點位（meta.json 的 contrib 區塊）。
// 跟 $moduleState 同樣的原則：PHP 端解析一次，前端直接讀 APP.contrib，不在兩邊各自算預設值。
$contribCfg = souliong_contrib_cfg($meta);
// 嵌入投稿：type 只能收窄專案已開放的型別，不含建立點位
if ($submit) {
    $want = array_filter(array_map('trim', explode(',', (string)($_GET['type'] ?? ''))));
    if ($want) $contribCfg['kinds'] = array_values(array_intersect($contribCfg['kinds'], $want));
    $contribCfg['newSpot'] = 'off';
}
// 要載入哪些型別檔（assets/js/contrib/kind-*.js）。「檔案有沒有被輸出」就是型別的開關——
// 前端不需要再對 contrib.kinds 過濾一次，純文字的地圖也不會載到影片抽幀那段程式碼。
// 建立點位是權限而非型別：設成 admin 時只有已登入的管理者拿得到那支檔案。
$contribFiles = $mod('upload') ? $contribCfg['kinds'] : [];
if ($contribFiles && ($contribCfg['newSpot'] === 'contributor' || ($contribCfg['newSpot'] === 'admin' && $canEditSpots))) {
    $contribFiles[] = 'newspot';
}
// 點位內容編輯器（content-editor.js）：只給具 edit_spots 的身分載入（純顯示判斷）。它借用 kind-audio.js 的
// 錄音／選檔，型別檔的載入獨立於 upload 模組——唯讀地圖的管理者一樣要能錄音，所以投稿型別沒載到 audio 時另外補載。
$contentEditOn = $mod('contentEdit') && $canEditSpots;
$needAudioKind = $contentEditOn && !in_array('audio', $contribFiles, true);
// 照片區塊同理：編輯器要用 kind-photo.js 的 SLPhotoTools（縮圖、EXIF、HEIC），照片投稿沒開也要補載。
$needPhotoKind = $contentEditOn && !in_array('photo', $contribFiles, true);
// 3D 模式關掉時整個 key 是 null，前端 map3d.js 本身也不會被載入(見下方 $mod('map3d') 輸出)，
// 兩邊一起判斷、不是只看其中一邊，plugin 缺席時 APP.map3d 也沒有殘留資料可用。
$map3d = $mod('map3d') ? [
    'styleUrl'            => (string)($apiCfg['map3d_style_url'] ?? ''),
    'key'                 => (string)($apiCfg['map3d_key'] ?? ''),
    'regions'             => souliong_region3d_public_list($apiCfg, $proj, $base),
    'excludedBuildingIds' => souliong_region3d_excluded_ids($apiCfg, $proj),
    // 預先抓取的 OSM 資料集網址（tools/osm_fetch.php）；沒抓過就是 null，前端據此跳過請求
    'roofsUrl'            => is_file((string)souliong_osm_path($apiCfg, $proj, 'roofs')) ? Route::osm($proj, 'roofs') : null,
    'treesUrl'            => is_file((string)souliong_osm_path($apiCfg, $proj, 'trees')) ? Route::osm($proj, 'trees') : null,
    'powerUrl'            => is_file((string)souliong_osm_path($apiCfg, $proj, 'power')) ? Route::osm($proj, 'power') : null,
] : null;

$APP = [
    'licenses' => souliong_licenses(),
    'base'        => $base,
    // 這張地圖的後台網址。前端有三個地方要用到（登入 POST、邀請兌換 POST、登入後跳轉），
    // 由伺服器端算好給它，網址形狀就只寫在 api/routes.php 一處。
    'manager'     => Route::manager($proj),
    'project'     => $proj,
    'embed'       => $embed,
    'embedOrigins' => $embed ? $embedOrigins : [],   // postMessage 白名單（只在嵌入模式注入）
    'gated'       => $gated,
    'contributionAccess' => $freeAccess,
    'meta'        => $meta,
    'spots'       => $spots,
    // 權限契約：actor 是身分種類，perms 是此身分在這張地圖具備的專案層級權限鍵（primary 為全部），
    // csrf 是寫入類端點要帶的憑證（anon 為 null）。前端判斷能力一律看 perms。
    'actor'       => $actor->kind(),
    'perms'       => $actor->grantedKeys($proj),
    'csrf'        => $csrfTok,
    // 純身分（顯示身分小標籤用），不是能力
    'isManager'   => $isManager,
    'canEditSpots' => $canEditSpots,
    // 點位版本紀錄裡可被覆寫的欄位（前端疊加點位版本時的欄位清單，跟 spot_effective() 同一份）
    'spotFields'  => spot_overridable_fields(),
    'moduleState' => $moduleState,
    'bylineFormats' => souliong_byline_formats($meta),
    'pinIcons' => ($meta['pinMark'] ?? '') === 'icon' ? souliong_marker_icons() : [],
    'contrib'     => $contribCfg,
    // 上傳大小上限（位元組，null＝不限制），前端送出前預檢用；欄位說明見 uploadlib_limits()
    'upload'      => uploadlib_limits($apiCfg),
    'pack'        => $pack,
    'layers'      => $layers,
    'layerExtra'  => $layerExtra,
    // 向量底圖標註語言：mapLabelLang 是 'auto'（跟隨 LANG）或 labelFields 的鍵；labelFields 是各語言的名稱欄位優先序
    'mapLabelLang' => souliong_label_lang($meta),
    'labelFields' => souliong_label_fields(),
    'map3d'       => $map3d,
    // 封面快照（api/cover.php）：POST 目標網址＋前端節流用的最小間距，避免管理者每次開頁
    // 都白白擷圖編碼一次（伺服器端仍是權威判斷，這裡只是省一趟沒意義的請求）。
    // 導航連結模板（api/navlinks.php）：前端只負責套值與顯示選單，有座標的點位才出現導航鈕
    'nav'         => souliong_nav_config(),
    'coverUrl'         => Route::api('cover', ['project' => $proj]),
    'coverMinInterval' => (int)($apiCfg['cover_min_interval'] ?? 3600),
];
$jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS;
$esc = fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
// 產生第一方 CSS／JS 網址（經 api/appasset.php 供應），版本號取檔案 mtime 供快取失效用。
$assetUrl = function (string $rel) use ($esc): string {
    $abs = __DIR__ . '/../' . $rel;
    return $esc(Route::api('appasset', ['f' => $rel, 'v' => (string)(@filemtime($abs) ?: 0)]));
};

// 社群分享預覽卡（OG/Twitter Card）：三層（專案預設／?spot=/?entry=）共用同一組輸出，
// 依 entry > spot > 專案預設 優先序決定，跟 $mod('share') 開關無關——網址列本來就能複製。
require_once __DIR__ . '/../api/oglib.php';
require_once __DIR__ . '/../api/socialcardlib.php';
$ogDesc  = $meta['desc'] ?? $meta['subtitle'] ?? i18n_t($DICT, 'app_tagline');
$ogImage = cover_file_of(project_dir($apiCfg, $proj) . '/cover') !== null
    ? Route::abs(Route::api('cover', ['project' => $proj]))
    : null;
$ogUrl   = Route::abs(Route::map($proj));

$entryId = is_string($_GET['entry'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $_GET['entry']) ? $_GET['entry'] : '';
$spotRef = is_string($_GET['spot'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $_GET['spot']) ? $_GET['spot'] : '';   // spotId（新連結）或 num（舊連結）

if ($entryId !== '' && ($entry = souliong_og_resolve_entry($apiCfg, $proj, $entryId))) {
    $sp = isset($entry['item_num']) ? souliong_og_resolve_spot($apiCfg, $proj, (int)$entry['item_num']) : null;
    $ogSpotLabel = $sp ? souliong_og_spot_title(souliong_og_spot_name($sp), (int)$entry['item_num'], $meta['numbering'] ?? 'suffix') : '';
    $kindKey = ['photo' => 'tab_photo', 'video' => 'tab_video', 'audio' => 'tab_audio'][$entry['kind'] ?? ''] ?? 'tab_text';
    $entryText = ($entry['comment'] ?? '') !== '' ? souliong_og_plain(mb_substr((string)$entry['comment'], 0, 2000)) : '';
    $entryFallback = i18n_t($DICT, 'og_entry_fallback', ['name' => $entry['name'] ?: i18n_t($DICT, 'anon_fallback'), 'kind' => i18n_t($DICT, $kindKey)]);
    $ogDesc = $entryText !== '' ? souliong_og_truncate($entryText) : $entryFallback;
    // 投稿沒有標題欄：有留言取前 20 字，沒有就用「誰分享的什麼」
    $ogEntryLabel = $entryText !== '' ? souliong_og_truncate($entryText, 20) : $entryFallback;
    if ($qs = souliong_og_entry_image_qs($entry)) $ogImage = Route::abs(Route::api($qs[0], $qs[1]));
    $ogUrl = Route::abs(Route::map($proj) . '?entry=' . rawurlencode($entryId));
} elseif ($spotRef !== '' && ($sp = souliong_og_resolve_spot($apiCfg, $proj, $spotRef))) {
    $ogSpotLabel = souliong_og_spot_title(souliong_og_spot_name($sp), (int)$sp['num'], $meta['numbering'] ?? 'suffix');
    $ogDesc  = souliong_og_truncate(souliong_og_plain(spot_content_text($sp)) ?: (string)($meta['desc'] ?? i18n_t($DICT, 'app_tagline')));
    $ogUrl = Route::abs(Route::map($proj) . '?spot=' . rawurlencode((string)$sp['id']));   // 新連結一律用 spotId
}
// 標題由內而外：投稿｜點位｜專案｜平台。沒有的層級略過；專案關閉「回平台首頁」時不帶平台名稱
$projectLabel = (string)($meta['title'] ?? '') !== '' ? (string)$meta['title'] : i18n_t($DICT, 'app_title');
$ogTitle = souliong_og_truncate(implode(' | ', array_values(array_unique(array_filter([
    $ogEntryLabel ?? '', $ogSpotLabel ?? '', $projectLabel,
    $mod('homeLink') ? i18n_t($DICT, 'app_title') : '',
], fn($v) => $v !== '')))), 90);
$APP['docTitle'] = $ogTitle;   // 前端的分頁標題沿用伺服器算好的同一串
// 嵌入頁不需要分享預覽，略過整段資料組裝
$socialCard = !$embed && souliong_social_ready($apiCfg) && is_array($meta)
    ? souliong_social_data($apiCfg, $proj, $meta, $entryId, $spotRef, $LANG) : null;
if ($socialCard) {
    $socialQuery = ['project' => $proj, 'lang' => $LANG, 'v' => substr(souliong_social_revision($apiCfg, $proj, $meta, $socialCard), 0, 16)];
    if ($entryId !== '') $socialQuery['entry'] = $entryId;
    elseif ($spotRef !== '') $socialQuery['spot'] = $spotRef;
    $ogImage = Route::abs(Route::api('socialpreview', $socialQuery));
}
?><!DOCTYPE html>
<html lang="<?= $LANG === 'en' ? 'en' : 'zh-Hant' ?>">
<head>
<link rel="icon" type="image/svg+xml" href="<?= $assetUrl('assets/favicon.svg') ?>">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $esc($ogTitle) ?></title>
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= $t('app_title') ?>">
<meta property="og:title" content="<?= $esc($ogTitle) ?>">
<meta property="og:description" content="<?= $esc($ogDesc) ?>">
<meta property="og:url" content="<?= $esc($ogUrl) ?>">
<?php if ($ogImage): ?><meta property="og:image" content="<?= $esc($ogImage) ?>">
<?php if ($socialCard): ?><meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:type" content="image/jpeg">
<?php endif; ?><meta property="og:image:alt" content="<?= $esc($ogTitle) ?>">
<meta name="twitter:image" content="<?= $esc($ogImage) ?>">
<meta name="twitter:image:alt" content="<?= $esc($ogTitle) ?>"><?php endif; ?>
<meta name="twitter:card" content="<?= $ogImage ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= $esc($ogTitle) ?>">
<meta name="twitter:description" content="<?= $esc($ogDesc) ?>">
<?php /* 大型第三方函式庫走真的 <link>/<script src>，不用 readfile() 內嵌，才吃得到瀏覽器快取 */ ?>
<link rel="stylesheet" href="https://unpkg.com/maplibre-gl@6.6.0/dist/maplibre-gl.css">
<?php if ($mod('map3d')): /* three.js importmap 只服務 3D 切換鈕，跟主引擎是不是 MapLibre 無關——
     向量底圖本身不需要 three.js */ ?>
<script type="importmap">
{"imports": {
  "three": "https://unpkg.com/three@0.184.0/build/three.module.js",
  "three/addons/": "https://unpkg.com/three@0.184.0/examples/jsm/"
}}
</script>
<?php // three.js 本體(~600KB)不在這裡載入——import map 只是解析規則,瀏覽器不會預先抓檔案;
      // 真正的 import('three') 只在 assets/js/engine/maplibre-engine.js 確認這張地圖至少有一筆
      // 已存自訂模型時才會執行,沒有自訂模型的地圖不用付這個下載成本(見該檔 _maybeLoadThree()) ?>
<?php endif; ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<?php
// 依層疊順序列出 assets/css/ 各檔，新增樣式分類時在陣列加檔名即可。
$cssFiles = ['theme', 'control-card', 'popups', 'map-markers', 'spot-panel', 'map-controls', 'lightbox', 'page-frame'];
// 投稿與建立點位對話框的樣式跟著它們的外掛走：沒載入外掛就沒必要送這段 CSS
if ($contribFiles) {
    $cssFiles[] = 'contrib';
}
// 播放器／點位卡片的中卡、全卡、迷你列樣式：有兩種來源都可能需要播音訊，任一種成立就要備好
// 這組樣式——開放 audio 投稿（$hasAudioKind，投稿牆上的音訊）、開了 contentEdit（點位自己的
// 原生音訊內容，見 api/spotcontent.php）。純顯示判斷，不查有沒有真的錄過內容：地圖錄過音訊
// 後又把 contentEdit 關掉，播放器就不會載入，這種邊界情形本次接受不處理。
$hasAudioKind = !$bare && in_array('audio', $contribCfg['kinds'], true);
if ($hasAudioKind || $mod('contentEdit')) {
    $cssFiles[] = 'sound-player';
}
if ($submit) $cssFiles[] = 'embed-submit';
if ($bare) $cssFiles[] = 'embed-bare';   // 排在最後：蓋掉前面各檔的浮層與版權樣式
foreach ($cssFiles as $f) {
?>
<link rel="stylesheet" href="<?= $assetUrl("assets/css/$f.css") ?>">
<?php
}
// 主題包接在 base 主題之後，靠 cascade 覆寫 --pack-* 變數；未選包時不輸出這個 <link>，
// 各面板改用 var(--pack-*, 預設值) 的預設值。
if ($pack) {
    $packDir = souliong_pack_dir($apiCfg, $pack['id'], $proj);
    if ($packDir !== null) {
        $packVer = (string)(@filemtime($packDir . '/pack.css') ?: 0);
?>
<link rel="stylesheet" href="<?= $esc(Route::api('appasset', ['pack' => $pack['id'], 'project' => $proj, 'v' => $packVer])) ?>">
<?php
    }
}
?>
<script>try{var t=localStorage.getItem('theme');if(t==='dark'||t==='light')document.documentElement.dataset.theme=t;}catch(e){}</script>
</head>
<body class="<?= $esc($bodyCls) ?>">
<main id="mapPage">
<h1 class="sl-sr-only"><?= $esc($projectLabel) ?></h1>

<?php if (!$bare): ?>
<div id="skeleton" aria-hidden="true">
  <div class="sk-card sk">
    <div class="sk-line" style="width:55%"></div>
    <div class="sk-line" style="width:80%"></div>
    <div class="sk-line" style="width:80%"></div>
    <div class="sk-row"><span class="sk-btn"></span><span class="sk-btn"></span></div>
  </div>
  <div class="sk-fab sk"></div>
</div>
<?php endif; ?>

<div id="map"></div>

<div id="controls" class="floatcard">
  <div class="ctl-head">
    <span class="brand" id="title" title="<?= $t('brand_hint') ?>" role="button" tabindex="0"><span class="brand-txt" id="titleTxt"><?= $t('app_title') ?></span><span class="brand-sub" id="titleSub"></span></span>
    <span id="brandShape" class="brand-shape" aria-hidden="true"></span>
    <span class="spacer"></span>
    <button id="collapseBtn" class="icon-btn" title="<?= $t('collapse') ?>" aria-label="<?= $t('collapse_aria') ?>"><i class="fa-solid fa-chevron-down" aria-hidden="true"></i></button>
  </div>
  <div class="ctl-body" id="ctlBody">
    <?php if ($mod('categoryLegend')): ?>
    <div class="legend" id="legend"></div>
    <?php endif; ?>
    <?php if ($mod('contribBrowse')): ?>
    <div class="ctl-row">
      <button class="btn" id="allSpotsBtn" title="<?= $t('show_all_spots') ?>"><i class="fa-solid fa-layer-group"></i> <?= $t('all') ?></button>
      <button class="btn" id="photoLayerBtn" title="<?= $t('filter_by_contrib') ?>"><i class="fa-solid fa-photo-film"></i> <?= $t('contrib') ?></button>
    </div>
    <?php endif; ?>
    <div class="ctl-row" id="personFilterRow">
      <select id="personFilter" title="<?= $t('filter_person') ?>"><option value=""><?= $t('all_contributors') ?></option></select>
      <div class="sl-filter-menu" id="spotFilterMenu" hidden>
        <button type="button" class="sl-filter-trigger" id="spotFilterTrigger" aria-expanded="false" aria-controls="spotFilterOptions">
          <span id="spotFilterLabel"></span><span class="sl-spot-count" id="spotFilterCount" hidden></span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
        </button>
        <div class="sl-filter-options" id="spotFilterOptions" hidden></div>
      </div>
    </div>
    <?php if ($mod('spotList')): ?>
    <div class="sl-spot-list" id="spotList"></div>
    <?php endif; ?>
    <div class="ctl-foot" id="foot"></div>
  </div>
</div>

<div id="topright" class="tr-group">
  <button class="icon-btn tr-toggle" id="trToggle" title="<?= $t('more_options') ?>" aria-label="<?= $t('expand_options_aria') ?>" aria-expanded="false" aria-controls="trItems"><i class="fa-solid fa-bars" aria-hidden="true"></i></button>
  <div class="tr-items" id="trItems">
    <?php if ($mod('homeLink')): ?><a class="icon-btn hide-in-embed" id="homeBtn" href="<?= $b ?>" title="<?= $t('back_to_list') ?>" aria-label="<?= $t('back_to_list') ?>"><i class="fa-solid fa-house" aria-hidden="true"></i></a><?php endif; ?>
    <button id="themeBtn" class="icon-btn" title="<?= $t('toggle_theme') ?>" aria-label="<?= $t('toggle_theme_aria') ?>"><i class="fa-solid fa-circle-half-stroke" aria-hidden="true"></i></button>
    <button id="shortcutsBtn" class="icon-btn shortcuts-btn" title="<?= $t('shortcuts_btn') ?>" aria-label="<?= $t('shortcuts_btn') ?>"><i class="fa-solid fa-keyboard" aria-hidden="true"></i></button>
    <div class="lang-menu hide-in-embed" id="langMenu">
      <button type="button" class="lang-btn" id="langBtn" title="<?= $t('lang_switch') ?>" aria-haspopup="listbox" aria-expanded="false">
        <span id="langBtnLabel"><?= $LANG === 'en' ? 'English' : '中文' ?></span>
        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
      </button>
      <ul class="lang-list" id="langList" role="listbox" aria-label="<?= $t('lang_switch') ?>">
        <li role="option" data-lang="zh_TW" aria-selected="<?= $LANG === 'zh_TW' ? 'true' : 'false' ?>">中文</li>
        <li role="option" data-lang="en" aria-selected="<?= $LANG === 'en' ? 'true' : 'false' ?>">English</li>
      </ul>
    </div>
    <?php if ($isManager && !$bare): ?><a class="icon-btn hide-in-embed" id="adminBtn" href="<?= $esc($APP['manager']) ?>" title="<?= $t('admin_settings_btn') ?>" aria-label="<?= $t('admin_settings_btn') ?>"><i class="fa-solid fa-gear" aria-hidden="true"></i></a><?php endif; ?>
  </div>
</div>

<?php if ($mod('locate')): ?>
<button type="button" class="icon-btn mapop-btn" id="locateBtn" title="<?= $t('locate_me') ?>" aria-label="<?= $t('locate_me') ?>"><i class="fa-solid fa-location-arrow" aria-hidden="true"></i></button>
<?php endif; ?>
<button class="icon-btn mapop-btn" id="resetBtn" title="<?= $t('reset_view') ?>" aria-label="<?= $t('reset_view_aria') ?>"><i class="fa-solid fa-location-crosshairs" aria-hidden="true"></i></button>

<input type="text" id="myName" hidden>

<div id="cloudWarn" class="toast" style="display:none"></div>

<div id="panel" role="region" aria-labelledby="pTitle" aria-hidden="true" inert>
  <button class="p-expand" onclick="MapApp.togglePanelSize()" aria-label="<?= $t('expand_panel') ?>" title="<?= $t('expand_panel') ?>"><i class="fa-solid fa-up-right-and-down-left-from-center" aria-hidden="true"></i></button>
  <button class="p-close" onclick="MapApp.closePanel()" aria-label="<?= $t('close') ?>"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
  <div class="p-head">
    <div class="cat" id="pCat"></div>
    <h2 id="pTitle"></h2>
    <div class="sub" id="pSub"></div>
    <button class="btn small" id="spotEditBtn" type="button" style="display:none" title="<?= htmlspecialchars($t('adjust_location'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars($t('adjust_location'), ENT_QUOTES) ?>"><i class="fa-solid fa-location-dot" aria-hidden="true"></i></button>
    <div class="photo-editor spot-editor" id="spotEditor" style="display:none"></div>
  </div>
  <div class="p-body">
    <div id="entries"></div>
  </div>
</div>

</main>
<?php if ($mod('upload')): ?>
<div id="unlockDialog" class="dialog">
  <div class="dialog-box">
    <div class="dialog-head"><b><?= $t('unlock_contrib') ?></b><button class="icon-btn" onclick="MapApp.closeUnlock()" aria-label="<?= $t('close') ?>"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <div class="hint"><?= $t('unlock_hint') ?></div>
    <input id="unlockCodeInput" class="name-in" style="width:100%;letter-spacing:8px;text-align:center;font-size:1.375rem" placeholder="<?= $t('six_digits') ?>" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" maxlength="6" data-pin-toggle data-pin-slots="6" data-pin-digits-only aria-label="<?= $t('contrib_code') ?>">
    <div id="unlockMsg" class="hint" role="status"></div>
    <?php if ($mod('identity')): ?>
    <div style="margin-top:10px">
      <button type="button" class="btn" id="idToggleBtn" aria-expanded="false" aria-controls="idFields"><?= $t('create_identity_btn') ?></button>
      <div id="idFields" style="display:none;margin-top:8px">
        <div class="hint"><?= $t('identity_hint') ?></div>
        <input id="unlockPinInput" class="name-in" style="width:100%;margin-top:6px" placeholder="<?= $t('set_pin') ?>" autocomplete="off" data-pin-toggle aria-label="<?= $t('contrib_pin') ?>">
        <input id="unlockCnameInput" class="name-in" style="width:100%;margin-top:6px" placeholder="<?= $t('display_nickname') ?>" autocomplete="off" maxlength="40" aria-label="<?= $t('contributor_nickname') ?>">
      </div>
    </div>
    <?php endif; ?>
    <div class="dialog-actions">
      <button class="btn" id="scanBtn"><i class="fa-solid fa-qrcode"></i> <?= $t('scan_qr') ?></button>
      <span class="spacer"></span>
      <button class="btn primary" id="unlockSubmit"><?= $t('unlock') ?></button>
    </div>
    <div id="scanBox" style="display:none"><video id="scanVideo" playsinline muted></video></div>
  </div>
</div>
<?php endif; ?>

<?php if ($mod('delegation')): ?>
<div id="adminRedeemDialog" class="dialog">
  <div class="dialog-box">
    <div class="dialog-head"><b><?= $t('admin_redeem_title') ?></b><button class="icon-btn" onclick="MapApp.closeAdminRedeem()" aria-label="<?= $t('close') ?>"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <div class="hint"><?= $t('admin_redeem_hint') ?></div>
    <input id="adminRedeemPinInput" class="name-in" style="width:100%;margin-top:6px" placeholder="<?= $t('admin_set_pin') ?>" autocomplete="off" data-pin-toggle aria-label="<?= $t('admin_set_pin') ?>">
    <input id="adminRedeemNameInput" class="name-in" style="width:100%;margin-top:6px" placeholder="<?= $t('admin_nickname') ?>" autocomplete="off" maxlength="40" aria-label="<?= $t('admin_nickname') ?>">
    <div id="adminRedeemMsg" class="hint" role="status"></div>
    <div class="dialog-actions">
      <span class="spacer"></span>
      <button class="btn primary" id="adminRedeemSubmit"><?= $t('admin_redeem_submit') ?></button>
    </div>
  </div>
</div>

<div id="pinDialog" class="dialog">
  <div class="dialog-box pin-box">
    <div class="dialog-head"><b><?= $t('admin_login') ?></b><button class="icon-btn" onclick="MapApp.closePin()" aria-label="<?= $t('close') ?>"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <form id="pinForm">
    <input id="pinInput" class="name-in" style="width:100%;text-align:center;font-size:1.125rem" type="password" placeholder="PIN" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-pin-toggle data-pin-slots="4" data-pin-keypad aria-label="<?= $t('pin_input_area') ?>">
    <div id="pinMsg" class="hint" role="status"></div>
    <div class="dialog-actions">
      <span class="spacer"></span>
      <button class="btn primary" type="submit" id="pinSubmitBtn"><?= $t('confirm_ok') ?></button>
    </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- #lbMedia 是影片／音訊的播放槽：播放器要能點（拖進度條、按暫停），所以它自己吞掉 click，
     不能跟照片一樣讓點擊冒泡到 #lb 去關燈箱。內容由 openLightbox() 每次重建，關閉時清空停止播放。 -->
<dialog id="markdownGuide" class="markdown-guide" aria-labelledby="markdownGuideTitle">
  <div class="markdown-guide-head"><h2 id="markdownGuideTitle"><?= $t('markdown_guide_title') ?></h2><button class="icon-btn" type="button" data-markdown-close aria-label="<?= $t('close') ?>"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
  <div class="markdown-guide-body">
    <p><?= $t('markdown_guide_intro') ?></p>
    <?php
    $examples = [
      'markdown_common' => [
        ['markdown_bold', '**' . $t('markdown_example') . '**'],
        ['markdown_italic', '*' . $t('markdown_example') . '*'],
        ['markdown_link', '[' . $t('markdown_example') . '](https://example.com)'],
        ['markdown_list', '- ' . $t('markdown_example') . "\n- " . $t('markdown_example')],
        ['markdown_paragraph', $t('markdown_example') . "\n\n" . $t('markdown_example')],
      ],
      'markdown_more' => [
        ['markdown_heading', '## ' . $t('markdown_example')],
        ['markdown_ordered', '1. ' . $t('markdown_example') . "\n2. " . $t('markdown_example')],
        ['markdown_quote', '> ' . $t('markdown_example')],
        ['markdown_strike', '~~' . $t('markdown_example') . '~~'],
        ['markdown_code', '`code`'],
        ['markdown_codeblock', "```\ncode\n```"],
        ['markdown_table', "| A | B |\n| --- | --- |\n| 1 | 2 |"],
        ['markdown_rule', '---'],
      ],
    ];
    foreach ($examples as $heading => $rows): ?>
      <h3><?= $t($heading) ?></h3>
      <div class="markdown-guide-labels"><span><?= $t('markdown_syntax') ?></span><span><?= $t('markdown_result') ?></span></div>
      <?php foreach ($rows as [$label, $source]): ?>
        <div class="markdown-guide-example"><div><strong><?= $t($label) ?></strong><pre><code><?= $esc($source) ?></code></pre></div><div class="markdown-guide-result sc-md"><?= Markdown::toHtml($source, ['heading_ids' => false, 'soft_breaks' => true]) ?></div></div>
      <?php endforeach; ?>
    <?php endforeach; ?>
    <p><?= $t('markdown_guide_note') ?></p>
  </div>
</dialog>
<script>
document.getElementById('markdownGuide').addEventListener('keydown', function(event) { event.stopPropagation(); });
document.addEventListener('click', function(event) {
  var guide = document.getElementById('markdownGuide');
  if (event.target.closest('[data-markdown-help]')) { if (!guide.open) guide.showModal(); }
  else if (event.target.closest('[data-markdown-close]') || event.target === guide) guide.close();
});
</script>

<div id="lb" role="dialog" aria-modal="true" aria-label="<?= $t('photo_info_title') ?>" tabindex="-1" onclick="MapApp.closeLightbox()"><img id="lbImg" alt=""><div id="lbMedia" style="display:none" onclick="event.stopPropagation()"></div><div class="cap" id="lbCap"></div><div class="photo-editor" id="lbEditor" style="display:none" onclick="event.stopPropagation()"></div></div>

<div id="extLinkDialog" class="dialog">
  <div class="dialog-box">
    <div class="dialog-head"><b><?= $t('ext_link_title') ?></b><button class="icon-btn" onclick="MapApp.closeExtLinkDialog()" aria-label="<?= $t('close') ?>"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <div class="hint"><?= $t('ext_link_hint') ?><b id="extLinkHost"></b></div>
    <div class="dialog-actions">
      <span class="spacer"></span>
      <button class="btn" onclick="MapApp.closeExtLinkDialog()"><?= $t('ext_link_cancel_btn') ?></button>
      <button class="btn primary" onclick="MapApp.extLinkProceed()"><?= $t('ext_link_confirm_btn') ?></button>
    </div>
  </div>
</div>

<div id="shortcutsDialog" class="dialog">
  <div class="dialog-box">
    <div class="dialog-head"><b><?= $t('shortcuts_dialog_title') ?></b><button class="icon-btn" onclick="MapApp.closeShortcuts()" aria-label="<?= $t('close') ?>"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <dl class="shortcuts-list" id="shortcutsList"></dl>
  </div>
</div>

<script>window.APP = <?= json_encode($APP, $jsonFlags) ?>; window.I18N = <?= json_encode($DICT, $jsonFlags) ?>; window.LANG = <?= json_encode($LANG, $jsonFlags) ?>;</script>
<?php if ($mod('map3d')): /* MapLibre v6 只出 ESM 版(dist/maplibre-gl.mjs),沒有 <script src> 吃得下去的全域
     版本了——用內嵌 type="module" 匯入後手動掛回 window.maplibregl,讓 map3d.js 仍以傳統全域變數方式
     使用它。map3d.js 也要標成 type="module",確保它排進「文件解析完才依序執行」這一批,晚於這段 shim
     ——3D 模式一律要使用者先按下切換鈕才會用到 maplibregl,不會跟這段非同步載入搶時間。
     主引擎不吃這段 shim：MapLibreEngine 掛載發生在
     頁面一開始同步的 boot() 裡，等不了這段要等文件解析完才執行的 ESM 全域變數，所以 viewer.core.js
     的 boot() 自己用 import() 動態載入同一份 MapLibre 並掛回 window.maplibregl，做法比照
     maplibre-engine.js 對 three.js 的 lazy-load（見該檔 _maybeLoadThree()）；兩邊載入同一個網址時
     瀏覽器的 module 快取本來就會共用，不會重複下載，這裡不用特別判斷「已經載過了」再跳過。 */ ?>
<script type="module">
import * as maplibregl from 'https://unpkg.com/maplibre-gl@6.6.0/dist/maplibre-gl.mjs';
window.maplibregl = maplibregl;
</script>
<?php endif; ?>
<?php if (in_array('photo', $contribFiles, true) || $needPhotoKind): /* EXIF 讀取與 HEIC 轉檔只有載入照片型別檔時用得到（見 assets/js/contrib/kind-photo.js） */ ?>
<script src="https://cdn.jsdelivr.net/npm/exifr/dist/full.umd.js"></script>
<script src="https://cdn.jsdelivr.net/npm/heic2any@0.0.4/dist/heic2any.min.js"></script>
<?php endif; ?>
<?php if (!$bare): ?>
<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
<script src="<?= $assetUrl('assets/js/pin-input.js') ?>"></script>
<?php endif; ?>
<script src="<?= $assetUrl('assets/js/engine/map-engine.js') ?>"></script>
<script src="<?= $assetUrl('assets/js/engine/maplibre-engine.js') ?>"></script>
<script src="<?= $assetUrl('assets/js/marker-colors.js') ?>"></script>
<script src="<?= $assetUrl('assets/js/featured-layout.js') ?>"></script>
<?php if (($meta['pinMark'] ?? '') === 'icon'): ?><script src="<?= $assetUrl('assets/js/pin-icons.js') ?>"></script><?php endif; ?>
<script src="<?= $assetUrl('assets/js/contribution-client.js') ?>"></script>
<script src="<?= $assetUrl('assets/js/viewer.core.js') ?>"></script>
<?php if (!$bare && count($layers) + count($layerExtra) >= 2): ?>
<script src="<?= $assetUrl('assets/js/layer-switch.js') ?>"></script>
<?php endif; ?>
<?php if ($bare): ?>
<script src="<?= $assetUrl('assets/js/embed-bridge.js') ?>"></script>
<?php endif; ?>
<?php if ($mod('identity') || ($isManager && !$bare && !$embed)): /* 管理者身分顯示不依賴投稿／設點開關 */ ?>
<script src="<?= $assetUrl('assets/js/plugins/contributor-identity.js') ?>"></script>
<?php endif; ?>
<?php if ($contribFiles || $needAudioKind || $needPhotoKind): /* 型別檔要在外掛之前載入：外掛開機時就要有完整的型別註冊表才能決定分頁 */ ?>
<script src="<?= $assetUrl('assets/js/contrib/kind-base.js') ?>"></script>
<?php foreach ($contribFiles as $kf): ?>
<script src="<?= $assetUrl('assets/js/contrib/kind-' . $kf . '.js') ?>"></script>
<?php endforeach; ?>
<?php if ($needAudioKind): ?>
<script src="<?= $assetUrl('assets/js/contrib/kind-audio.js') ?>"></script>
<?php endif; ?>
<?php if ($needPhotoKind): ?>
<script src="<?= $assetUrl('assets/js/contrib/kind-photo.js') ?>"></script>
<?php endif; ?>
<?php endif; ?>
<?php if ($contribFiles): ?>
<script src="<?= $assetUrl('assets/js/plugins/contribution.js') ?>"></script>
<?php endif; ?>
<?php if ($mod('embed')): ?>
<script src="<?= $assetUrl('assets/js/plugins/embed-code.js') ?>"></script>
<?php endif; ?>
<?php if ($mod('share')): ?>
<script src="<?= $assetUrl('assets/js/vendor/qrcode-generator.js') ?>"></script>
<script src="<?= $assetUrl('assets/js/plugins/share-link.js') ?>"></script>
<script src="<?= $assetUrl('assets/js/plugins/entry-link.js') ?>"></script>
<?php endif; ?>
<?php if ($mod('route')): ?>
<script src="<?= $assetUrl('assets/js/plugins/route-tour.js') ?>"></script>
<?php endif; ?>
<?php // contentEdit 只決定「要不要輸出內容編輯器」，真正擋寫入的是 api/spotcontent.php 的
      // Auth::require(edit_spots)；$canEditSpots 這裡只是不讓沒有該權限的人載入一個按了會
      // 被 403 擋下的編輯器，純顯示邏輯，不是權限依據。編輯器借用 kind-audio.js 的錄音／選檔，
      // 該型別檔的載入不看 upload 模組（見上方 $needAudioKind）。 ?>
<?php if ($contentEditOn): ?>
<script src="<?= $assetUrl('assets/js/plugins/content-editor.js') ?>"></script>
<?php endif; ?>
<?php if ($hasAudioKind || $mod('contentEdit')): ?>
<script src="<?= $assetUrl('assets/js/plugins/sound-player.js') ?>"></script>
<?php endif; ?>
<?php if ($mod('personExplore')): ?>
<script src="<?= $assetUrl('assets/js/plugins/person-explore.js') ?>"></script>
<?php endif; ?>
<?php if ($mod('map3d')): ?>
<script type="module" src="<?= $assetUrl('assets/js/plugins/map3d.js') ?>"></script>
<?php /* 三個資料插件向 MapLibreEngine 登記 3D 擴充，各自只在對應的 APP.map3d.*Url 有值時動作 */ ?>
<script type="module" src="<?= $assetUrl('assets/js/plugins/map3d-roofs.js') ?>"></script>
<script type="module" src="<?= $assetUrl('assets/js/plugins/map3d-trees.js') ?>"></script>
<script type="module" src="<?= $assetUrl('assets/js/plugins/map3d-power.js') ?>"></script>
<?php endif; ?>
</body>
</html>
