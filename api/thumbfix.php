<?php
// 維護工具（備援）：舊投稿縮圖平常由 photo.php（&th=1）自動產生，這支只在自動產生失敗
//（主機 GD 沒編 WebP）或想一次補齊全部時使用——由管理者瀏覽器抓原圖、canvas 縮小後回存並補 thumb 欄位。
// 只補「沒有 thumb」的原始投稿，不動已有縮圖的紀錄與照片原檔。
require __DIR__ . '/store.php';
require __DIR__ . '/security.php';
require __DIR__ . '/i18n.php';
require_once __DIR__ . '/routes.php';   // 網址表：後台網址只有這一份定義（見 api/routes.php）
$cfg = require __DIR__ . '/config.php';
rate_limit($cfg, 'admin');
[$LANG, $DICT] = i18n_init();
$t  = fn(string $key, array $vars = []): string => htmlspecialchars(i18n_t($DICT, $key, $vars), ENT_QUOTES);
$tr = fn(string $key, array $vars = []): string => i18n_t($DICT, $key, $vars);
$esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// 「回後台」連結一律由 Route 產生（理由同 exiffix.php：相對網址會被路由誤判）
// 從哪個專案點進來就回哪個專案（後台的工具連結會帶 ?project=），沒帶就回全站總覽；一律回工具分頁
$backProject = preg_replace('/[^a-z0-9_-]/', '', $_GET['project'] ?? '');
$adminUrl = $esc(Route::abs(Route::manager($backProject, 'tools')));

if (!site_perm($cfg, 'fix_thumbnails')) {
    http_response_code(401);
    header('Content-Type: text/html; charset=utf-8');
    echo '<p>' . $tr('primary_login_required_msg', ['url' => $adminUrl]) . '</p>';
    exit;
}
$csrf = admin_derived($cfg);

/** 這個專案裡「有照片、還沒有縮圖」的原始投稿（編輯版本 photo 為 null，天然不在名單裡） */
function thumbfix_missing(array $cfg, array $dict, string $project): array {
    $out = [];
    foreach (store_all($cfg, $project) as $r) {
        if (($r['kind'] ?? 'photo') !== 'photo') continue;
        if (empty($r['photo']) || !empty($r['thumb'])) continue;
        $abs = photo_abs_path($cfg, (string)$r['photo']);
        if ($abs === null || !is_file($abs)) continue;   // 原檔已不存在的沒得縮，略過
        $out[] = [
            'id'    => (string)$r['id'],
            'photo' => (string)$r['photo'],
            'label' => i18n_t($dict, 'item_label_full', [
                'n'    => $r['item_num'] ?? '?',
                'name' => $r['name'] ?? i18n_t($dict, 'anon_fallback'),
                'time' => substr((string)($r['photo_time'] ?? $r['created_at'] ?? ''), 0, 16),
            ]),
        ];
    }
    return $out;
}

// ── 待補名單（POST，JSON 回應） ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'list') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        json_out(['error' => $tr('csrf_invalid_ajax_msg')], 403);
    }
    $project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
    if ($project === '' || !is_dir($cfg['projects_dir'] . '/' . $project)) {
        json_out(['error' => 'bad project'], 400);
    }
    json_out(['ok' => true, 'items' => thumbfix_missing($cfg, $DICT, $project)]);
}

