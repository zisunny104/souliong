<?php
// 常駐工具：建立新專案。獨立工具頁（比照 tilecut.php／layermigrate.php），不經過 manager.php，
// 只建立「基本資訊＋地圖位置」這組最小可用設定；numbering／pinMark／pack／mapLabelLang／layers
// 等外觀與進階欄位刻意留給既有的「編輯專案描述」對話框（那裡的 fallback 本來就是正確的起始狀態）。
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/routes.php';   // 網址表：後台網址只有這一份定義（見 api/routes.php）
require_once __DIR__ . '/features.php';
require_once __DIR__ . '/../pages/error.php';
$cfg = require __DIR__ . '/config.php';
rate_limit($cfg, 'admin');
[$LANG, $DICT] = i18n_init();
$t  = fn(string $key, array $vars = []): string => htmlspecialchars(i18n_t($DICT, $key, $vars), ENT_QUOTES);
$tr = fn(string $key, array $vars = []): string => i18n_t($DICT, $key, $vars);
$esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// id 清洗：跟 manager.php 的 clean_id() 同一條規則，但這支獨立工具不依賴 manager.php，自己內嵌一份。
$cleanId = fn($raw): string => (string)preg_replace('/[^a-z0-9_-]/', '', (string)$raw);

// index.php 的 $action switch 是唯一路由入口，一個專案 id 若撞上這些字，/<id> 永遠會先命中
// 工具／API 而不是這張地圖（見 index.php 的 default 分支只在沒撞到任何 case 時才會走到）。
// 這份清單要跟 index.php 的 case 標籤同步——加新工具時記得回來補一個。
const NEWPROJECT_RESERVED = [
    'list', 'upload', 'photo', 'media', 'layer', 'model3d', 'osm', 'appasset', 'cover', 'pinmark',
    'delete', 'editentry', 'editspot', 'newspot', 'spotcontent', 'unlock', 'exiffix', 'thumbfix',
    'tilecut', 'region3d', 'layermigrate', 'newproject', 'stat', 'admin', 'manager', 'privacy', 'index',
];

// ── 即時可用性檢查（GET，唯讀，免 CSRF）──
if (($_GET['action'] ?? '') === 'check') {
    if (!Auth::can($cfg, null, 'create_project')) {
        json_out(['error' => $tr('primary_only_newproject_msg')], 403);
    }
    $id = $cleanId($_GET['id'] ?? '');
    $available = $id !== '' && !in_array($id, NEWPROJECT_RESERVED, true) && !is_dir(project_dir($cfg, $id));
    json_out(['available' => $available]);
}

