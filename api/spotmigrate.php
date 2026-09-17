<?php
// 後台工具（可重複執行、冪等）：手動觸發 spotmigrate_run()（見 api/spotlib.php），把單一
// 專案的靜態點位底稿併入 spots.jsonl，顯示遷移報告供人肉眼核對。pages/view.php 另外會在開頁
// 時自動偵測、就地補跑同一套邏輯（見該檔 spotmigrate_needed() 呼叫處），這支頁面是給想主動
// 檢查、或需要看報告細節時用的手動入口。
require __DIR__ . '/spotlib.php';
require __DIR__ . '/security.php';
require __DIR__ . '/i18n.php';
require_once __DIR__ . '/routes.php';   // 網址表：後台網址只有這一份定義（見 api/routes.php）
$cfg = require __DIR__ . '/config.php';
rate_limit($cfg, 'manage');
[$LANG, $DICT] = i18n_init();
$t  = fn(string $key, array $vars = []): string => htmlspecialchars(i18n_t($DICT, $key, $vars), ENT_QUOTES);
$tr = fn(string $key, array $vars = []): string => i18n_t($DICT, $key, $vars);
$esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$backProject = preg_replace('/[^a-z0-9_-]/', '', $_GET['project'] ?? '');
$adminUrl = $esc(Route::abs(Route::manager($backProject, 'tools')));

if (!site_perm($cfg, 'migrate_spots')) {
    http_response_code(401);
    header('Content-Type: text/html; charset=utf-8');
    echo '<p>' . $tr('primary_login_required_msg', ['url' => $adminUrl]) . '</p>';
    exit;
}
$csrf = primary_derived($cfg);

// ── 單一專案執行遷移（POST，JSON 回應）──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'migrate') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        json_out(['error' => $tr('csrf_invalid_ajax_msg')], 403);
    }
    $project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
    if ($project === '' || !is_dir($cfg['projects_dir'] . '/' . $project)) {
        json_out(['error' => 'bad project'], 400);
    }
    try {
        $result = spotmigrate_run($cfg, $project);
        json_out(['ok' => true] + $result);
    } catch (Throwable $e) {
        error_log('souliong spotmigrate: ' . $e->getMessage());
        json_out(['error' => 'server'] + (!empty($cfg['debug']) ? ['detail' => $e->getMessage()] : []), 500);
    }
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
  <title><?= $t('spotmigrate_title') ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
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
      max-width: 45rem;
      margin: 0 auto
    }

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
    select:focus-visible {
      outline: 2px solid var(--accent);
      outline-offset: 2px
    }
  </style>
</head>

<body>
  <div class="langsw">
    <a href="<?= $esc(Route::tool('spotmigrate', $backProject, ['lang' => 'zh_TW'])) ?>" class="<?= $LANG === 'zh_TW' ? 'on' : '' ?>">中文</a>
    <a href="<?= $esc(Route::tool('spotmigrate', $backProject, ['lang' => 'en'])) ?>" class="<?= $LANG === 'en' ? 'on' : '' ?>">English</a>
  </div>
  <div class="wrap">
    <h1><i class="fa-solid fa-chair"></i> <?= $t('spotmigrate_h1') ?></h1>
    <div class="warn"><?= $t('spotmigrate_warn') ?></div>

    <div class="card">
      <label for="project"><?= $t('tool_select_project_label') ?></label>
      <select id="project">
        <?php foreach ($allProjects as $p): ?><option value="<?= $esc($p) ?>" <?= $p === $reqProject ? 'selected' : '' ?>><?= $esc($p) ?></option><?php endforeach; ?>
      </select>
      <button id="go"><?= $t('spotmigrate_run_btn') ?></button>
      <div class="hint runhint" id="status"></div>
      <ul id="results"></ul>
    </div>

    <p class="backlink"><a href="<?= $adminUrl ?>"><i class="fa-solid fa-arrow-left"></i> <?= $t("back_to_admin") ?></a></p>
  </div>

  <script>
    const I18N = <?= json_encode([
      'running'        => i18n_t($DICT, 'spotmigrate_running_msg'),
      'error_prefix'   => i18n_t($DICT, 'error_prefix_label'),
      'conn_failed'    => i18n_t($DICT, 'connection_failed_retry_msg'),
      'skipped_msg'    => i18n_t($DICT, 'spotmigrate_skipped_msg'),
      'done_msg'       => i18n_t($DICT, 'spotmigrate_done_msg'),
      'added_line'     => i18n_t($DICT, 'spotmigrate_added_line'),
      'repointed_line' => i18n_t($DICT, 'spotmigrate_repointed_line'),
      'backup_line'    => i18n_t($DICT, 'spotmigrate_backup_line'),
    ], JSON_UNESCAPED_UNICODE) ?>;
    const fmt = (str, vars) => str.replace(/\{(\w+)\}/g, (_, k) => (vars[k] != null ? vars[k] : ''));
    const csrf = <?= json_encode($csrf) ?>;

    const go = document.getElementById('go');
    const statusEl = document.getElementById('status');
    const resultsEl = document.getElementById('results');

    function renderResults(list, cls, lineTpl, labelKey) {
      list.forEach(r => {
        const li = document.createElement('li');
        const label = document.createElement('span');
        label.textContent = r.label || r.item_num;
        const state = document.createElement('span');
        state.className = cls;
        state.textContent = fmt(lineTpl, { num: r.num, item_num: r.item_num, id: r.id });
        li.appendChild(label);
        li.appendChild(state);
        resultsEl.appendChild(li);
      });
    }

    go.addEventListener('click', async () => {
      go.disabled = true;
      statusEl.textContent = I18N.running;
      resultsEl.innerHTML = '';
      try {
        const fd = new FormData();
        fd.append('action', 'migrate');
        fd.append('csrf', csrf);
        fd.append('project', document.getElementById('project').value);
        const res = await fetch('?api=spotmigrate', { method: 'POST', body: fd });
        const j = await res.json();
        if (!res.ok || !j.ok) { statusEl.textContent = I18N.error_prefix + (j.error || res.status); go.disabled = false; return; }
        if (j.skipped) {
          statusEl.textContent = fmt(I18N.skipped_msg, { file: j.static_file });
        } else {
          statusEl.textContent = fmt(I18N.done_msg, { added: j.added.length, repointed: j.repointed.length });
          if (j.backup) {
            const li = document.createElement('li');
            const label = document.createElement('span');
            label.textContent = I18N.backup_line;
            const val = document.createElement('span');
            val.className = 'ok';
            val.textContent = j.backup;
            li.appendChild(label);
            li.appendChild(val);
            resultsEl.appendChild(li);
          }
          renderResults(j.added, 'ok', I18N.added_line);
          renderResults(j.repointed, 'ok', I18N.repointed_line);
        }
      } catch (e) {
        statusEl.textContent = I18N.conn_failed;
      }
      go.disabled = false;
    });
  </script>
</body>

</html>