// ── 存回單張縮圖（POST，JSON 回應） ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        json_out(['error' => $tr('csrf_invalid_ajax_msg')], 403);
    }
    $project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
    $id = (string)($_POST['id'] ?? '');
    if ($project === '' || $id === '' || strlen($id) > 64 || !is_dir($cfg['projects_dir'] . '/' . $project)) {
        json_out(['error' => 'bad request'], 400);
    }
    $rec = null;
    foreach (store_all($cfg, $project) as $r) {
        if ((string)($r['id'] ?? '') === $id) { $rec = $r; break; }
    }
    if (!$rec || empty($rec['photo'])) { json_out(['error' => 'not found'], 404); }
    if (!empty($rec['thumb'])) { json_out(['error' => $tr('thumbfix_already_has_thumb')], 409); }
    if (!isset($_FILES['thumb']) || $_FILES['thumb']['error'] !== UPLOAD_ERR_OK) {
        json_out(['error' => 'need thumb file'], 400);
    }
    $tf = $_FILES['thumb'];
    $tinfo = @getimagesize($tf['tmp_name']);
    $tmime = is_array($tinfo) ? ($tinfo['mime'] ?? '') : '';
    if ($tf['size'] > 1024 * 1024 || !isset($cfg['allowed_mime'][$tmime])) {
        json_out(['error' => 'bad thumb'], 415);
    }
    // 縮圖檔名沿用照片檔名加 _t（跟 upload.php 一致），放在同一個 photos 目錄
    if (!preg_match('#^([a-z0-9_-]+)/([A-Za-z0-9_.-]+)$#', (string)$rec['photo'], $m)) {
        json_out(['error' => 'bad photo path'], 500);
    }
    $tname = preg_replace('/\.[A-Za-z0-9]+$/', '', $m[2]) . '_t.' . $cfg['allowed_mime'][$tmime];
    $destAbs = project_dir($cfg, $m[1]) . '/photos/' . $tname;
    if (!move_uploaded_file($tf['tmp_name'], $destAbs)) {
        json_out(['error' => 'save failed'], 500);
    }
    $patched = store_patch($cfg, $project, $id, ['thumb' => $m[1] . '/' . $tname]);
    if (!$patched) {
        @unlink($destAbs);   // 欄位沒寫成就別留孤兒檔
        json_out(['error' => 'patch failed'], 500);
    }
    json_out(['ok' => true, 'thumb' => $m[1] . '/' . $tname]);
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
  <title><?= $t('thumbfix_title') ?></title>
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

    label {
      display: block;
      font-size: 0.8125rem;
      color: var(--muted);
      margin-bottom: var(--sp-1)
    }

    select {
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

    .hint.runhint {
      margin-top: var(--sp-3)
    }

    /* 尚未輸出訊息前不要撐開卡片 */
    .hint:empty {
      display: none
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
    <a href="<?= $esc(Route::tool('thumbfix', $backProject, ['lang' => 'zh_TW'])) ?>" class="<?= $LANG === 'zh_TW' ? 'on' : '' ?>">中文</a>
    <a href="<?= $esc(Route::tool('thumbfix', $backProject, ['lang' => 'en'])) ?>" class="<?= $LANG === 'en' ? 'on' : '' ?>">English</a>
  </div>
  <div class="wrap">
    <h1><i class="fa-solid fa-images"></i> <?= $t('thumbfix_h1') ?></h1>
    <div class="warn"><?= $t('thumbfix_warn') ?></div>

    <div class="card">
      <label for="project"><?= $t('tool_select_project_label') ?></label>
      <select id="project">
        <?php foreach ($allProjects as $p): ?><option value="<?= $esc($p) ?>" <?= $p === $reqProject ? 'selected' : '' ?>><?= $esc($p) ?></option><?php endforeach; ?>
      </select>
      <button id="go"><?= $t('thumbfix_scan_btn') ?></button>
      <p class="hint runhint"><?= $t('thumbfix_keep_open_hint') ?></p>
      <div class="hint runhint" id="status"></div>
      <ul id="results"></ul>
    </div>

    <p class="backlink"><a href="<?= $adminUrl ?>"><i class="fa-solid fa-arrow-left"></i> <?= $t("back_to_admin") ?></a></p>
  </div>

  <script>
    // 未套變數的原始字串（含 {var} 佔位符），前端用 fmt() 自行代換——因為進度／計數只有 JS 迴圈裡才知道
    const I18N = <?= json_encode([
      'scanning'     => i18n_t($DICT, 'thumbfix_scanning_msg'),
      'error_prefix' => i18n_t($DICT, 'error_prefix_label'),
      'no_items'     => i18n_t($DICT, 'thumbfix_no_items_msg'),
      'progress'     => i18n_t($DICT, 'thumbfix_progress_msg'),
      'rate_limited' => i18n_t($DICT, 'thumbfix_rate_limited_msg'),
      'item_done'    => i18n_t($DICT, 'thumbfix_item_done_reason'),
      'fail_prefix'  => i18n_t($DICT, 'thumbfix_fail_prefix'),
      'complete'     => i18n_t($DICT, 'thumbfix_complete_summary'),
      'fail_suffix'  => i18n_t($DICT, 'thumbfix_fail_suffix'),
      'conn_failed'  => i18n_t($DICT, 'connection_failed_retry_msg'),
      'orig_load_failed' => i18n_t($DICT, 'thumbfix_orig_load_failed'),
    ], JSON_UNESCAPED_UNICODE) ?>;
    const fmt = (str, vars) => str.replace(/\{(\w+)\}/g, (_, k) => (vars[k] != null ? vars[k] : ''));
    const csrf = <?= json_encode($csrf) ?>;
    // 跨端點請求一律用絕對 base：這頁可能是從 <base>/thumbfix 路徑進來的，相對 ?api= 會被路徑路由搶走
    const BASE = <?= json_encode(Route::abs(Route::base()), JSON_UNESCAPED_SLASHES) ?>;
    const THUMB_MAX = 640, THUMB_Q = 0.78;   // 跟前端上傳的縮圖規格一致
    const go = document.getElementById('go');
    const statusEl = document.getElementById('status');
    const resultsEl = document.getElementById('results');

    function addResult(label, ok, reason) {
      const li = document.createElement('li');
      const name = document.createElement('span');
      name.textContent = label;
      const state = document.createElement('span');
      state.className = ok ? 'ok' : 'no';
      state.textContent = (ok ? '✓ ' : '— ') + reason;
      li.appendChild(name);
      li.appendChild(state);
      resultsEl.appendChild(li);
    }

    // canvas.toBlob 對不支援的格式會靜默退回 PNG（又大又慢），所以驗 type 不符就改用 JPEG
    async function makeThumb(blob) {
      const bmp = await createImageBitmap(blob, { imageOrientation: 'from-image' });
      let w = bmp.width, h = bmp.height;
      if (Math.max(w, h) > THUMB_MAX) { const s = THUMB_MAX / Math.max(w, h); w = Math.round(w * s); h = Math.round(h * s); }
      const cv = document.createElement('canvas'); cv.width = w; cv.height = h;
      cv.getContext('2d').drawImage(bmp, 0, 0, w, h);
      let out = await new Promise(r => cv.toBlob(r, 'image/webp', THUMB_Q));
      if (!out || out.type !== 'image/webp') out = await new Promise(r => cv.toBlob(r, 'image/jpeg', 0.8));
      if (!out) throw new Error('encode failed');
      return out;
    }

    go.addEventListener('click', async () => {
      go.disabled = true;
      resultsEl.innerHTML = '';
      statusEl.textContent = I18N.scanning;
      const project = document.getElementById('project').value;
      try {
        const fd = new FormData();
        fd.append('action', 'list');
        fd.append('csrf', csrf);
        fd.append('project', project);
        const res = await fetch(BASE + '?api=thumbfix', { method: 'POST', body: fd });
        const j = await res.json();
        if (!res.ok || !j.ok) { statusEl.textContent = I18N.error_prefix + (j.error || res.status); go.disabled = false; return; }
        const items = j.items || [];
        if (!items.length) { statusEl.textContent = I18N.no_items; go.disabled = false; return; }
        let done = 0, fail = 0;
        for (const it of items) {
          statusEl.textContent = fmt(I18N.progress, { i: done + fail + 1, total: items.length });
          try {
            const pr = await fetch(BASE + '?api=photo&f=' + encodeURIComponent(it.photo));
            if (!pr.ok) throw new Error(fmt(I18N.orig_load_failed, { status: pr.status }));
            const thumb = await makeThumb(await pr.blob());
            const sfd = new FormData();
            sfd.append('action', 'save');
            sfd.append('csrf', csrf);
            sfd.append('project', project);
            sfd.append('id', it.id);
            sfd.append('thumb', thumb, 'thumb.webp');
            // 照片多時可能碰到限流（429）：照 Retry-After 等一下再試，不直接放棄這張
            let sr, sj;
            for (let attempt = 0; attempt < 5; attempt++) {
              sr = await fetch(BASE + '?api=thumbfix', { method: 'POST', body: sfd });
              if (sr.status !== 429) break;
              const wait = parseInt(sr.headers.get('Retry-After') || '10', 10) || 10;
              statusEl.textContent = fmt(I18N.rate_limited, { wait, i: done + fail + 1, total: items.length });
              await new Promise(r => setTimeout(r, wait * 1000));
            }
            sj = await sr.json().catch(() => ({}));
            if (!sr.ok || !sj.ok) throw new Error(sj.error || ('HTTP ' + sr.status));
            done++;
            addResult(it.label, true, I18N.item_done);
          } catch (e) {
            fail++;
            addResult(it.label, false, I18N.fail_prefix + (e.message || e));
          }
        }
        statusEl.textContent = fmt(I18N.complete, { done }) + (fail ? fmt(I18N.fail_suffix, { fail }) : '');
      } catch (e) {
        statusEl.textContent = I18N.conn_failed;
      }
      go.disabled = false;
    });
  </script>
</body>

</html>
