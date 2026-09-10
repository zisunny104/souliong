<?php
/**
 * Souliong 主視圖。base 路徑自動推導、資料伺服器端內嵌（框架不供應靜態檔）。
 * ?embed=1 → 精簡檢視模式（僅供瀏覽）。?p=<project> → 切換項目。
 */
$cfg = include __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/routes.php';   // 網址表：掛載根目錄與各種網址的算法，全站只有這一份
$base = Route::base();

$b       = htmlspecialchars($base, ENT_QUOTES);
$embed   = (($_GET['embed'] ?? '') === '1');
$proj    = preg_replace('/[^a-z0-9_-]/', '', $_GET['p'] ?? ($cfg['default_project'] ?? 'chairs'));
$metaF   = __DIR__ . '/../projects/' . $proj . '/meta.json';
$meta    = is_file($metaF) ? json_decode(file_get_contents($metaF), true) : null;
$ptsF    = $meta ? __DIR__ . '/../projects/' . $proj . '/' . ($meta['points'] ?? 'points.json') : null;
$points  = ($ptsF && is_file($ptsF)) ? json_decode(file_get_contents($ptsF), true) : [];

// 已用管理 PIN 登入者（主 PIN 或此專案的 PIN）直接視為已解鎖投稿身分，不受投稿碼限制
require __DIR__ . '/../api/security.php';
require __DIR__ . '/../api/i18n.php';
require __DIR__ . '/../api/features.php';
require_once __DIR__ . '/../api/packs.php';
require_once __DIR__ . '/../api/layers.php';
require_once __DIR__ . '/../api/regions3d.php';
$apiCfg    = require __DIR__ . '/../api/config.php';
$isManager = admin_can($apiCfg, $proj);
// 投稿開關＝有沒有還有效的投稿碼（真正的碼在伺服器端 codes.json，前端拿不到）。
// APP.gated 因此變成「現在有碼可解鎖」：一組都沒有時前端連解鎖鈕都不出現。
$gated = contrib_open($apiCfg, $proj);
// 定位點編輯（editpoint.php）走的是這個公開頁面而非後台頁，因此比照 manager.php 的作法，
// 帶一份「同源才讀得到」的 CSRF 驗證值，只在已登入管理者時計算並輸出。
// 管理者三種登入方式都要各自對應到正確的衍生值，否則其中一種身分送出的請求會被誤判成 CSRF 失效。
$isPrimaryAuthed = primary_authed($apiCfg);
$acctForCsrf    = ($isManager && !$isPrimaryAuthed) ? account_current($apiCfg) : null;
$csrfTok   = !$isManager ? null : ($isPrimaryAuthed
    ? primary_derived($apiCfg)
    : ($acctForCsrf !== null ? account_derived($apiCfg, (string)$acctForCsrf['id']) : padm_derived($apiCfg, $proj, (string)padm_pin_id($apiCfg, $proj))));
[$LANG, $DICT] = i18n_init();
$t = fn(string $key, array $vars = []): string => htmlspecialchars(i18n_t($DICT, $key, $vars), ENT_QUOTES);
$mod = fn(string $key): bool => souliong_module_on($meta, $key);
// 每個模組解析後（含未來的相依關係）的開關結果，前端 MOD() 直接讀這份、不再自己重算預設值邏輯，
// 避免 PHP 端 $mod() 與 JS 端各自判斷、日後模組間有相依時兩邊算出不同答案。
$moduleState = array_combine(array_keys(souliong_modules()), array_map($mod, array_keys(souliong_modules())));
$pack = souliong_pack_for($apiCfg, $meta, $proj);
// 這張地圖由下往上要疊哪幾層圖磚／插畫。跟 $pack 同樣是「用哪一包」而非布林開關，差別只在
// 圖層是有序陣列。相對路徑的圖檔在這裡就被改寫成 <base>/layer/... 絕對網址，前端不必分辨。
$layers = souliong_layers_public($apiCfg, $meta, $proj, $base);
// 這張地圖的主引擎：只要 layers 裡有任何一筆 type 是 vector，整顆地圖就改用 MapLibreEngine
// （向量 style 本身就是完整一張圖，沒辦法像光柵圖層一樣只疊局部——見 maplibre-engine.js 開頭註解）。
// 逐專案 opt-in：沒特別選向量底圖的專案這個值一律是 leaflet，行為與拆分之前完全一樣。
$primaryEngine = 'leaflet';
foreach ($layers as $l) {
    if (($l['type'] ?? '') === 'vector') { $primaryEngine = 'maplibre'; break; }
}
// 這張地圖開放哪些投稿型別、對話框預設開哪一頁、誰能建立地點（meta.json 的 contrib 區塊）。
// 跟 $moduleState 同樣的原則：PHP 端解析一次，前端直接讀 APP.contrib，不在兩邊各自算預設值。
$contribCfg = souliong_contrib_cfg($meta);
// 要載入哪些型別檔（assets/js/contrib/kind-*.js）。「檔案有沒有被輸出」就是型別的開關——
// 前端不需要再對 contrib.kinds 過濾一次，純文字的地圖也不會載到影片抽幀那段程式碼。
// 建立地點是權限而非型別：設成 admin 時只有已登入的管理者拿得到那支檔案。
$contribFiles = $mod('upload') ? $contribCfg['kinds'] : [];
if ($contribFiles && ($contribCfg['newPoint'] === 'contributor' || ($contribCfg['newPoint'] === 'admin' && $isManager))) {
    $contribFiles[] = 'newpoint';
}
// 3D 模式關掉時整個 key 是 null，前端 map3d.js 本身也不會被載入(見下方 $mod('map3d') 輸出)，
// 兩邊一起判斷、不是只看其中一邊，plugin 缺席時 APP.map3d 也沒有殘留資料可用。
$map3d = $mod('map3d') ? [
    'styleUrl'            => (string)($apiCfg['map3d_style_url'] ?? ''),
    'key'                 => (string)($apiCfg['map3d_key'] ?? ''),
    'regions'             => souliong_region3d_public_list($apiCfg, $proj, $base),
    'excludedBuildingIds' => souliong_region3d_excluded_ids($apiCfg, $proj),
] : null;

