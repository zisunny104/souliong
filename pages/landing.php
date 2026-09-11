<?php
/**
 * Souliong 地圖清單首頁。背景為固定的裝飾地圖（不可互動，中心點見 api/config.php 的 landing_bg_center），內容浮在其上。
 */
$cfg = include __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/settings.php';
require __DIR__ . '/../api/i18n.php';
$apiCfg = require __DIR__ . '/../api/config.php';
$randomExplore = souliong_random_explore_on($apiCfg);
[$LANG, $DICT] = i18n_init();
$t = fn(string $key, array $vars = []): string => htmlspecialchars(i18n_t($DICT, $key, $vars), ENT_QUOTES);
require_once __DIR__ . '/../api/routes.php';   // 網址表：掛載根目錄的算法只有這一份（見 api/routes.php）
require_once __DIR__ . '/../api/layers.php';   // 版權標註共用函式（souliong_credit_html 等）＋圖層解析
$base = Route::base();

$maps = [];
// 全站版權區塊：跨所有專案，把「實際用得到」的圖層署名＋引擎署名去重合併，
// 不是寫死列出全部已知服務——沒有專案在用的圖磚／引擎不該出現在這裡。
// 每個專案自己頁面上的版權角標（in-map attribution）維持只顯示自己那份，互不影響。
$creditParts = [];
$addCredit = function (?array $part) use (&$creditParts, $DICT) {
    $html = souliong_credit_html($part, $DICT);
    if ($html !== '' && !in_array($html, $creditParts, true)) $creditParts[] = $html;
};
// 用 scandir 而不是 glob()：glob 會把路徑裡的中括號當成「字元集合」樣式，
// 安裝在含中括號的目錄下（例如 .../亞洲大學[Asia University]/...）時整個樣式一個檔案都對不到，
// 首頁就會在明明有地圖的情況下顯示「尚未有地圖」。這裡只是逐一列目錄，沒有比對樣式的需要。
$projectsDir = $apiCfg['projects_dir'];
foreach (scandir($projectsDir) ?: [] as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    $mf = $projectsDir . '/' . $entry . '/meta.json';
    if (!is_file($mf)) continue;
    $m = json_decode(file_get_contents($mf), true);
    if (!is_array($m)) continue;
    $maps[] = [
        'id' => $m['id'] ?? $entry,
        'title' => $m['title'] ?? '',
        'subtitle' => $m['subtitle'] ?? '',
        'desc' => $m['desc'] ?? '',
        'center' => $m['center'] ?? [23.9, 120.7],
        'zoom' => $m['zoom'] ?? 14,
        'cover' => is_array($m['cover'] ?? null) ? $m['cover'] : null,
    ];
    $layers = souliong_layers_for($apiCfg, $m, $entry);
    foreach ($layers as $l) {
        $attr = $l['attribution'] ?? null;
        if (is_array($attr)) { foreach ($attr as $part) { if (is_array($part)) $addCredit($part); } }
    }
    if ($layers) $addCredit(souliong_engine_credit($layers));
}
$b = htmlspecialchars($base, ENT_QUOTES);
$esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$bg = [
    'center' => $apiCfg['landing_bg_center'] ?? [23.9, 120.7],
    'zoom'   => $apiCfg['landing_bg_zoom'] ?? 14,
    'offset' => $apiCfg['landing_bg_offset'] ?? [0, 0],
];
?><!DOCTYPE html>
<html lang="<?= $LANG === 'en' ? 'en' : 'zh-Hant' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $t('app_title') ?></title>
<link rel="stylesheet" href="https://unpkg.com/maplibre-gl@6.6.0/dist/maplibre-gl.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{ --bg:#fafafa; --fg:#1b1b1d; --muted:#6b6b70; --line:#e7e7ea; --card:rgba(255,255,255,.86); --accent:#1b1b1d; --accent-fg:#fff; --r-lg:22px; --scrim:rgba(250,250,250,.72); }
@media (prefers-color-scheme: dark){ :root{ --bg:#141416; --fg:#f1f1f3; --muted:#9c9ca3; --line:#2e2e31; --card:rgba(29,29,32,.86); --accent:#f1f1f3; --accent-fg:#141416; --scrim:rgba(20,20,22,.72); } }
*{box-sizing:border-box}
body{margin:0;font-family:system-ui,sans-serif;background:var(--bg);color:var(--fg);-webkit-font-smoothing:antialiased;min-height:100vh}
#bgmap{position:fixed;inset:0;z-index:0;pointer-events:none}
.scrim{position:fixed;inset:0;z-index:1;background:var(--scrim);-webkit-backdrop-filter:blur(1.5px);backdrop-filter:blur(1.5px)}
.page{position:relative;z-index:2;display:flex;flex-direction:column;min-height:100vh}
.wrap{max-width:940px;margin:0 auto;padding:0 20px 24px}
header{text-align:center;padding:60px 20px 26px}
header .logo{font-size:2rem;font-weight:800;letter-spacing:-.02em}
header .tag{color:var(--muted);font-size:0.875rem;margin-top:10px;line-height:1.7}
.bar{display:flex;justify-content:center;gap:10px;margin:24px 0 6px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:8px;border:1px solid var(--line);border-radius:999px;padding:10px 18px;font-size:0.875rem;font-weight:600;background:var(--card);color:var(--fg);cursor:pointer;text-decoration:none;transition:transform .15s,box-shadow .15s;-webkit-backdrop-filter:blur(8px);backdrop-filter:blur(8px)}
.btn.primary{background:var(--accent);color:var(--accent-fg);border-color:var(--accent)}
.btn:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(0,0,0,.15)}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:18px;margin-top:26px}
.card{display:flex;flex-direction:column;text-decoration:none;color:inherit;background:var(--card);border:1px solid var(--line);border-radius:var(--r-lg);overflow:hidden;transition:transform .16s,box-shadow .16s;-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px)}
.card:hover{transform:translateY(-3px);box-shadow:0 16px 44px rgba(0,0,0,.18)}
.card .cover{height:120px;display:flex;align-items:center;justify-content:center;font-size:2.5rem;color:#fff;background:linear-gradient(135deg,#2e9e5b,#2f7ec6 55%,#e34a6f);overflow:hidden}
.card .cover img{width:100%;height:100%;object-fit:cover}
.card .body{padding:16px 18px}
.card h2{margin:0 0 3px;font-size:1.125rem;font-weight:800}
.card .st{color:var(--muted);font-size:0.7813rem;margin-bottom:9px}
.card .desc{font-size:0.8125rem;line-height:1.6}
.empty{text-align:center;color:var(--muted);padding:50px;font-size:0.875rem}
footer{text-align:center;color:var(--muted);font-size:0.75rem;padding:22px;line-height:1.8;margin-top:auto}
footer a{color:inherit}
.langsw{position:fixed;top:16px;right:16px;z-index:3;display:flex;gap:2px;font-size:0.75rem}
.langsw a{color:var(--muted);text-decoration:none;padding:4px 8px;border-radius:999px}
.langsw a.on{color:var(--fg);font-weight:700;background:var(--card);border:1px solid var(--line)}
.cr-line{display:flex;align-items:center;justify-content:center;flex-wrap:wrap}
.cr-sep{width:1px;align-self:stretch;background:var(--line);margin:0 .5em}
@media (max-width:560px){
  .cr-line{flex-direction:column}
  .cr-sep{width:60%;height:1px;align-self:center;margin:.3em 0}
}
</style>
</head>
<body>
<div class="langsw">
  <a href="?lang=zh_TW" class="<?= $LANG === 'zh_TW' ? 'on' : '' ?>">中文</a>
  <a href="?lang=en" class="<?= $LANG === 'en' ? 'on' : '' ?>">English</a>
</div>
<div id="bgmap"></div>
<div class="scrim"></div>
<div class="page">
<header>
  <div class="logo" id="logo"><?= $t('app_title') ?><span id="logoShape" class="logo-shape"></span></div>
  <div class="tag"><?= $t('landing_tagline_1') ?><br><?= $t('landing_tagline_2') ?></div>
  <div class="bar">
    <?php if ($maps && $randomExplore): ?><a class="btn primary" id="randomBtn"><i class="fa-solid fa-shuffle"></i> <?= $t('random_explore_btn') ?></a><?php endif; ?>
    <a class="btn" href="https://github.com/zisunny104/souliong" target="_blank" rel="noopener"><i class="fa-brands fa-github"></i> <?= $t('source_code_btn') ?></a>
  </div>
</header>
<div class="wrap">
  <?php if (!$maps): ?>
    <div class="empty"><?= $t('no_maps_yet') ?><a href="<?= $b ?>manager"><?= $t('create_first_map_link') ?></a><?= $t('or_contact_admin_suffix') ?></div>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($maps as $m): ?>
        <a class="card" href="<?= $b . $esc($m['id']) ?>">
          <div class="cover"><?php if ($m['cover']): ?><img src="<?= $esc(Route::api('cover', ['project' => $m['id']])) ?>" alt="" loading="lazy"><?php else: ?><i class="fa-solid fa-map-location-dot"></i><?php endif; ?></div>
          <div class="body"><h2><?= $esc($m['title']) ?></h2><div class="st"><?= $esc($m['subtitle']) ?></div><div class="desc"><?= $esc($m['desc']) ?></div></div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<footer>
  <div class="cr-line">
    <?php if ($creditParts): ?>
    <span class="cr-ext"><?= implode(' &middot; ', $creditParts) ?></span>
    <span class="cr-sep" aria-hidden="true"></span>
    <?php endif; ?>
    <span class="cr-own">
      <a href="https://github.com/zisunny104/souliong" target="_blank" rel="noopener"><i class="fa-brands fa-github"></i> GitHub</a>
      ・ <a href="<?= $b ?>">Souliong</a>
      ・ <a href="https://toka.dev" target="_blank" rel="noopener">prjToka</a>
    </span>
  </div>
  <?= $t('platform_tagline_footer') ?> ・ <a href="<?= $b ?>privacy"><?= $t('privacy_link_text') ?></a>
</footer>
</div>
<script type="module">
import * as maplibregl from 'https://unpkg.com/maplibre-gl@6.6.0/dist/maplibre-gl.mjs';
var BASE = <?= json_encode($base) ?>, IDS = <?= json_encode(array_map(fn($m) => $m['id'], $maps)) ?>;
var BG = <?= json_encode($bg) ?>;

// 背景地圖用的極簡樣式：直接接 OpenFreeMap 的向量圖磚來源（同 layers/openfreemap-liberty），
// 但只挑水域／公園／建物色塊跟道路留下來自己刻樣式，不套用原本的 111 層 Liberty 樣式——
// 純裝飾用途不需要地名、POI、行政界線這些文字與符號圖層。
function slBgStyle(dark) {
  var pal = dark ? {
    bg: '#141416', water: '#16232b', park: '#172019', building: '#1c1c1f',
    roadMinor: '#2c2c30', roadMedium: '#3c3c42', roadMajor: '#57575f',
  } : {
    bg: '#eeeeee', water: '#cfe3ec', park: '#dde6da', building: '#e2e2e2',
    roadMinor: '#cfcfcf', roadMedium: '#b0b0b0', roadMajor: '#8a8a8a',
  };
  var roadLayout = { 'line-cap': 'round', 'line-join': 'round' };
  return {
    version: 8,
    sources: { openmaptiles: { type: 'vector', url: 'https://tiles.openfreemap.org/planet' } },
    layers: [
      { id: 'bg', type: 'background', paint: { 'background-color': pal.bg } },
      { id: 'park', type: 'fill', source: 'openmaptiles', 'source-layer': 'park', paint: { 'fill-color': pal.park } },
      { id: 'water', type: 'fill', source: 'openmaptiles', 'source-layer': 'water', paint: { 'fill-color': pal.water } },
      { id: 'building', type: 'fill', source: 'openmaptiles', 'source-layer': 'building', minzoom: 13, paint: { 'fill-color': pal.building, 'fill-opacity': 0.8 } },
      {
        id: 'road-minor', type: 'line', source: 'openmaptiles', 'source-layer': 'transportation', layout: roadLayout,
        filter: ['in', ['get', 'class'], ['literal', ['minor', 'service', 'track', 'street', 'street_limited']]],
        paint: { 'line-color': pal.roadMinor, 'line-width': ['interpolate', ['exponential', 1.4], ['zoom'], 12, 0.6, 14, 1.4, 18, 4] },
      },
      {
        id: 'road-medium', type: 'line', source: 'openmaptiles', 'source-layer': 'transportation', layout: roadLayout,
        filter: ['in', ['get', 'class'], ['literal', ['secondary', 'tertiary', 'link']]],
        paint: { 'line-color': pal.roadMedium, 'line-width': ['interpolate', ['exponential', 1.4], ['zoom'], 10, 0.8, 14, 2.2, 18, 6] },
      },
      {
        id: 'road-major', type: 'line', source: 'openmaptiles', 'source-layer': 'transportation', layout: roadLayout,
        filter: ['in', ['get', 'class'], ['literal', ['motorway', 'trunk', 'primary']]],
        paint: { 'line-color': pal.roadMajor, 'line-width': ['interpolate', ['exponential', 1.4], ['zoom'], 10, 1.2, 14, 3.5, 18, 9] },
      },
    ],
  };
}

var mq = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)');
var center = BG.center || [23.9, 120.7];
var lngLat = [center[1], center[0]];
var map = new maplibregl.Map({
  container: 'bgmap', style: slBgStyle(!!(mq && mq.matches)),
  center: lngLat, zoom: BG.zoom || 14,
  interactive: false, attributionControl: false,
});
// offset：把中心點在畫面上往上推，理由見 api/config.php 的 landing_bg_offset 註解——
// 建構子的 center 只會落在容器正中央，要偏移得靠 jumpTo() 的 CameraOptions.offset 重新對一次。
if (BG.offset && (BG.offset[0] || BG.offset[1])) {
  map.jumpTo({ center: lngLat, zoom: BG.zoom || 14, offset: BG.offset });
}
if (mq && mq.addEventListener) mq.addEventListener('change', function (e) { map.setStyle(slBgStyle(e.matches)); });

var rb = document.getElementById('randomBtn');
if (rb) rb.onclick = function(){ location.href = BASE + IDS[Math.floor(Math.random()*IDS.length)]; };
</script>
</body>
</html>
