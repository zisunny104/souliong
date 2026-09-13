<?php
// 維護工具：修復投稿缺少相機 EXIF 資訊的情況。
// 兩種修復方式（都採欄位級補齊：現有值優先、只補缺少的欄位，整組遺失或只缺光圈/焦段等部分欄位皆可修）：
//  1) 直接修復（優先用這個）：適用於原始投稿本身仍保有完整 exif、只有其編輯版本缺 exif 的情況；
//     只要順著編輯版本的 edit_of 找回原始投稿，把 exif 複製過去即可，不需要使用者做任何事。
//  2) 上傳原始檔比對（備援）：在原始投稿本身 exif 缺漏（例如上傳當下就沒存到、或只存到部分欄位）時使用，
//     由管理者上傳當初的原始照片檔，瀏覽器端用 exifr 讀出 EXIF 後只送出時間／座標／相機欄位（不上傳整張照片、不新增投稿），
//     伺服器用「時間 + 座標」比對現有缺 exif 的投稿，找不到相符的就略過、絕不亂猜配對。
require __DIR__ . '/store.php';
require __DIR__ . '/security.php';
require __DIR__ . '/i18n.php';
require_once __DIR__ . '/routes.php';   // 網址表：後台網址只有這一份定義（見 api/routes.php）
$cfg = require __DIR__ . '/config.php';
rate_limit($cfg, 'manage');
[$LANG, $DICT] = i18n_init();
$t  = fn(string $key, array $vars = []): string => htmlspecialchars(i18n_t($DICT, $key, $vars), ENT_QUOTES);
$tr = fn(string $key, array $vars = []): string => i18n_t($DICT, $key, $vars);
$esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// 「回後台」連結一律由 Route 產生（見 api/routes.php），不寫相對網址：這支工具本身是走
// /exiffix 這個路徑進來的，相對連結會被路由誤判成同一個專案底下的動作，回不去後台。
// 從哪個專案點進來就回哪個專案（後台的工具連結會帶 ?project=），沒帶就回全站總覽，不硬塞一個專案；
// 一律回工具分頁，不是回後台第一頁的「總覽」──不然等於又是一次「跳到別的地方」。
$backProject = preg_replace('/[^a-z0-9_-]/', '', $_GET['project'] ?? '');
$adminUrl = $esc(Route::abs(Route::manager($backProject, 'tools')));

if (!site_perm($cfg, 'fix_exif')) {
    http_response_code(401);
    header('Content-Type: text/html; charset=utf-8');
    echo '<p>' . $tr('primary_login_required_msg', ['url' => $adminUrl]) . '</p>';
    exit;
}
$csrf = primary_derived($cfg);

function exiffix_dist_m(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $R = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}
function exiffix_clean_str(?string $s, int $max): ?string {
    if ($s === null) return null;
    $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s));
    if ($s === '') return null;
    if (preg_match('/^.{0,' . $max . '}/us', $s, $m)) $s = $m[0];
    return $s;
}