$APP = [
    'base'        => $base,
    // 這張地圖的後台網址。前端有三個地方要用到（登入 POST、邀請兌換 POST、登入後跳轉），
    // 由伺服器端算好給它，網址形狀就只寫在 api/routes.php 一處。
    'manager'     => Route::manager($proj),
    'project'     => $proj,
    'embed'       => $embed,
    'gated'       => $gated,
    'meta'        => $meta,
    'points'      => $points,
    'isManager'   => $isManager,
    'csrf'        => $csrfTok,
    'moduleState' => $moduleState,
    'contrib'     => $contribCfg,
    'pack'        => $pack,
    'layers'      => $layers,
    'engine'      => $primaryEngine,
    'map3d'       => $map3d,
];
$jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS;
$esc = fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
// 產生第一方 CSS／JS 網址（經 api/appasset.php 供應），版本號取檔案 mtime 供快取失效用。
$assetUrl = function (string $rel) use ($esc): string {
    $abs = __DIR__ . '/../' . $rel;
    return $esc(Route::api('appasset', ['f' => $rel, 'v' => (string)(@filemtime($abs) ?: 0)]));
};
?><!DOCTYPE html>
<html lang="<?= $LANG === 'en' ? 'en' : 'zh-Hant' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title><?= $t('app_title') ?></title>
<?php if ($primaryEngine === 'leaflet'): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<?php endif; ?>
<?php if ($mod('map3d') || $primaryEngine === 'maplibre'): /* 大型第三方函式庫走真的 <link>/<script src>,不用 readfile() 內嵌,才吃得到瀏覽器快取(理由同下方 leaflet.js)；
     向量主引擎的專案就算沒開 3D 開關也要拿到 MapLibre 本體，見下方 script 段落同款條件 */ ?>
<link rel="stylesheet" href="https://unpkg.com/maplibre-gl@6.6.0/dist/maplibre-gl.css">
<?php endif; ?>
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
// 依 style.css 原本的層疊順序列出各檔（見 assets/css/），新增樣式分類時在陣列加檔名即可。
$cssFiles = ['theme', 'control-card', 'popups', 'map-markers', 'point-panel', 'map-controls', 'lightbox', 'page-frame'];
// 投稿對話框的樣式跟著它的外掛走：唯讀地圖根本不會有 #contribModal，沒必要送這段 CSS
if ($mod('upload')) {
    $cssFiles[] = 'contrib';
}
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
<body class="<?= $embed ? 'embed' : '' ?>">

<div id="skeleton" aria-hidden="true">
  <div class="sk-card sk">
    <div class="sk-line" style="width:55%"></div>
    <div class="sk-line" style="width:80%"></div>
    <div class="sk-line" style="width:80%"></div>
    <div class="sk-row"><span class="sk-btn"></span><span class="sk-btn"></span></div>
  </div>
  <div class="sk-fab sk"></div>
</div>

<div id="map"></div>

