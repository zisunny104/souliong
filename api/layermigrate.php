<?php
// 維護工具（常駐，可重複使用，不是一次性腳本）：把「meta.json 沒有 layers 欄位、跟隨
// config.php default_layers」的專案，明確凍結成目前解析出來的圖層 id——見 api/layers.php
// souliong_layers_for() 的說明：沒有欄位是即時解析，之後改 default_layers 會立刻波及所有
// 這樣的專案。用在「以後要換全站預設底圖」之前，先把舊專案的圖層釘住、不受那次改動影響。
// 已經自己設定過 layers 的專案一律略過、絕不覆寫——不管內容是什麼，那都是已經做過的明確選擇。
require __DIR__ . '/store.php';
require __DIR__ . '/security.php';
require __DIR__ . '/i18n.php';
require __DIR__ . '/layers.php';
require_once __DIR__ . '/routes.php';   // 網址表：後台網址只有這一份定義（見 api/routes.php）
$cfg = require __DIR__ . '/config.php';
rate_limit($cfg, 'manage');
[$LANG, $DICT] = i18n_init();
$t  = fn(string $key, array $vars = []): string => htmlspecialchars(i18n_t($DICT, $key, $vars), ENT_QUOTES);
$tr = fn(string $key, array $vars = []): string => i18n_t($DICT, $key, $vars);
$esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// 「回後台」連結一律由 Route 產生（理由同 thumbfix.php：相對網址會被路由誤判）
$backProject = preg_replace('/[^a-z0-9_-]/', '', $_GET['project'] ?? '');
$adminUrl = $esc(Route::abs(Route::manager($backProject, 'tools')));

if (!site_perm($cfg, 'manage_layers')) {
    http_response_code(401);
    header('Content-Type: text/html; charset=utf-8');
    echo '<p>' . $tr('primary_login_required_msg', ['url' => $adminUrl]) . '</p>';
    exit;
}
$csrf = primary_derived($cfg);

/** 這個專案目前的圖層狀態：跟 souliong_layers_for() 用同一套「有沒有非空 layers 欄位」判斷式，
 *  不能自己另外土法重猜一次，否則這裡顯示的跟頁面實際生效的圖層可能各講各的。 */
function layermigrate_status(array $cfg, string $proj): array
{
    $mf = $cfg['projects_dir'] . '/' . $proj . '/meta.json';
    $meta = is_file($mf) ? json_decode((string)@file_get_contents($mf), true) : null;
    $explicit = is_array($meta) && isset($meta['layers']) && is_array($meta['layers']) && $meta['layers'];
    return [
        'explicit' => $explicit,
        'ids' => $explicit ? $meta['layers'] : souliong_default_layers($cfg),
    ];
}

/** id 陣列轉成給人看的「標籤（id）」清單；找不到 manifest 時就只顯示 id 本身（例如圖層已被刪除）。 */
function layermigrate_labels(array $cfg, string $proj, array $ids): string
{
    $all = souliong_layer_list($cfg, $proj);
    return implode('、', array_map(
        fn($id) => isset($all[$id]) ? ($all[$id]['label'] ?? $id) . '（' . $id . '）' : (string)$id . '？',
        $ids
    ));
}

/** 把目前的 default_layers 明確寫進這個專案的 meta.json；只在還沒有 layers 欄位時生效。
 *  回傳 true＝真的寫了，false＝略過（已經凍結過，或專案不存在）。 */
function layermigrate_freeze(array $cfg, string $proj): bool
{
    $dir = $cfg['projects_dir'] . '/' . $proj;
    if (!is_dir($dir)) return false;
    $mf = $dir . '/meta.json';
    $meta = is_file($mf) ? json_decode((string)@file_get_contents($mf), true) : [];
    if (!is_array($meta)) $meta = [];
    if (isset($meta['layers']) && is_array($meta['layers']) && $meta['layers']) return false;   // 已凍結，略過
    $meta['layers'] = souliong_default_layers($cfg);
    $json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || !is_dir(dirname($mf))) return false;
    file_put_contents($mf, $json, LOCK_EX);
    return true;
}

// ── 單一專案套用 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'freeze') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit($tr('csrf_invalid_ajax_msg'));
    }
    $p = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
    $done = $p !== '' && layermigrate_freeze($cfg, $p);
    header('Location: ' . Route::tool('layermigrate', $backProject, ['done' => $done ? 'freeze_ok' : 'freeze_skip', 'p' => $p]));
    exit;
}

// ── 批次套用：對所有還沒凍結的專案跑同一套邏輯，回傳處理了幾個 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'freeze_all') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit($tr('csrf_invalid_ajax_msg'));
    }
    $n = 0;
    foreach (store_projects($cfg) as $p) {
        if (layermigrate_freeze($cfg, $p)) $n++;
    }
    header('Location: ' . Route::tool('layermigrate', $backProject, ['done' => 'freeze_all_ok', 'n' => (string)$n]));
    exit;
}

// ── 頁面 ──
$allProjects = store_projects($cfg);
$rows = array_map(fn($p) => ['id' => $p] + layermigrate_status($cfg, $p), $allProjects);
$pendingCount = count(array_filter($rows, fn($r) => !$r['explicit']));