// ── 方式一：直接從原始投稿複製 exif（POST，JSON 回應）──
// 編輯版本（edit_of 指向原始投稿 id）自己的 exif 若是空的，而它指向的原始投稿有 exif，直接複製過去。
// 這是最主要的修復路徑：多數「編輯後相機資訊不見」的案例，資料本來就沒真的丟，只是編輯版本自己那筆沒有而已。
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'autofix') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        json_out(['error' => $tr('csrf_invalid_ajax_msg')], 403);
    }
    $project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
    if ($project === '' || !is_dir($cfg['projects_dir'] . '/' . $project)) {
        json_out(['error' => 'bad project'], 400);
    }
    $all = store_all($cfg, $project);
    $byId = [];
    foreach ($all as $r) { if (isset($r['id'])) $byId[(string)$r['id']] = $r; }

    $results = [];
    foreach ($all as $r) {
        if (($r['kind'] ?? 'photo') !== 'photo') continue;
        if (empty($r['edit_of'])) continue;      // 只處理編輯版本，原始投稿本身不需要（也不該）被這條路徑動到
        $orig = $byId[(string)$r['edit_of']] ?? null;
        if (!$orig || empty($orig['exif']) || !is_array($orig['exif'])) continue;   // 原始投稿自己也沒有 exif，這條路徑幫不上忙，留給上傳比對
        $cur = is_array($r['exif'] ?? null) ? $r['exif'] : [];
        $merged = $cur + $orig['exif'];          // 現有欄位優先、只補缺少的欄位（例如缺焦段/光圈），絕不覆蓋既有值
        if ($merged == $cur) continue;           // 沒有可補的欄位就不動
        $patched = store_patch($cfg, $project, (string)$r['id'], ['exif' => $merged]);
        $results[] = [
            'name' => i18n_t($DICT, 'item_label_short', ['n' => $r['item_num'] ?? '?', 'time' => substr((string)($r['created_at'] ?? ''), 0, 16)]),
            'ok' => (bool)$patched,
            'item_num' => $r['item_num'] ?? null,
            'reason' => $patched ? ($cur ? $tr('exiffix_filled_missing_reason') : $tr('exiffix_copied_from_orig_reason')) : $tr('exiffix_patch_failed_reason'),
        ];
    }
    json_out(['ok' => true, 'results' => $results]);
}

// ── 方式二：上傳原始檔比對並修補（POST，JSON 回應） ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'match') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        json_out(['error' => $tr('csrf_invalid_ajax_msg')], 403);
    }
    $project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
    if ($project === '' || !is_dir($cfg['projects_dir'] . '/' . $project)) {
        json_out(['error' => 'bad project'], 400);
    }
    $candidates = json_decode((string)($_POST['candidates'] ?? '[]'), true);
    if (!is_array($candidates)) $candidates = [];

    // 候選比對池：所有有拍攝時間的照片投稿。比對到之後只「補缺少的欄位」、絕不覆蓋已有值，
    // 所以 exif 完整的投稿就算被比對到也不會被動到（會回報「已完整」）；
    // 這讓 exif 整組遺失、或只缺部分欄位（如焦段/光圈）的投稿都能靠上傳原始檔補齊。
    $pool = [];
    foreach (store_all($cfg, $project) as $r) {
        if (($r['kind'] ?? 'photo') !== 'photo') continue;
        if (empty($r['photo_time'])) continue;
        $pool[] = $r;
    }

    $results = [];
    foreach ($candidates as $c) {
        $name = (string)($c['name'] ?? '');
        $t = isset($c['time']) ? strtotime((string)$c['time']) : false;
        $lat = isset($c['lat']) && is_numeric($c['lat']) ? (float)$c['lat'] : null;
        $lon = isset($c['lon']) && is_numeric($c['lon']) ? (float)$c['lon'] : null;
        if ($t === false) {
            $results[] = ['name' => $name, 'ok' => false, 'reason' => $tr('exiffix_no_time_reason')];
            continue;
        }

        $best = null;
        $bestScore = null;
        foreach ($pool as $r) {
            $rt = strtotime((string)$r['photo_time']);
            if ($rt === false) continue;
            $dt = abs($rt - $t);
            if ($dt > 120) continue;   // 時間容許 ±2 分鐘（同一次拍攝，時鐘略有誤差）
            $d = null;
            if ($lat !== null && $lon !== null && isset($r['lat'], $r['lon'])) {
                $d = exiffix_dist_m($lat, $lon, (float)$r['lat'], (float)$r['lon']);
                if ($d > 300) continue;   // 座標容許 300 公尺（投稿後可能被拖曳校正過位置）
            }
            $score = $dt + ($d !== null ? $d / 50 : 0);   // 時間為主、座標為輔的綜合分數，取最接近者
            if ($bestScore === null || $score < $bestScore) {
                $bestScore = $score;
                $best = $r;
            }
        }

        if (!$best) {
            $results[] = ['name' => $name, 'ok' => false, 'reason' => $tr('exiffix_no_match_reason')];
            continue;
        }

        $exif = [];
        foreach (['make', 'model', 'lens', 'sw'] as $k) {
            if (!empty($c[$k])) {
                $v = exiffix_clean_str((string)$c[$k], 60);
                if ($v !== null) $exif[$k] = $v;
            }
        }
        foreach (['f', 'exp', 'focal'] as $k) {
            if (isset($c[$k]) && is_numeric($c[$k])) $exif[$k] = (float)$c[$k];
        }
        if (isset($c['iso']) && is_numeric($c['iso'])) $exif['iso'] = (int)$c['iso'];
        if (!$exif) {
            $results[] = ['name' => $name, 'ok' => false, 'reason' => $tr('exiffix_no_exif_reason')];
            continue;
        }

        $cur = is_array($best['exif'] ?? null) ? $best['exif'] : [];
        $merged = $cur + $exif;   // 現有欄位優先、只補缺少的欄位，絕不覆蓋既有值
        if ($merged == $cur) {
            // 沒有可補的欄位：不動資料、也不從池中移除（讓它仍可被其他更相符的檔案比對）
            $results[] = ['name' => $name, 'ok' => false, 'item_num' => $best['item_num'] ?? null, 'reason' => $tr('exiffix_already_complete_reason', ['n' => $best['item_num'] ?? '?'])];
            continue;
        }

        // 用過的紀錄從池中移除，避免同一批裡不同照片搶配到同一筆投稿
        $pool = array_values(array_filter($pool, fn($r) => ($r['id'] ?? null) !== ($best['id'] ?? null)));

        $patched = store_patch($cfg, $project, (string)$best['id'], ['exif' => $merged]);
        $results[] = [
            'name' => $name,
            'ok' => (bool)$patched,
            'item_num' => $best['item_num'] ?? null,
            'reason' => $patched ? ($cur ? $tr('exiffix_filled_missing_reason') : $tr('exiffix_patched_reason')) : $tr('exiffix_patch_failed_reason'),
        ];
    }
    json_out(['ok' => true, 'results' => $results]);
}