// ── 建立（POST，JSON 回應）──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $actor = Auth::require($cfg, null, 'create_project', true, $tr('primary_only_newproject_msg'));

    $id = $cleanId($_POST['id'] ?? '');
    if ($id === '' || in_array($id, NEWPROJECT_RESERVED, true)) {
        json_out(['error' => $tr('newproject_bad_id_msg')], 400);
    }
    $dir = project_dir($cfg, $id);
    if (is_dir($dir)) {
        json_out(['error' => $tr('newproject_id_taken_msg')], 409);
    }

    // 標題必填：同 action=meta 的 trim＋300 字 UTF-8 安全截斷規則，差別是空值在這裡是硬性錯誤
    $rawTitle = trim((string)($_POST['title'] ?? ''));
    $title = preg_replace('/^(.{0,300}).*$/su', '$1', $rawTitle);
    if ($title === null || $title === '') {
        json_out(['error' => $tr('newproject_title_required_msg')], 400);
    }
    $meta = ['title' => $title];
    foreach (['subtitle', 'desc', 'source', 'credit'] as $k) {
        $raw = trim((string)($_POST[$k] ?? ''));
        $v = preg_replace('/^(.{0,300}).*$/su', '$1', $raw);
        if ($v === null || $v === '') continue;   // 空值或非法 UTF-8：這欄就不寫入，跟 action=meta 的「留白＝不設定」一致
        $meta[$k] = $v;
    }

    // 地圖位置：拖曳標記＋地圖縮放算出來的結果，伺服器端仍驗證範圍，避免表單被竄改送出壞座標
    $lat = num_or_null($_POST['lat'] ?? null);
    $lon = num_or_null($_POST['lon'] ?? null);
    $zoom = num_or_null($_POST['zoom'] ?? null);
    if ($lat === null || $lon === null || $zoom === null || abs($lat) > 90 || abs($lon) > 180 || $zoom < 0 || $zoom > 24) {
        json_out(['error' => $tr('newproject_bad_center_msg')], 400);
    }
    $num = fn(float $v) => $v == floor($v) ? (int)$v : $v;   // 整數值存成整數，跟手寫的 meta.json 同一種風格
    $meta['center'] = [$num($lat), $num($lon)];
    $meta['zoom'] = $num($zoom);

    // 功能模組：只有這區塊曾展開並送出（modules_submitted）才寫入，完全比照 action=meta 的 checkbox-group 慣例
    if (isset($_POST['features']) || isset($_POST['modules_submitted'])) {
        $features = is_array($_POST['features'] ?? null) ? $_POST['features'] : [];
        foreach (souliong_modules() as $mk => $info) {
            if ($mk === 'personExplore') continue;
            $meta['features'][$mk] = isset($features[$mk]);
        }
        $meta['personExplore'] = isset($_POST['personExplore']);
    }

    // 投稿設定：同樣靠 contrib_submitted 旗標分辨「這次有送出」與「這張表單根本沒有這一區」
    if (isset($_POST['contrib_submitted'])) {
        $want = is_array($_POST['contrib_kinds'] ?? null) ? array_keys($_POST['contrib_kinds']) : [];
        $kinds = array_values(array_intersect(souliong_contrib_kinds(), $want));
        if (!$kinds) $kinds = ['photo'];
        $meta['contrib'] = [
            'kinds' => $kinds,
            'default' => (string)($_POST['contrib_default'] ?? ''),
            'newPoint' => (string)($_POST['contrib_newspot'] ?? 'off'),
        ];
        $ccfg = souliong_contrib_cfg($meta);
        $meta['contrib']['default'] = $ccfg['default'];
        $meta['contrib']['newPoint'] = $ccfg['newPoint'];
    }

    if (!@mkdir($dir, 0775, true)) {
        json_out(['error' => $tr('newproject_mkdir_failed_msg')], 500);
    }
    $json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || !@file_put_contents($dir . '/meta.json', $json, LOCK_EX)) {
        @rmdir($dir);
        json_out(['error' => $tr('newproject_write_failed_msg')], 500);
    }
    audit_log($cfg, $actor->audit(), 'create_project', $id, $title);
    json_out(['ok' => true, 'id' => $id, 'redirect' => Route::manager($id, 'access') . '?new=' . rawurlencode($id)]);
}

// ── 頁面（GET）：primary 以外一律 403，fail-closed ──
if (!Auth::can($cfg, null, 'create_project')) {
    error_page(403, $t('no_permission_title'), $t('primary_only_newproject_msg'), Route::manager('', 'tools'), $t('back_to_admin'));
}
$actor = Auth::actor($cfg, null);
$csrf = (string)$actor->csrf(null);
$adminUrl = $esc(Route::abs(Route::manager('', 'tools')));
$selfUrl = Route::tool('newproject');
$checkUrl = Route::tool('newproject', '', ['action' => 'check']);

$offModules = array_filter(souliong_modules(), fn($info) => ($info['default'] ?? true) === false);
$offLabels = implode('、', array_map(fn($info) => $info['label'], $offModules));
$modulesSummary = $tr('newproject_modules_summary', ['off' => $offLabels]);