<div id="controls" class="floatcard">
  <div class="ctl-head">
    <span class="brand" id="title" title="<?= $t('brand_hint') ?>" role="button" tabindex="0"><span class="brand-txt" id="titleTxt"><?= $t('app_title') ?></span></span>
    <span id="brandShape" class="brand-shape" aria-hidden="true"></span>
    <span class="spacer"></span>
    <button id="collapseBtn" class="icon-btn" title="<?= $t('collapse') ?>" aria-label="<?= $t('collapse_aria') ?>"><i class="fa-solid fa-chevron-down" aria-hidden="true"></i></button>
  </div>
  <div class="ctl-body" id="ctlBody">
    <div class="legend" id="legend"></div>
    <div class="ctl-row">
      <button class="btn" id="allPointsBtn" title="<?= $t('show_all_points') ?>"><i class="fa-solid fa-layer-group"></i> <?= $t('all') ?></button>
      <button class="btn" id="photoLayerBtn" title="<?= $t('filter_by_contrib') ?>"><i class="fa-solid fa-photo-film"></i> <?= $t('contrib') ?></button>
    </div>
    <div class="ctl-row">
      <select id="personFilter" title="<?= $t('filter_person') ?>"><option value=""><?= $t('all_contributors') ?></option></select>
    </div>
    <div class="ctl-foot" id="foot"></div>
  </div>
</div>

<div id="topright" class="tr-group">
  <button class="icon-btn tr-toggle" id="trToggle" title="<?= $t('more_options') ?>" aria-label="<?= $t('expand_options_aria') ?>" aria-expanded="false" aria-controls="trItems"><i class="fa-solid fa-bars" aria-hidden="true"></i></button>
  <div class="tr-items" id="trItems">
    <?php if ($mod('homeLink')): ?><a class="icon-btn hide-in-embed" id="homeBtn" href="<?= $b ?>" title="<?= $t('back_to_list') ?>" aria-label="<?= $t('back_to_list') ?>"><i class="fa-solid fa-house" aria-hidden="true"></i></a><?php endif; ?>
    <button id="themeBtn" class="icon-btn" title="<?= $t('toggle_theme') ?>" aria-label="<?= $t('toggle_theme_aria') ?>"><i class="fa-solid fa-circle-half-stroke" aria-hidden="true"></i></button>
    <button id="pointsVisBtn" class="icon-btn" title="<?= $t('toggle_points') ?>" aria-label="<?= $t('toggle_points_aria') ?>" aria-pressed="false"><i class="fa-solid fa-eye" aria-hidden="true"></i></button>
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
  </div>
</div>

<button class="icon-btn mapop-btn" id="resetBtn" title="<?= $t('reset_view') ?>" aria-label="<?= $t('reset_view_aria') ?>"><i class="fa-solid fa-location-crosshairs" aria-hidden="true"></i></button>

<input type="text" id="myName" hidden>

<div id="cloudWarn" class="toast" style="display:none"></div>

<div id="panel">
  <button class="p-expand" onclick="MapApp.togglePanelSize()" aria-label="<?= $t('expand_panel') ?>" title="<?= $t('expand_panel') ?>"><i class="fa-solid fa-up-right-and-down-left-from-center" aria-hidden="true"></i></button>
  <button class="p-close" onclick="MapApp.closePanel()" aria-label="<?= $t('close') ?>"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
  <div class="p-head">
    <div class="cat" id="pCat"></div>
    <h2 id="pTitle"></h2>
    <div class="sub" id="pSub"></div>
    <button class="btn small" id="pointEditBtn" type="button" style="display:none"><i class="fa-solid fa-location-dot"></i> <?= $t('adjust_location') ?></button>
    <div class="photo-editor point-editor" id="pointEditor" style="display:none"></div>
  </div>
  <div class="p-body">
    <div id="entries"></div>
  </div>
</div>

<?php if ($mod('upload')): ?>
<div id="unlockDialog" class="dialog">
  <div class="dialog-box">
    <div class="dialog-head"><b><?= $t('unlock_contrib') ?></b><button class="icon-btn" onclick="MapApp.closeUnlock()" aria-label="<?= $t('close') ?>"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <div class="hint"><?= $t('unlock_hint') ?></div>
    <input id="unlockCodeInput" class="name-in" style="width:100%;letter-spacing:8px;text-align:center;font-size:1.375rem" placeholder="<?= $t('six_digits') ?>" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" maxlength="8" data-pin-toggle data-pin-slots="6" aria-label="<?= $t('contrib_code') ?>">
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
    <input id="pinInput" class="name-in" style="width:100%;text-align:center;font-size:1.125rem" type="password" placeholder="PIN" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-pin-toggle data-pin-slots="4" data-pin-keypad aria-label="<?= $t('pin_input_area') ?>">
    <div id="pinMsg" class="hint" role="status"></div>
    <div class="dialog-actions">
      <span class="spacer"></span>
      <button class="btn primary" id="pinSubmitBtn"><?= $t('confirm_ok') ?></button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- #lbMedia 是影片／音訊的播放槽：播放器要能點（拖進度條、按暫停），所以它自己吞掉 click，
     不能跟照片一樣讓點擊冒泡到 #lb 去關燈箱。內容由 openLightbox() 每次重建，關閉時清空停止播放。 -->
