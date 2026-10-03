<?php
/**
 * 導航選單小頁：GET ?api=navsheet&project=<slug>&spot=<spotId|num>&embed=1[&theme=light|dark|auto][&lang=]
 * 只輸出「用哪個軟體導航」選單，給 iframe／modal 引用。選項清單、連結模板、平台條件與地圖頁的點位
 * 導航選單同一份（api/navlinks.php）；樣式沿用 .p-nav-item（assets/css/spot-panel.css）。
 *
 * 無狀態：不開 session、不送 Set-Cookie、不帶起點、不做定位。外部連結一律 target=_blank
 * rel="noopener noreferrer"。收起時由 assets/js/navsheet.js 對父頁 postMessage
 * {v:1, ns:"souliong", type:"navsheet-close"}，目標 origin 只取自嵌入允許清單（api/embedorigins.php）。
 * 框架標頭：embed=1 且清單非空才送 frame-ancestors 並移除 PHP 端 XFO；其餘一律只准同源嵌入。
 */
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/spotlib.php';
require_once __DIR__ . '/oglib.php';
require_once __DIR__ . '/navlinks.php';
require_once __DIR__ . '/embedorigins.php';
require_once __DIR__ . '/i18n.php';
$cfg = require __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    json_out(['error' => 'method not allowed'], 405);
}
$cfg['rate_limits']['navsheet'] = $cfg['rate_limits']['navsheet'] ?? ['max' => 120, 'window' => 60];
rate_limit($cfg, 'navsheet');

[$LANG, $DICT] = i18n_init(false);
$esc = fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$t = fn(string $k): string => $esc(i18n_t($DICT, $k));
$asset = fn(string $rel): string => $esc(Route::api('appasset', ['f' => $rel, 'v' => (string)(@filemtime(__DIR__ . '/../' . $rel) ?: 0)]));

$slug = (string)($_GET['project'] ?? '');
$meta = null;
if (preg_match('/^[a-z0-9_-]{1,40}$/D', $slug)) {
    $mf = project_dir($cfg, $slug) . '/meta.json';
    $meta = is_file($mf) ? json_decode((string)@file_get_contents($mf), true) : null;
    if (!is_array($meta)) $meta = null;
}

$embed = (($_GET['embed'] ?? '') === '1');
$origins = embed_origins_allowed($cfg, $meta);
// 不在允許清單內（含非 embed 模式、清單為空）一律只准同源嵌入，不依賴框架或伺服器是否另外送 XFO
if (!($embed && embed_send_frame_headers($origins))) {
    header("Content-Security-Policy: frame-ancestors 'self'");
    header('X-Frame-Options: SAMEORIGIN');
}

$spot = $meta !== null ? spot_effective_by_ref($cfg, $slug, (string)($_GET['spot'] ?? '')) : null;
$lat = $spot['lat'] ?? null;
$lon = $spot['lon'] ?? null;
$found = $spot !== null && is_numeric($lat) && is_numeric($lon) && abs((float)$lat) <= 90 && abs((float)$lon) <= 180;

$theme = (string)($_GET['theme'] ?? '');
$themeAttr = ($theme === 'light' || $theme === 'dark') ? ' data-theme="' . $theme . '"' : '';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if ($found) {
    header('Cache-Control: public, max-age=60');
} else {
    http_response_code(404);
    header('Cache-Control: no-store');
}

$title = '';
$items = [];
if ($found) {
    $title = souliong_og_spot_title(souliong_og_spot_name($spot), (int)$spot['num'], (string)($meta['numbering'] ?? 'suffix'));
    foreach (souliong_nav_apps() as $a) {
        $items[] = [
            'a' => $a,
            'url' => souliong_nav_url($a['url'], (float)$lat, (float)$lon, souliong_og_spot_name($spot)),
        ];
    }
}
$closeLabel = $t('nav_sheet_close');
?><!DOCTYPE html>
<html lang="<?= $LANG === 'en' ? 'en' : 'zh-Hant' ?>"<?= $themeAttr ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title><?= $t('nav_menu_title') ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= $asset('assets/css/theme.css') ?>">
<link rel="stylesheet" href="<?= $asset('assets/css/control-card.css') ?>">
<link rel="stylesheet" href="<?= $asset('assets/css/spot-panel.css') ?>">
<link rel="stylesheet" href="<?= $asset('assets/css/navsheet.css') ?>">
</head>
<body class="nsheet-body" data-nsheet-origins="<?= $esc(json_encode($embed ? $origins : [], JSON_UNESCAPED_SLASHES)) ?>">
<div class="nsheet-backdrop" id="nsheetBackdrop"></div>
<section class="nsheet" id="nsheet" role="dialog" aria-modal="true" aria-labelledby="nsheetTitle">
  <div class="nsheet-grab" id="nsheetGrab" aria-hidden="true"><span></span></div>
  <header class="nsheet-head">
    <div class="nsheet-titles">
      <h1 class="nsheet-title" id="nsheetTitle"><?= $found ? $esc($title !== '' ? $title : i18n_t($DICT, 'nav_menu_title')) : $t('nav_sheet_notfound') ?></h1>
      <?php if ($found && $title !== ''): ?><p class="nsheet-sub"><?= $t('nav_menu_title') ?></p><?php endif; ?>
    </div>
    <button class="btn small nsheet-close" type="button" id="nsheetClose" title="<?= $closeLabel ?>" aria-label="<?= $closeLabel ?>"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
  </header>
<?php if ($found): ?>
  <nav class="nsheet-list" role="menu" aria-label="<?= $t('nav_menu_title') ?>">
<?php foreach ($items as $it): $a = $it['a']; $web = (bool)preg_match('#^https?:#i', $it['url']); $ios = ($a['platform'] ?? 'all') === 'ios'; ?>
    <a class="p-nav-item nsheet-item" role="menuitem" href="<?= $esc($it['url']) ?>"<?= $web ? ' target="_blank" rel="noopener noreferrer"' : '' ?><?= $ios ? ' data-nsheet-platform="ios" hidden' : '' ?>>
      <?php if (!empty($a['icon'])): ?><i class="<?= $esc($a['icon']) ?>" aria-hidden="true"></i> <?php endif; ?><?= $t($a['lang']) ?>
    </a>
<?php endforeach; ?>
  </nav>
<?php else: ?>
  <p class="nsheet-empty"><?= $t('nav_sheet_notfound_desc') ?></p>
<?php endif; ?>
</section>
<script src="<?= $asset('assets/js/navsheet.js') ?>"></script>
</body>
</html>