// ── 頁面 ──
$allProjects = store_projects($cfg);
$reqProject = preg_replace('/[^a-z0-9_-]/', '', $_GET['project'] ?? ($allProjects[0] ?? ''));
?>
<!doctype html>
<html lang="<?= $LANG === 'en' ? 'en' : 'zh-Hant' ?>">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex">
  <title><?= $t('exiffix_title') ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <style>
    /* 間距與點擊區用 rem，跟著使用者的瀏覽器字級縮放 */
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
      /* 最小點擊區：WCAG 2.2 SC 2.5.8 下限 24px，取 1.75rem 留餘裕 */
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
      max-width: 45rem;
      margin: 0 auto
    }

    /* 原本 position:fixed 貼右上角，視窗一窄就壓在標題上；改成跟著版面走的一列 */
    .langsw {
      max-width: 45rem;
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

    /* 兩種修復方式用編號卡片區分，取代原本靠段落文字說明「方式一／方式二」 */
    .steptitle {
      display: flex;
      align-items: center;
      gap: var(--sp-2);
      font-size: 0.9375rem;
      font-weight: 700;
      margin: 0 0 var(--sp-2)
    }

    .steptitle .num {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex: none;
      width: 1.5rem;
      height: 1.5rem;
      border-radius: 999px;
      background: var(--accent);
      color: var(--accent-fg);
      font-size: 0.75rem
    }

    .steptitle+.hint {
      margin-bottom: var(--sp-3)
    }

    .hint.runhint {
      margin-top: var(--sp-3)
    }

    /* 尚未輸出訊息前不要撐開卡片 */
    .hint:empty {
      display: none
    }

    label {
      display: block;
      font-size: 0.8125rem;
      color: var(--muted);
      margin-bottom: var(--sp-1)
    }

    select,
    input[type=file] {
      width: 100%;
      border: 1px solid var(--line);
      border-radius: 0.625rem;
      background: var(--bg);
      color: var(--fg);
      padding: var(--sp-2) var(--sp-3);
      font-size: 0.875rem;
      min-height: 2.25rem;
      margin-bottom: var(--sp-3)
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

    button:disabled {
      opacity: .5;
      cursor: default
    }

    .hint {
      font-size: 0.75rem;
      color: var(--muted)
    }

    ul#results {
      list-style: none;
      margin: var(--sp-3) 0 0;
      padding: 0;
      font-size: 0.8125rem
    }

    ul#results li {
      padding: var(--sp-2) 0;
      border-top: 1px solid var(--line);
      display: flex;
      justify-content: space-between;
      gap: var(--sp-3)
    }

    .ok {
      color: #2a8a4a
    }

    .no {
      color: var(--muted)
    }

    a {
      color: var(--accent)
    }

    /* 返回後台：原本是一行純文字連結，點擊面積不足一個手指 */
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
    <a href="<?= $esc(Route::tool('exiffix', $backProject, ['lang' => 'zh_TW'])) ?>" class="<?= $LANG === 'zh_TW' ? 'on' : '' ?>">中文</a>
    <a href="<?= $esc(Route::tool('exiffix', $backProject, ['lang' => 'en'])) ?>" class="<?= $LANG === 'en' ? 'on' : '' ?>">English</a>
  </div>
  <div class="wrap">
    <h1><i class="fa-solid fa-kit-medical"></i> <?= $t('exiffix_h1') ?></h1>
    <div class="warn"><?= $t('exiffix_warn') ?></div>

    <div class="card">
      <h2 class="steptitle"><span class="num">1</span><?= $t('exiffix_method1_title') ?></h2>
      <div class="hint"><?= $t('exiffix_method1_hint') ?></div>
      <label for="project"><?= $t('tool_select_project_label') ?></label>
      <select id="project">
        <?php foreach ($allProjects as $p): ?><option value="<?= $esc($p) ?>" <?= $p === $reqProject ? 'selected' : '' ?>><?= $esc($p) ?></option><?php endforeach; ?>
      </select>
      <button id="goAuto"><?= $t('exiffix_method1_btn') ?></button>
      <div class="hint runhint" id="statusAuto"></div>
      <ul id="resultsAuto"></ul>
    </div>

    <div class="card">
      <h2 class="steptitle"><span class="num">2</span><?= $t('exiffix_method2_title') ?></h2>
      <div class="hint"><?= $t('exiffix_method2_hint') ?></div>
      <label for="files"><?= $t('exiffix_choose_files_label') ?></label>
      <input type="file" id="files" accept="image/*" multiple>
      <button id="go" disabled><?= $t('exiffix_match_btn') ?></button>
      <div class="hint runhint" id="status"></div>
      <ul id="results"></ul>
    </div>

    <p class="backlink"><a href="<?= $adminUrl ?>"><i class="fa-solid fa-arrow-left"></i> <?= $t("back_to_admin") ?></a></p>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/exifr/dist/full.umd.js"></script>
  <script>
    // 未套變數的原始字串（含 {var} 佔位符），前端用 fmt() 自行代換
    const I18N = <?= json_encode([
      'fixing'          => i18n_t($DICT, 'exiffix_fixing_msg'),
      'error_prefix'    => i18n_t($DICT, 'error_prefix_label'),
      'autofix_done'    => i18n_t($DICT, 'exiffix_autofix_done_msg'),
      'autofix_done_none' => i18n_t($DICT, 'exiffix_autofix_done_none_msg'),
      'conn_failed'     => i18n_t($DICT, 'connection_failed_retry_msg'),
      'picked_files'    => i18n_t($DICT, 'exiffix_picked_files_msg'),
      'reading_exif'    => i18n_t($DICT, 'exiffix_reading_exif_msg'),
      'matching'        => i18n_t($DICT, 'exiffix_matching_msg'),
      'match_done'      => i18n_t($DICT, 'exiffix_match_done_msg'),
      'item_num_suffix' => i18n_t($DICT, 'exiffix_item_num_suffix'),
    ], JSON_UNESCAPED_UNICODE) ?>;
    const fmt = (str, vars) => str.replace(/\{(\w+)\}/g, (_, k) => (vars[k] != null ? vars[k] : ''));
    const csrf = <?= json_encode($csrf) ?>;
    function renderResults(el, list) {
      el.innerHTML = '';
      list.forEach(r => {
        const li = document.createElement('li');
        const label = document.createElement('span');
        label.textContent = r.name;
        const state = document.createElement('span');
        state.className = r.ok ? 'ok' : 'no';
        state.textContent = r.ok ? ('✓ ' + r.reason + (r.item_num != null ? fmt(I18N.item_num_suffix, { n: r.item_num }) : '')) : ('— ' + r.reason);
        li.appendChild(label);
        li.appendChild(state);
        el.appendChild(li);
      });
    }

    const goAuto = document.getElementById('goAuto');
    const statusAutoEl = document.getElementById('statusAuto');
    const resultsAutoEl = document.getElementById('resultsAuto');
    goAuto.addEventListener('click', async () => {
      goAuto.disabled = true;
      statusAutoEl.textContent = I18N.fixing;
      resultsAutoEl.innerHTML = '';
      try {
        const fd = new FormData();
        fd.append('action', 'autofix');
        fd.append('csrf', csrf);
        fd.append('project', document.getElementById('project').value);
        const res = await fetch('?api=exiffix', { method: 'POST', body: fd });
        const j = await res.json();
        if (!res.ok || !j.ok) { statusAutoEl.textContent = I18N.error_prefix + (j.error || res.status); goAuto.disabled = false; return; }
        statusAutoEl.textContent = j.results.length ? fmt(I18N.autofix_done, { n: j.results.length }) : I18N.autofix_done_none;
        renderResults(resultsAutoEl, j.results);
      } catch (e) {
        statusAutoEl.textContent = I18N.conn_failed;
      }
      goAuto.disabled = false;
    });

    const filesInput = document.getElementById('files');
    const go = document.getElementById('go');
    const statusEl = document.getElementById('status');
    const resultsEl = document.getElementById('results');
    let picked = [];

    filesInput.addEventListener('change', () => {
      picked = Array.from(filesInput.files || []);
      go.disabled = picked.length === 0;
      statusEl.textContent = picked.length ? fmt(I18N.picked_files, { n: picked.length }) : '';
    });

    go.addEventListener('click', async () => {
      go.disabled = true;
      resultsEl.innerHTML = '';
      statusEl.textContent = I18N.reading_exif;
      const candidates = [];
      for (const file of picked) {
        try {
          const m = await exifr.parse(file, ['DateTimeOriginal', 'CreateDate', 'Make', 'Model', 'LensModel', 'FNumber', 'ExposureTime', 'ISO', 'FocalLength', 'Software']);
          const g = await exifr.gps(file).catch(() => null);
          const time = m && (m.DateTimeOriginal || m.CreateDate);
          candidates.push({
            name: file.name,
            time: time ? new Date(time).toISOString() : null,
            lat: g && typeof g.latitude === 'number' ? g.latitude : null,
            lon: g && typeof g.longitude === 'number' ? g.longitude : null,
            make: m && m.Make || null,
            model: m && m.Model || null,
            lens: m && m.LensModel || null,
            f: m && m.FNumber || null,
            exp: m && m.ExposureTime || null,
            iso: m && m.ISO || null,
            focal: m && m.FocalLength || null,
            sw: m && m.Software || null,
          });
        } catch (e) {
          candidates.push({ name: file.name, time: null });
        }
      }
      statusEl.textContent = I18N.matching;
      try {
        const fd = new FormData();
        fd.append('action', 'match');
        fd.append('csrf', csrf);
        fd.append('project', document.getElementById('project').value);
        fd.append('candidates', JSON.stringify(candidates));
        const res = await fetch('?api=exiffix', { method: 'POST', body: fd });
        const j = await res.json();
        if (!res.ok || !j.ok) { statusEl.textContent = I18N.error_prefix + (j.error || res.status); go.disabled = false; return; }
        statusEl.textContent = fmt(I18N.match_done, { n: j.results.length });
        renderResults(resultsEl, j.results);
      } catch (e) {
        statusEl.textContent = I18N.conn_failed;
      }
      go.disabled = false;
    });
  </script>
</body>

</html>