$ckinds = souliong_kinds();
$ctabs = [];
foreach (souliong_contrib_kinds() as $ck) {
    $tb = $ckinds[$ck]['tab'];
    if (!in_array($tb, $ctabs, true)) $ctabs[] = $tb;
}
$ccur = souliong_contrib_cfg(null);
?>
<!doctype html>
<html lang="<?= $LANG === 'en' ? 'en' : 'zh-Hant' ?>">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex">
  <title><?= $t('newproject_title') ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
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
      max-width: 40rem;
      margin: 0 auto
    }

    .langsw {
      max-width: 40rem;
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
      gap: var(--sp-2)
    }

    h2 {
      font-size: 0.875rem;
      margin: 0 0 var(--sp-2);
      display: flex;
      align-items: center;
      gap: var(--sp-2)
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

    label span {
      font-weight: 700;
      color: var(--fg)
    }

    .req {
      color: var(--accent)
    }

    select,
    input[type=text],
    input[type=number],
    textarea {
      width: 100%;
      border: 1px solid var(--line);
      border-radius: 0.625rem;
      background: var(--bg);
      color: var(--fg);
      padding: var(--sp-2) var(--sp-3);
      font-size: 0.875rem;
      min-height: 2.25rem;
      font-family: inherit;
      margin-bottom: var(--sp-3)
    }

    textarea {
      resize: vertical
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

    .ok {
      color: #2a8a4a
    }

    .no {
      color: var(--accent)
    }

    #map {
      height: 20rem;
      border-radius: 0.75rem;
      border: 1px solid var(--line);
      margin-bottom: var(--sp-2);
      background: var(--bg)
    }

    .latlon {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: var(--sp-3)
    }

    .latlon input {
      margin-bottom: 0
    }

    details.card>summary {
      cursor: pointer;
      list-style: none;
      display: block;
      margin: calc(var(--sp-4) * -1) calc(var(--sp-5) * -1) 0;
      padding: var(--sp-4) var(--sp-5)
    }

    details.card>summary::-webkit-details-marker {
      display: none
    }

    details.card[open]>summary {
      border-bottom: 1px solid var(--line);
      margin-bottom: var(--sp-4)
    }

    .modhead {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: var(--sp-2)
    }

    .modhead h2 {
      margin: 0
    }

    .chevron {
      transition: transform .15s;
      color: var(--muted)
    }

    details.card[open] .chevron {
      transform: rotate(180deg)
    }

    .modrow {
      display: flex;
      align-items: flex-start;
      gap: var(--sp-2);
      padding: var(--sp-2) 0;
      border-top: 1px solid var(--line)
    }

    .modrow:first-of-type {
      border-top: none
    }

    .modrow input {
      margin-top: 0.2rem
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
    <a href="<?= $esc(Route::tool('newproject', '', ['lang' => 'zh_TW'])) ?>" class="<?= $LANG === 'zh_TW' ? 'on' : '' ?>">中文</a>
    <a href="<?= $esc(Route::tool('newproject', '', ['lang' => 'en'])) ?>" class="<?= $LANG === 'en' ? 'on' : '' ?>">English</a>
  </div>
  <div class="wrap">
    <h1><i class="fa-solid fa-plus"></i> <?= $t('newproject_heading') ?></h1>

    <form id="npform">
      <div class="card">
        <h2><i class="fa-solid fa-1"></i> <?= $t('newproject_basic_heading') ?></h2>
        <label for="idInput"><span><?= $t('newproject_id_label') ?></span> <span class="req">*</span></label>
        <input type="text" id="idInput" name="id" autocomplete="off" spellcheck="false" required>
        <div class="hint" id="idHint"></div>
        <div class="hint" style="margin-bottom:var(--sp-3)"><?= $t('newproject_id_hint') ?></div>

        <label for="titleInput"><span><?= $t('field_title_label') ?></span> <span class="req">*</span></label>
        <input type="text" id="titleInput" name="title" maxlength="300" placeholder="<?= $t('map_title_placeholder') ?>" required>

        <label for="subtitleInput"><?= $t('field_subtitle_label') ?></label>
        <input type="text" id="subtitleInput" name="subtitle" maxlength="300" placeholder="<?= $t('subtitle_placeholder') ?>">

        <label for="descInput"><?= $t('field_desc_label') ?></label>
        <textarea id="descInput" name="desc" rows="2" maxlength="300" placeholder="<?= $t('desc_optional_placeholder') ?>"></textarea>

        <label for="sourceInput"><?= $t('field_source_label') ?></label>
        <input type="text" id="sourceInput" name="source" maxlength="300" placeholder="<?= $t('source_placeholder') ?>">

        <label for="creditInput"><?= $t('field_credit_label') ?></label>
        <input type="text" id="creditInput" name="credit" maxlength="300" placeholder="<?= $t('credit_placeholder') ?>">
      </div>

      <div class="card">
        <h2><i class="fa-solid fa-2"></i> <?= $t('newproject_map_heading') ?></h2>
        <div class="hint" style="margin-bottom:var(--sp-2)"><?= $t('newproject_center_hint') ?></div>
        <div id="map"></div>
        <div class="latlon">
          <div><label for="latInput"><?= $t('newproject_lat_label') ?></label><input type="text" id="latInput" readonly></div>
          <div><label for="lonInput"><?= $t('newproject_lon_label') ?></label><input type="text" id="lonInput" readonly></div>
          <div><label for="zoomInput"><?= $t('newproject_zoom_label') ?></label><input type="text" id="zoomInput" readonly></div>
        </div>
      </div>

      <details class="card" id="modsec">
        <summary>
          <div class="modhead">
            <h2><i class="fa-solid fa-3"></i> <?= $t('feature_modules_heading') ?> <i class="fa-solid fa-chevron-down chevron"></i></h2>
            <button type="button" class="ghost" onclick="event.preventDefault();event.stopPropagation();resetModules()"><?= $t('newproject_reset_defaults_btn') ?></button>
          </div>
          <div class="hint"><?= $esc($modulesSummary) ?></div>
        </summary>
        <div id="modfields">
          <?php foreach (souliong_modules() as $mk => $minfo): $mon = souliong_module_on(null, $mk); ?>
          <label class="modrow">
            <input type="checkbox" data-mod="<?= $esc($mk) ?>" name="<?= $mk === 'personExplore' ? 'personExplore' : 'features[' . $esc($mk) . ']' ?>" <?= $mon ? 'checked' : '' ?>>
            <span><b><?= $esc($minfo['label']) ?></b><br><span class="hint"><?= $esc($minfo['desc']) ?></span></span>
          </label>
          <?php endforeach; ?>
        </div>
      </details>

      <details class="card" id="contribsec">
        <summary>
          <div class="modhead">
            <h2><i class="fa-solid fa-4"></i> <?= $t('contrib_kinds_heading') ?> <i class="fa-solid fa-chevron-down chevron"></i></h2>
            <button type="button" class="ghost" onclick="event.preventDefault();event.stopPropagation();resetContrib()"><?= $t('newproject_reset_defaults_btn') ?></button>
          </div>
          <div class="hint"><?= $t('newproject_contrib_summary_default') ?></div>
        </summary>
        <div id="contribfields">
          <div class="hint" style="margin-bottom:var(--sp-2)"><?= $t('contrib_kinds_hint') ?></div>
          <?php foreach (souliong_contrib_kinds() as $ck): ?>
          <label class="modrow">
            <input type="checkbox" data-kind="<?= $esc($ck) ?>" name="contrib_kinds[<?= $esc($ck) ?>]" <?= in_array($ck, $ccur['kinds'], true) ? 'checked' : '' ?>>
            <span><?= $esc($ckinds[$ck]['label']) ?></span>
          </label>
          <?php endforeach; ?>
          <label for="contribDefaultSel" style="margin-top:var(--sp-3)"><?= $t('contrib_default_tab_label') ?></label>
          <select id="contribDefaultSel" name="contrib_default">
            <?php foreach ($ctabs as $tb): ?>
            <option value="<?= $esc($tb) ?>" <?= $ccur['default'] === $tb ? 'selected' : '' ?>><?= $t('tab_' . $tb) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="hint" style="margin:calc(var(--sp-3) * -1) 0 var(--sp-3)"><?= $t('contrib_default_tab_hint') ?></div>
          <label for="contribNewspotSel"><?= $t('contrib_newspot_label') ?></label>
          <select id="contribNewspotSel" name="contrib_newspot">
            <?php foreach (['off', 'admin', 'contributor'] as $np): ?>
            <option value="<?= $np ?>" <?= $ccur['newPoint'] === $np ? 'selected' : '' ?>><?= $t('contrib_newspot_' . $np) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="hint"><?= $t('contrib_newspot_hint') ?></div>
        </div>
      </details>

      <div class="row" style="justify-content:space-between">
        <div class="hint no" id="formErr"></div>
        <button type="submit" id="submitBtn"><i class="fa-solid fa-plus"></i> <?= $t('newproject_submit_btn') ?></button>
      </div>
    </form>

    <div class="backlink"><a href="<?= $adminUrl ?>"><i class="fa-solid fa-arrow-left"></i> <?= $t('back_to_admin') ?></a></div>
  </div>

  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script>
    const csrf = <?= json_encode($csrf) ?>;
    const SELF_URL = <?= json_encode($selfUrl) ?>;
    const CHECK_URL = <?= json_encode($checkUrl) ?>;
    const CHECKING_TEXT = <?= json_encode($tr('newproject_id_checking')) ?>;
    const AVAILABLE_TEXT = <?= json_encode($tr('newproject_id_available_msg')) ?>;
    const TAKEN_TEXT = <?= json_encode($tr('newproject_id_taken_hint_msg')) ?>;
    const MODULE_DEFAULTS = <?= json_encode(array_map(fn($i) => (bool)($i['default'] ?? true), souliong_modules())) ?>;
    const CONTRIB_DEFAULT = <?= json_encode($ccur) ?>;

    // ── id 即時清洗＋可用性查詢 ──
    const idInput = document.getElementById('idInput');
    const idHint = document.getElementById('idHint');
    let idTimer = null;
    idInput.addEventListener('input', () => {
      clearTimeout(idTimer);
      const cleaned = idInput.value.toLowerCase().replace(/[^a-z0-9_-]/g, '');
      if (cleaned !== idInput.value) idInput.value = cleaned;
      idHint.textContent = '';
      idHint.className = 'hint';
      if (!cleaned) return;
      idTimer = setTimeout(async () => {
        idHint.textContent = CHECKING_TEXT;
        try {
          const res = await fetch(CHECK_URL + '&id=' + encodeURIComponent(cleaned));
          const data = await res.json();
          idHint.textContent = data.available ? AVAILABLE_TEXT : TAKEN_TEXT;
          idHint.classList.add(data.available ? 'ok' : 'no');
        } catch (e) {
          idHint.textContent = '';
        }
      }, 350);
    });

    // ── 地圖：單一可拖曳標記決定 center，地圖縮放決定 zoom ──
    const map = L.map('map', { center: [23.9, 120.7], zoom: 14 });
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',
      { maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors' }).addTo(map);
    const marker = L.marker(map.getCenter(), { draggable: true }).addTo(map);
    const latInput = document.getElementById('latInput'), lonInput = document.getElementById('lonInput'), zoomInput = document.getElementById('zoomInput');
    function syncFromMarker() {
      const ll = marker.getLatLng();
      latInput.value = ll.lat.toFixed(6);
      lonInput.value = ll.lng.toFixed(6);
    }
    marker.on('drag', syncFromMarker).on('dragend', syncFromMarker);
    map.on('click', e => { marker.setLatLng(e.latlng); syncFromMarker(); });
    map.on('zoomend', () => { zoomInput.value = map.getZoom(); });
    syncFromMarker();
    zoomInput.value = map.getZoom();

    // ── 收折區塊：記住「曾展開過」，還原預設不動這個旗標（見 api/newproject.php 的寫入規則說明）──
    const modsec = document.getElementById('modsec'), contribsec = document.getElementById('contribsec');
    modsec.addEventListener('toggle', () => { if (modsec.open) modsec.dataset.opened = '1'; });
    contribsec.addEventListener('toggle', () => { if (contribsec.open) contribsec.dataset.opened = '1'; });

    function resetModules() {
      document.querySelectorAll('#modfields input[data-mod]').forEach(cb => {
        cb.checked = MODULE_DEFAULTS[cb.dataset.mod];
      });
    }
    function resetContrib() {
      document.querySelectorAll('#contribfields input[data-kind]').forEach(cb => {
        cb.checked = CONTRIB_DEFAULT.kinds.includes(cb.dataset.kind);
      });
      document.getElementById('contribDefaultSel').value = CONTRIB_DEFAULT.default;
      document.getElementById('contribNewspotSel').value = CONTRIB_DEFAULT.newPoint;
    }

    // ── 送出 ──
    const form = document.getElementById('npform'), submitBtn = document.getElementById('submitBtn'), formErr = document.getElementById('formErr');
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      formErr.textContent = '';
      submitBtn.disabled = true;
      const fd = new FormData(form);
      fd.set('lat', latInput.value);
      fd.set('lon', lonInput.value);
      fd.set('zoom', zoomInput.value);
      fd.append('action', 'create');
      fd.append('csrf', csrf);
      if (modsec.dataset.opened === '1') fd.append('modules_submitted', '1');
      if (contribsec.dataset.opened === '1') fd.append('contrib_submitted', '1');
      try {
        const res = await fetch(SELF_URL, { method: 'POST', body: fd });
        const data = await res.json();
        if (!res.ok || data.error) {
          formErr.textContent = data.error || res.statusText;
          submitBtn.disabled = false;
          return;
        }
        location.href = data.redirect;
      } catch (err) {
        formErr.textContent = String(err);
        submitBtn.disabled = false;
      }
    });
  </script>
</body>

</html>