$done = (string)($_GET['done'] ?? '');
$flash = null;
if ($done === 'freeze_ok') {
    $flash = ['ok', $tr('layermigrate_done_freeze_ok', ['p' => (string)($_GET['p'] ?? '')])];
} elseif ($done === 'freeze_skip') {
    $flash = ['no', $tr('layermigrate_done_freeze_skip', ['p' => (string)($_GET['p'] ?? '')])];
} elseif ($done === 'freeze_all_ok') {
    $flash = ['ok', $tr('layermigrate_done_freeze_all_ok', ['n' => (string)($_GET['n'] ?? '0')])];
}
?>
<!doctype html>
<html lang="<?= $LANG === 'en' ? 'en' : 'zh-Hant' ?>">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex">
  <title><?= $t('layermigrate_title') ?></title>
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

    .flash {
      border-radius: var(--sp-3);
      padding: var(--sp-3) var(--sp-4);
      font-size: 0.8125rem;
      margin-bottom: var(--sp-4);
      border: 1px solid var(--line);
      background: var(--card)
    }

    .flash.ok {
      border-color: #2a8a4a;
      color: #2a8a4a
    }

    .card {
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: 0.875rem;
      padding: var(--sp-4) var(--sp-5);
      margin-bottom: var(--sp-4)
    }

    button, .btn {
      border: none;
      background: var(--accent);
      color: var(--accent-fg);
      border-radius: 0.625rem;
      padding: var(--sp-2) var(--sp-4);
      min-height: 2.25rem;
      font-size: 0.8125rem;
      font-weight: 700;
      cursor: pointer
    }

    button.ghost {
      background: transparent;
      color: var(--accent);
      border: 1px solid var(--accent)
    }

    .hint {
      font-size: 0.75rem;
      color: var(--muted)
    }

    ul#rows {
      list-style: none;
      margin: var(--sp-3) 0 0;
      padding: 0;
      font-size: 0.8125rem
    }

    ul#rows li {
      padding: var(--sp-3) 0;
      border-top: 1px solid var(--line);
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: var(--sp-3);
      flex-wrap: wrap
    }

    .pname {
      font-weight: 700
    }

    .pstatus {
      display: block;
      color: var(--muted);
      font-size: 0.75rem;
      margin-top: 2px
    }

    .pstatus.pending {
      color: var(--accent)
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
    button:focus-visible {
      outline: 2px solid var(--accent);
      outline-offset: 2px
    }
  </style>
</head>

<body>
  <div class="langsw">
    <a href="<?= $esc(Route::tool('layermigrate', $backProject, ['lang' => 'zh_TW'])) ?>" class="<?= $LANG === 'zh_TW' ? 'on' : '' ?>">中文</a>
    <a href="<?= $esc(Route::tool('layermigrate', $backProject, ['lang' => 'en'])) ?>" class="<?= $LANG === 'en' ? 'on' : '' ?>">English</a>
  </div>
  <div class="wrap">
    <h1><i class="fa-solid fa-layer-group"></i> <?= $t('layermigrate_h1') ?></h1>
    <div class="warn"><?= $t('layermigrate_warn') ?></div>
    <?php if ($flash): ?>
    <div class="flash <?= $flash[0] ?>"><?= $esc($flash[1]) ?></div>
    <?php endif; ?>

    <div class="card">
      <?php if ($pendingCount > 0): ?>
      <form method="post" onsubmit="return confirm(<?= json_encode($tr('layermigrate_freeze_all_confirm', ['n' => (string)$pendingCount]), JSON_UNESCAPED_UNICODE) ?>)">
        <input type="hidden" name="csrf" value="<?= $esc($csrf) ?>">
        <input type="hidden" name="action" value="freeze_all">
        <button type="submit"><i class="fa-solid fa-snowflake"></i> <?= $t('layermigrate_freeze_all_btn') ?> (<?= $pendingCount ?>)</button>
      </form>
      <p class="hint" style="margin-top:var(--sp-2)"><?= $t('layermigrate_freeze_all_hint') ?></p>
      <?php else: ?>
      <p class="hint"><?= $t('layermigrate_all_frozen_msg') ?></p>
      <?php endif; ?>

      <ul id="rows">
        <?php foreach ($rows as $r): ?>
        <li>
          <span>
            <span class="pname"><?= $esc($r['id']) ?></span>
            <span class="pstatus <?= $r['explicit'] ? '' : 'pending' ?>">
              <?= $esc($r['explicit']
                  ? $tr('layermigrate_status_explicit', ['ids' => layermigrate_labels($cfg, $r['id'], $r['ids'])])
                  : $tr('layermigrate_status_default', ['ids' => layermigrate_labels($cfg, $r['id'], $r['ids'])])) ?>
            </span>
          </span>
          <?php if (!$r['explicit']): ?>
          <form method="post" style="margin:0">
            <input type="hidden" name="csrf" value="<?= $esc($csrf) ?>">
            <input type="hidden" name="action" value="freeze">
            <input type="hidden" name="project" value="<?= $esc($r['id']) ?>">
            <button type="submit" class="ghost"><i class="fa-solid fa-snowflake"></i> <?= $t('layermigrate_freeze_btn') ?></button>
          </form>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
        <li class="hint"><?= $t('layermigrate_no_projects_msg') ?></li>
        <?php endif; ?>
      </ul>
    </div>

    <p class="backlink"><a href="<?= $adminUrl ?>"><i class="fa-solid fa-arrow-left"></i> <?= $t("back_to_admin") ?></a></p>
  </div>
</body>

</html>