<div id="lb" onclick="MapApp.closeLightbox()"><img id="lbImg" alt=""><div id="lbMedia" style="display:none" onclick="event.stopPropagation()"></div><div class="cap" id="lbCap"></div><div class="photo-editor" id="lbEditor" style="display:none" onclick="event.stopPropagation()"></div></div>

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
<?php if ($primaryEngine === 'leaflet'): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<?php endif; ?>
<?php if ($mod('map3d')): /* MapLibre v6 只出 ESM 版(dist/maplibre-gl.mjs),沒有 <script src> 吃得下去的全域
     版本了——用內嵌 type="module" 匯入後手動掛回 window.maplibregl,讓 map3d.js 仍以傳統全域變數方式
     使用它。map3d.js 也要標成 type="module",確保它排進「文件解析完才依序執行」這一批,晚於這段 shim
     ——3D 模式一律要使用者先按下切換鈕才會用到 maplibregl,不會跟這段非同步載入搶時間。
     主引擎是 MapLibre（$primaryEngine==='maplibre'）時不吃這段 shim：MapLibreEngine 掛載發生在
     頁面一開始同步的 boot() 裡，等不了這段要等文件解析完才執行的 ESM 全域變數，所以 viewer.core.js
     的 boot() 自己用 import() 動態載入同一份 MapLibre 並掛回 window.maplibregl，做法比照
     maplibre-engine.js 對 three.js 的 lazy-load（見該檔 _maybeLoadThree()）；兩邊載入同一個網址時
     瀏覽器的 module 快取本來就會共用，不會重複下載，這裡不用特別判斷「已經載過了」再跳過。 */ ?>
<script type="module">
import * as maplibregl from 'https://unpkg.com/maplibre-gl@6.6.0/dist/maplibre-gl.mjs';
window.maplibregl = maplibregl;
</script>
<?php endif; ?>
<?php if (in_array('photo', $contribFiles, true)): /* EXIF 讀取與 HEIC 轉檔只有照片投稿用得到（見 assets/js/contrib/kind-photo.js） */ ?>
<script src="https://cdn.jsdelivr.net/npm/exifr/dist/full.umd.js"></script>
<script src="https://cdn.jsdelivr.net/npm/heic2any@0.0.4/dist/heic2any.min.js"></script>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
<script src="<?= $assetUrl('assets/js/pin-input.js') ?>"></script>
<script src="<?= $assetUrl('assets/js/engine/map-engine.js') ?>"></script>
<?php if ($primaryEngine === 'leaflet'): ?>
<script src="<?= $assetUrl('assets/js/engine/leaflet-engine.js') ?>"></script>
<?php endif; ?>
<?php if ($primaryEngine === 'maplibre' || $mod('map3d')): ?>
<script src="<?= $assetUrl('assets/js/engine/maplibre-engine.js') ?>"></script>
<?php endif; ?>
<script src="<?= $assetUrl('assets/js/viewer.core.js') ?>"></script>
<?php if ($mod('identity')): ?>
<script src="<?= $assetUrl('assets/js/plugins/contributor-identity.js') ?>"></script>
<?php endif; ?>
<?php if ($contribFiles): /* 型別檔要在外掛之前載入：外掛開機時就要有完整的型別註冊表才能決定分頁 */ ?>
<script src="<?= $assetUrl('assets/js/contrib/kind-base.js') ?>"></script>
<?php foreach ($contribFiles as $kf): ?>
<script src="<?= $assetUrl('assets/js/contrib/kind-' . $kf . '.js') ?>"></script>
<?php endforeach; ?>
<script src="<?= $assetUrl('assets/js/plugins/contribution.js') ?>"></script>
<?php endif; ?>
<?php if ($mod('embed')): ?>
<script src="<?= $assetUrl('assets/js/plugins/embed-code.js') ?>"></script>
<?php endif; ?>
<?php if ($mod('share')): ?>
<script src="<?= $assetUrl('assets/js/vendor/qrcode-generator.js') ?>"></script>
<script src="<?= $assetUrl('assets/js/plugins/share-link.js') ?>"></script>
<?php endif; ?>
<?php if ($mod('route')): ?>
<script src="<?= $assetUrl('assets/js/plugins/route-tour.js') ?>"></script>
<?php endif; ?>
<?php if ($mod('story')): ?>
<script src="<?= $assetUrl('assets/js/plugins/story-editor.js') ?>"></script>
<?php endif; ?>
<?php if ($mod('personExplore')): ?>
<script src="<?= $assetUrl('assets/js/plugins/person-explore.js') ?>"></script>
<?php endif; ?>
<?php if ($mod('map3d')): ?>
<script type="module" src="<?= $assetUrl('assets/js/plugins/map3d.js') ?>"></script>
<?php endif; ?>
</body>
</html>
