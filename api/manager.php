<?php
// 管理頁：<base>/manager（httpOnly cookie 認證；PIN 走 POST。主 PIN 全域、各專案 PIN 僅該專案）
// 網址一律用 api/routes.php 的 Route:: 組出來，本檔不自己黏字串——形狀改一次就好，也不會有漏改的角落。
require __DIR__ . '/store.php';
require __DIR__ . '/security.php';
require __DIR__ . '/stats.php';
require __DIR__ . '/features.php';
require_once __DIR__ . '/packs.php';
require_once __DIR__ . '/layers.php';     // 地圖圖層註冊表（底圖／疊圖），形狀同 packs.php
require_once __DIR__ . '/regions3d.php';  // 3D 自訂模型區域註冊表，形狀同上，見 api/region3d.php
require_once __DIR__ . '/coverlib.php';   // 封面／地圖快照的存檔邏輯，與 api/cover.php 共用
require_once __DIR__ . '/routes.php';    // 網址表：後台網址只有這一份定義，不在各處黏字串
require_once __DIR__ . '/settings.php';   // packs.php 內部也會載它，兩邊都用 require_once 才不會重複宣告
require __DIR__ . '/../pages/error.php';
require_once __DIR__ . '/i18n.php';
$cfg = require __DIR__ . '/config.php';
rate_limit($cfg, 'manage');
[$LANG, $DICT] = i18n_init();
$t  = fn(string $key, array $vars = []): string => htmlspecialchars(i18n_t($DICT, $key, $vars), ENT_QUOTES);
$tr = fn(string $key, array $vars = []): string => i18n_t($DICT, $key, $vars);   // 內容含固定 HTML 標籤（非使用者輸入），此頁自行保證安全，不做二次跳脫
$esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
// 專案／主題包／圖層 id 只收 [a-z0-9_-]，其餘字元一律砍掉；GET／POST 各處取這些 id 都走這裡
function clean_id($raw): string
{
  return (string)preg_replace('/[^a-z0-9_-]/', '', (string)$raw);
}

// ── 登入（POST pin[, project]）→ 種 cookie ──
$loginErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
  $pin = (string)($_POST['pin'] ?? '');
  $proj = clean_id($_POST['project'] ?? '');
  $userid = trim((string)($_POST['userid'] ?? ''));
  $pw = (string)($_POST['pw'] ?? '');
  $ok = false;
  $go = Route::manager();
  $label = '';
  if ($userid !== '') {
    // 帳號登入（userid+密碼）：與 PIN 互斥的另一種憑證，欄位有填 userid 就走這條，不落入下面的 PIN 分支
    $r = account_login($cfg, $userid, $pw);
    if ($r['ok']) {
      $acc = $r['account'];
      account_set_cookie($cfg, (string)$acc['id']);
      $ok = true;
      $label = (string)($acc['label'] !== '' ? $acc['label'] : $acc['userid']);
      // 全站身分（主帳號／主 PIN）本來就能連到任何專案，不該因為順便有全站權限
      // 就把人送去主站總覽——登入表單帶著來源專案（見 panel-login 的 hidden input），
      // 來源在某個專案就回那裡。
      if (($acc['role'] ?? '') === 'primary') {
        if ($proj !== '' && is_dir($cfg['projects_dir'] . '/' . $proj)) $go = Route::manager($proj);
      } else {
        $aprojects = account_project_list($cfg, (string)$acc['id']);
        if ($proj !== '' && in_array($proj, $aprojects, true)) $go = Route::manager($proj);
        elseif (count($aprojects) === 1) $go = Route::manager($aprojects[0]);
      }
    } else {
      $loginErrMsg = i18n_t($DICT, $r['error'] === 'locked' ? 'account_locked_msg' : 'account_login_failed_msg');
    }
  } elseif (check_primary_pin($cfg, $pin)) {
    primary_set_cookie($cfg);
    $ok = true;
    $label = primary_pin_label($cfg, $pin);
    if ($proj !== '' && is_dir($cfg['projects_dir'] . '/' . $proj)) $go = Route::manager($proj);
  } elseif ($proj !== '' && ($ppMatch = project_pin_match($cfg, $proj, $pin)) !== null) {
    if (pins_check_and_bump($cfg, $proj, (string)$ppMatch['id'])) {
      pin_set_cookie($cfg, $proj, (string)$ppMatch['id']);
      $ok = true;
      $go = Route::manager($proj);
      $label = project_pin_label($cfg, $proj, $pin);
    } else {
      $loginErrMsg = i18n_t($DICT, 'share_link_expired');
    }
  }
  if (isset($_POST['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    if ($ok) echo json_encode(['ok' => true, 'label' => $label]);
    else {
      http_response_code(403);
      echo json_encode(['error' => $loginErrMsg ?? i18n_t($DICT, 'admin_pin_incorrect')]);
    }
    exit;
  }
  if ($ok) {
    header('Location: ' . $go);
    exit;
  }
  $loginErr = $loginErrMsg ?? i18n_t($DICT, 'admin_pin_incorrect');
}
if (isset($_GET['logout'])) {
  primary_clear_cookie();
  account_clear_cookie();
  header('Location: ' . Route::manager());
  exit;
}

// 帳號系統的錯誤代碼 → 顯示訊息；跟登入頁一樣走純表單送出（不需要 JS/fetch），未對應到的代碼一律退回通用訊息
$accountErrMsg = function (string $err) use ($DICT): string {
  $map = [
    'userid_invalid' => 'account_err_userid_invalid', 'userid_taken' => 'account_err_userid_taken',
    'pw_short' => 'account_err_pw_short', 'pw_same_as_userid' => 'account_err_pw_same_as_userid', 'pw_weak' => 'account_err_pw_weak',
    'invalid_token' => 'account_err_invalid_token', 'legacy_pin_wrong' => 'account_err_legacy_pin_wrong',
  ];
  return i18n_t($DICT, $map[$err] ?? 'account_err_generic');
};

// ── 帳號自助註冊（POST userid, pw[, label]）→ 僅 registration_open 開啟時允許；新帳號預設無任何專案權限 ──
$registerErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'account_register') {
  if (!souliong_registration_open($cfg)) {
    $registerErr = i18n_t($DICT, 'registration_closed_msg');
  } else {
    $res = account_register($cfg, (string)($_POST['userid'] ?? ''), (string)($_POST['pw'] ?? ''), (string)($_POST['label'] ?? ''));
    if ($res['ok']) {
      account_set_cookie($cfg, (string)$res['account']['id']);
      header('Location: ' . Route::manager());
      exit;
    }
    $registerErr = $accountErrMsg((string)$res['error']);
  }
}

// ── 舊 PIN 轉換為帳號（POST token, legacy_pin, userid, pw）→ 核對舊 PIN 證明本人後建立新帳號 ──
// 公開端點（未登入者用，token 只透過網址 fragment 帶出），比照 grant_redeem：不做 CSRF，rate_limit($cfg,'manage') 已節流。
$activateErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'account_activate') {
  $res = account_migrate_activate(
    $cfg,
    (string)($_POST['token'] ?? ''),
    (string)($_POST['legacy_pin'] ?? ''),
    (string)($_POST['userid'] ?? ''),
    (string)($_POST['pw'] ?? '')
  );
  if ($res['ok']) {
    account_set_cookie($cfg, (string)$res['account']['id']);
    header('Location: ' . Route::manager());
    exit;
  }
  $activateErr = $accountErrMsg((string)$res['error']);
}

// ── 管理 PIN 邀請兌換（POST project, token, pin[, label]）→ 收件人自己設 PIN／暱稱，成功即種 cookie ──
// 公開端點（未登入者用），刻意不做 CSRF 檢查：跟 action=login 同一層級，呼叫端本來就沒有已登入頁面可嵌 token；
// 最多只是幫別人兌換一個權限全關的身分，rate_limit($cfg,'manage')（見本檔開頭）已足以節流亂猜。
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'grant_redeem') {
  header('Content-Type: application/json; charset=utf-8');
  $rProj = clean_id($_POST['project'] ?? '');
  $rToken = (string)($_POST['token'] ?? '');
  $rPin = (string)($_POST['pin'] ?? '');
  $rLabel = isset($_POST['label']) ? (string)$_POST['label'] : null;
  if ($rProj === '' || !is_dir($cfg['projects_dir'] . '/' . $rProj)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid']);
    exit;
  }
  $res = pins_redeem($cfg, $rProj, $rToken, $rPin, $rLabel);
  if ($res['ok']) {
    pin_set_cookie($cfg, $rProj, (string)$res['id']);
  } else {
    http_response_code(403);
  }
  echo json_encode($res);
  exit;
}

// ── 認證與範圍 ──
$reqProject = clean_id($_GET['project'] ?? '');
// 目前停在哪個分頁。網址上寫得出來才能加書籤／分享，所以 pane 是路徑的一段而不是 fragment；
// 拆解在 Route::parseManager()，這裡只認 Route::PANES 之內的值。
$reqPane = in_array((string)($_GET['pane'] ?? ''), Route::PANES, true) ? (string)$_GET['pane'] : '';

// 舊網址（?api=admin、/admin、/<mapid>/manager|edit）一律導向正規形式，讓書籤與貼出去的連結
// 收斂成同一種寫法。只導 GET：POST 帶著表單內容，302 會把 body 丟掉；下載類請求也不導，
// 那不是「頁面」，導了只會讓瀏覽器多跑一趟。
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['backup'])) {
    $canonical = Route::manager($reqProject, $reqPane);
    if (rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)) !== rawurldecode($canonical)) {
        $keep = $_GET;
        unset($keep['api'], $keep['project'], $keep['pane']);
        header('Location: ' . $canonical . ($keep ? '?' . http_build_query($keep) : ''), true, 302);
        exit;
    }
}
$primary = Auth::actor($cfg, null)->kind() === 'primary';
// 帳號登入者可能同時管理多個專案，不像 PIN 綁死單一 $reqProject：$acctProjects 是他有權限的專案清單，
// 沒有 ?project= 時（例如剛登入、或多專案帳號查看總覽）也要能通過 $authed。
$isAcct = !$primary && Auth::actor($cfg, null)->kind() === 'account';
$acctProjects = $isAcct ? array_values(array_filter(store_projects($cfg), fn($tp) => Auth::actor($cfg, $tp)->isMember($tp))) : [];
$authed = $primary
  || ($reqProject !== '' && Auth::actor($cfg, $reqProject)->isMember($reqProject))
  || ($isAcct && $acctProjects !== []);
if (!$authed) {
  http_response_code(401);
  header('Content-Type: text/html; charset=utf-8');
?>
  <!DOCTYPE html>
  <html lang="<?= $LANG === 'en' ? 'en' : 'zh-Hant' ?>">

  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $t('admin_login_title') ?></title>
    <style>
      :root {
        color-scheme: light dark;
        --bg: #111113;
        --fg: #f1f1f3;
        --muted: #9c9ca3;
        --line: #2b2b2f;
        --card: #1c1c1f;
        --accent: #f1f1f3;
        --accent-fg: #151517;
        --r-md: 0.75rem;
        --sp-1: 0.25rem;
        --sp-2: 0.5rem;
        --sp-3: 0.75rem;
        --sp-4: 1rem;
        --sp-5: 1.5rem;
        /* 最小點擊區：WCAG 2.2 SC 2.5.8 下限 24px，取 1.75rem 留餘裕 */
        --tap: 1.75rem
      }

      @media(prefers-color-scheme:light) {
        :root {
          --bg: #f6f6f7;
          --fg: #1b1b1d;
          --muted: #6b6b70;
          --line: #e7e7ea;
          --card: #fff;
          --accent: #1b1b1d;
          --accent-fg: #fff
        }
      }

      * {
        box-sizing: border-box
      }

      body {
        margin: 0;
        min-height: 100vh;
        display: grid;
        place-items: center;
        padding: var(--sp-5) var(--sp-4);
        background: var(--bg);
        color: var(--fg);
        font-family: system-ui, sans-serif;
        line-height: 1.6
      }

      .langsw {
        position: fixed;
        top: var(--sp-2);
        right: var(--sp-2);
        z-index: 3;
        display: flex;
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

      form {
        background: var(--card);
        border: 1px solid var(--line);
        border-radius: 1.25rem;
        padding: 1.75rem;
        width: min(20rem, 90vw);
        box-shadow: 0 12px 40px rgba(0, 0, 0, .3);
        text-align: center
      }

      h1 {
        font-size: 1.125rem;
        line-height: 1.4;
        margin: 0 0 var(--sp-1)
      }

      .s {
        font-size: 0.75rem;
        color: var(--muted);
        margin-bottom: var(--sp-5)
      }

      input {
        width: 100%;
        text-align: center;
        letter-spacing: 0.25rem;
        font-size: 1.25rem;
        padding: var(--sp-3);
        border: 1px solid var(--line);
        border-radius: var(--r-md);
        background: var(--bg);
        color: var(--fg);
        margin-bottom: var(--sp-3)
      }

      button {
        width: 100%;
        border: none;
        border-radius: var(--r-md);
        background: var(--accent);
        color: var(--accent-fg);
        font-size: 0.9375rem;
        font-weight: 700;
        padding: var(--sp-3);
        cursor: pointer
      }

      .err {
        color: #ff6b6b;
        font-size: 0.8125rem;
        margin-bottom: var(--sp-2);
        min-height: 1.125rem
      }

      .pin-toggle-wrap {
        position: relative;
        display: block;
        margin-bottom: var(--sp-3)
      }

      .pin-toggle-wrap input {
        margin-bottom: 0;
        padding-right: 2.5rem
      }

      .pin-toggle-btn {
        position: absolute;
        right: var(--sp-1);
        top: 50%;
        transform: translateY(-50%);
        width: var(--tap);
        height: var(--tap);
        border: none;
        background: transparent;
        color: var(--muted);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: var(--sp-2)
      }

      .pin-toggle-btn:hover {
        background: var(--line)
      }

      .pin-mask-overlay {
        position: absolute;
        inset: 0;
        right: 2.5rem;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5625rem;
        overflow: hidden;
        pointer-events: none;
        color: var(--fg)
      }

      .pin-mask-dot.pop {
        animation: pinpop .2s ease
      }

      /* 還沒填的格子（見 pin-input.js 的 data-pin-slots），理由同 popups.css 那份 */
      .pin-mask-dot.pin-blank {
        opacity: .5
      }

      .pin-mask-dot.pin-blank.pin-next {
        opacity: .85
      }

      @keyframes pinpop {
        0% {
          transform: scale(1.6)
        }

        100% {
          transform: scale(1)
        }
      }

      .pin-keypad {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: var(--sp-1);
        margin-top: var(--sp-2)
      }

      .pin-key {
        height: 2.75rem;
        border: 1px solid var(--line);
        border-radius: var(--sp-2);
        background: var(--bg);
        color: var(--fg);
        font-size: 1.0625rem;
        font-weight: 600;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center
      }

      .pin-key:hover {
        background: var(--line)
      }

      .pin-key:active {
        transform: translateY(1px)
      }

      .pin-key.del {
        color: var(--muted)
      }

      .pin-key.ok {
        background: var(--accent);
        color: var(--accent-fg);
        border-color: var(--accent)
      }

      .textfield {
        text-align: left;
        letter-spacing: normal;
        font-size: 0.9375rem
      }

      .switchlink {
        margin-top: var(--sp-3);
        font-size: 0.8125rem
      }

      /* 連結也要有 24px 以上的可點面積，不能只有一行字的高度 */
      .switchlink a {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: var(--tap);
        padding: 0 var(--sp-2);
        border-radius: var(--sp-2);
        color: var(--muted);
        cursor: pointer;
        text-decoration: underline
      }

      .switchlink a:hover {
        color: var(--fg)
      }

      a:focus-visible,
      button:focus-visible,
      input:focus-visible {
        outline: 2px solid var(--accent);
        outline-offset: 2px
      }
    </style>
  </head>

  <body>
    <div class="langsw">
      <a href="?<?= $reqProject !== '' ? 'project=' . rawurlencode($reqProject) . '&' : '' ?>lang=zh_TW" class="<?= $LANG === 'zh_TW' ? 'on' : '' ?>">中文</a>
      <a href="?<?= $reqProject !== '' ? 'project=' . rawurlencode($reqProject) . '&' : '' ?>lang=en" class="<?= $LANG === 'en' ? 'on' : '' ?>">English</a>
    </div>

    <?php
      // 失敗重新整理後要保留原本在看的面板（否則 register/activate 送出失敗會被彈回登入面板、錯誤訊息看起來像消失了）
      $postAction = (string)($_POST['action'] ?? '');
      $initPanel = in_array($postAction, ['account_register', 'account_activate'], true) ? $postAction : 'login';
    ?>
    <form method="post" id="panel-login" style="display:<?= $initPanel === 'login' ? '' : 'none' ?>">
      <input type="hidden" name="action" value="login"><input type="hidden" name="project" value="<?= $esc($reqProject) ?>">
      <h1><?= $t('app_title') ?></h1>
      <div class="s"><?= $reqProject !== '' ? $t('project_scope_label', ['project' => $reqProject]) : $t('primary_scope_label') ?><?= $t('enter_admin_pin') ?></div>
      <div class="err"><?= $esc($loginErr) ?></div>
      <div id="loginPinFields">
        <input name="pin" type="password" autocomplete="off" autofocus placeholder="PIN" data-pin-toggle data-pin-slots="4" data-pin-keypad>
      </div>
      <div id="loginAcctFields" style="display:none">
        <input name="userid" type="text" class="textfield" autocomplete="username" placeholder="<?= $t('userid_placeholder') ?>">
        <input name="pw" type="password" class="textfield" autocomplete="current-password" placeholder="<?= $t('password_placeholder') ?>">
      </div>
      <button><?= $t('login_btn') ?></button>
      <div class="switchlink" id="toAcctWrap"><a id="toAcctLogin"><?= $t('login_with_account_link') ?></a></div>
      <div class="switchlink" id="toPinWrap" style="display:none"><a id="toPinLogin"><?= $t('login_with_pin_link') ?></a></div>
      <?php if (souliong_registration_open($cfg)): ?>
        <div class="switchlink"><a id="toRegister"><?= $t('register_link') ?></a></div>
      <?php endif; ?>
    </form>

    <form method="post" id="panel-register" style="display:<?= $initPanel === 'account_register' ? '' : 'none' ?>">
      <input type="hidden" name="action" value="account_register">
      <h1><?= $t('register_title') ?></h1>
      <div class="s"><?= $t('register_hint') ?></div>
      <div class="err"><?= $esc($registerErr) ?></div>
      <input name="userid" type="text" class="textfield" autocomplete="username" placeholder="<?= $t('userid_placeholder') ?>">
      <input name="pw" type="password" class="textfield" autocomplete="new-password" placeholder="<?= $t('password_placeholder') ?>">
      <input name="label" type="text" class="textfield" autocomplete="nickname" placeholder="<?= $t('nickname_optional_placeholder') ?>">
      <button><?= $t('register_btn') ?></button>
      <div class="switchlink"><a id="backToLoginFromRegister"><?= $t('back_to_login_link') ?></a></div>
    </form>

    <form method="post" id="panel-activate" style="display:<?= $initPanel === 'account_activate' ? '' : 'none' ?>">
      <input type="hidden" name="action" value="account_activate"><input type="hidden" name="token" id="activateToken" value="<?= $postAction === 'account_activate' ? $esc($_POST['token'] ?? '') : '' ?>">
      <h1><?= $t('activate_title') ?></h1>
      <div class="s"><?= $t('activate_hint') ?></div>
      <div class="err"><?= $esc($activateErr) ?></div>
      <input name="legacy_pin" type="password" autocomplete="off" placeholder="<?= $t('legacy_pin_placeholder') ?>" data-pin-toggle>
      <input name="userid" type="text" class="textfield" autocomplete="username" placeholder="<?= $t('userid_placeholder') ?>">
      <input name="pw" type="password" class="textfield" autocomplete="new-password" placeholder="<?= $t('password_placeholder') ?>">
      <button><?= $t('activate_btn') ?></button>
    </form>

    <script>window.I18N = <?= json_encode($DICT, JSON_UNESCAPED_UNICODE) ?>; window.LANG = <?= json_encode($LANG) ?>;</script>
    <script><?php readfile(__DIR__ . '/../assets/js/pin-input.js'); ?></script>
    <script>
      (function () {
        function showPanel(id) {
          ['panel-login', 'panel-register', 'panel-activate'].forEach(function (p) {
            document.getElementById(p).style.display = (p === id) ? '' : 'none';
          });
        }
        var toAcct = document.getElementById('toAcctLogin');
        var toPin = document.getElementById('toPinLogin');
        if (toAcct) toAcct.addEventListener('click', function (e) {
          e.preventDefault();
          document.getElementById('loginPinFields').style.display = 'none';
          document.getElementById('loginAcctFields').style.display = '';
          document.getElementById('toAcctWrap').style.display = 'none';
          document.getElementById('toPinWrap').style.display = '';
          document.querySelector('#loginAcctFields input[name=userid]').focus();
        });
        if (toPin) toPin.addEventListener('click', function (e) {
          e.preventDefault();
          document.getElementById('loginAcctFields').style.display = 'none';
          document.getElementById('loginPinFields').style.display = '';
          document.getElementById('toPinWrap').style.display = 'none';
          document.getElementById('toAcctWrap').style.display = '';
          document.querySelector('#loginPinFields input[name=pin]').focus();
        });
        var toReg = document.getElementById('toRegister');
        if (toReg) toReg.addEventListener('click', function (e) { e.preventDefault(); showPanel('panel-register'); });
        var backReg = document.getElementById('backToLoginFromRegister');
        if (backReg) backReg.addEventListener('click', function (e) { e.preventDefault(); showPanel('panel-login'); });

        // 舊 PIN 轉帳號的一次性連結：token 只透過 fragment 帶出，讀出後立刻用 history.replaceState 清掉，
        // 避免重新整理或分享網址時外流／重複使用（比照管理PIN邀請連結兌換的既有作法）。
        var raw = location.hash.startsWith('#') ? location.hash.slice(1) : location.hash;
        var hp = new URLSearchParams(raw);
        var actToken = hp.get('activate');
        if (actToken) {
          hp.delete('activate');
          var rest = hp.toString();
          try { history.replaceState(null, '', location.pathname + location.search + (rest ? '#' + rest : '')); } catch (e) {}
          document.getElementById('activateToken').value = actToken;
          showPanel('panel-activate');
        }
      })();
    </script>
  </body>

  </html><?php
          exit;
        }

        // 範圍：主 PIN → 可全部（?project= 選填，空字串＝全部）；專案 PIN → $reqProject 恆為登入時鎖定的那個專案
        $scopeProject = $reqProject;
        $reqScope = $reqProject !== '' ? $reqProject : null;
        $csrfActor = Auth::actor($cfg, $reqScope);
        $csrf = (string)($csrfActor->csrf($reqScope) ?? Auth::actor($cfg, null)->csrf(null) ?? '');
        $esc_csrf = $esc($csrf);

        // 表單動作的唯一關卡：權限鍵與 CSRF 一次驗完（Auth::require），失敗以頁面式錯誤結束。
        // 專案層級鍵一定要帶專案；全站鍵傳 null。
        $gate = function (?string $project, string $key, ?string $denyKey = null, ?string $back = null) use ($cfg, $t, $scopeProject): Actor {
          $project = ($project === '' || $project === null) ? null : $project;
          $fail = function (string $reason, string $msg) use ($t, $scopeProject, $back): void {
            $to = $back ?? Route::manager($scopeProject);
            if ($reason === 'csrf') {
              error_page(403, $t('csrf_expired_title'), $t('csrf_expired_msg'), $to, $t('back_to_admin'));
            }
            error_page(403, $t('no_permission_title'), $msg, $to, $t('back_to_admin'));
          };
          $msg = $t($denyKey ?? 'no_project_permission_msg');
          if ($project === null && (auth_registry()[$key]['scope'] ?? 'project') === 'project') {
            $fail('deny', $msg);
          }
          return Auth::require($cfg, $project, $key, true, $msg, $fail);
        };
        // 圖層／主題包：全站層（$lp 為空）歸 manage_layers，專案層歸該專案的 edit_layers
        $gateLayers = fn(string $lp, string $denyKey, string $back): Actor
          => $gate($lp === '' ? null : $lp, $lp === '' ? 'manage_layers' : 'edit_layers', $denyKey, $back);

        // 顯示用：$canProject 只判斷是否為該專案成員，不作為動作門檻；動作一律走 $gate。
        $canProject = fn($p) => Auth::actor($cfg, $p)->isMember($p);
        $canMeta    = fn($p) => Auth::can($cfg, $p, 'edit_meta');
        $canLayers  = fn($p) => Auth::can($cfg, $p, 'edit_layers');
        $canContrib = fn($p) => Auth::can($cfg, $p, 'manage_contrib');
        $canBackup  = fn($p) => Auth::can($cfg, $p, 'export_backup');

        // 目前是否為「所有專案」總覽頁：全站專屬功能（工具分頁、主要管理 PIN）只在這裡顯示與生效
        $sitewideOnly = $primary && $scopeProject === '';

        // 全站專屬操作的表單守門，供下方 action=settings／storagerecalc／import 共用
        function need_sitewide_primary(string $msgKey): void
        {
          global $sitewideOnly, $t;
          if (!$sitewideOnly) {
            error_page(403, $t('no_permission_title'), $t($msgKey), Route::manager('', 'tools'), $t('back_to_admin'));
          }
        }

        // 稽核紀錄用的操作者識別：primary／帳號 id／PIN id 三選一，供事後追查憑證外洩時歸責
        $auditWho = fn() => $csrfActor->kind() === 'anon' ? Auth::actor($cfg, null)->audit() : $csrfActor->audit();

        // 專案清單（供備份/檢視）
        $allProjects = store_projects($cfg);

        // 分享連結／邀請碼要給人複製貼上，所以組成絕對網址（Route::abs）；站內導覽用 Route 的相對路徑就好。
        // 掛載根目錄怎麼還原見 Route::base()——後台可能是從 /manager/<mapid>/tools 這種深路徑進來的。
        $mapUrl = fn($p) => Route::abs(Route::map($p));

        // ── 動作 ──
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
          $p = clean_id($_POST['project'] ?? '');
          $gate($p, 'delete_others');
          $id = (string)($_POST['id'] ?? '');
          // 刪別人投稿預設僅限主 PIN；專案管理者只有在被授權 delete_others、且動的是自己已登入的專案時才可以。
          // 刪點位本身（kind:'spot'）另外還要有 edit_spots——delete_others 管的是別人的投稿，不等於能動點位識別記錄。
          $rec = ($p !== '' && $id !== '') ? store_find($cfg, $p, $id) : null;
          $canDelete = !$rec || ($rec['kind'] ?? '') !== 'spot' || Auth::can($cfg, $p, 'edit_spots');
          if ($p !== '' && $id !== '' && $canDelete) {
            $removed = store_delete($cfg, $p, $id);
            if ($removed) audit_log($cfg, $auditWho(), 'delete_others', $p, $id);
            store_purge_files($cfg, $removed);   // 照片與影音的主檔＋縮圖一起清（見 store.php）
          }
          header('Location: ' . Route::manager($scopeProject, 'records'));
          exit;
        }
        // 編輯專案描述（只改標題/副標/說明/資料來源，其餘欄位保留；免手改 meta.json）
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'meta') {
          $p = clean_id($_POST['project'] ?? '');
          $gate($p, 'edit_meta', 'no_project_permission_msg', Route::manager($scopeProject, 'access'));
          $mf = $cfg['projects_dir'] . '/' . $p . '/meta.json';
          $meta = is_file($mf) ? json_decode((string)@file_get_contents($mf), true) : [];
          if (!is_array($meta)) $meta = [];
          foreach (['title', 'subtitle', 'desc', 'source', 'credit'] as $k) {
            $raw = trim((string)($_POST[$k] ?? ''));
            $v = preg_replace('/^(.{0,300}).*$/su', '$1', $raw);   // UTF-8 安全截斷
            if ($v === null) {
              continue;
            }                          // 非法 UTF-8 → 略過此欄，不動舊值
            if ($v === '') {
              unset($meta[$k]);
            } else {
              $meta[$k] = $v;
            }
          }
          // 點位編號顯示：三選一，非白名單值一律退回預設（名稱後面）
          if (isset($_POST['numbering'])) {
            $nb = (string)$_POST['numbering'];
            $meta['numbering'] = in_array($nb, ['prefix', 'disable'], true) ? $nb : 'suffix';
          }
          // 地圖標記樣式：圓點裡要放編號、留空、固定幾何圖形，還是自訂圖片；非白名單值退回預設（顯示編號）。
          // 「圖片」這個狀態只能透過下面的圖片上傳表單切換成立，這裡收到 image 但其實沒有檔案就不採信，
          // 避免手動改表單值造成沒有圖可顯示的空狀態。
          if (isset($_POST['pinMark'])) {
            $pm = (string)$_POST['pinMark'];
            if ($pm === 'image' && cover_file_of(project_dir($cfg, $p) . '/pinmark') === null) {
              $pm = 'number';
            }
            $meta['pinMark'] = in_array($pm, ['blank', 'shape', 'image'], true) ? $pm : 'number';
            // 標記尺寸／外框：跟 pinMark 同一個表單區塊一起送出，借用上面的 isset($_POST['pinMark']) 判斷這區有沒有送出——
            // pinBorder 是 checkbox，沒勾就不會出現在 $_POST，所以「沒出現」＝關閉（跟功能開關那組 checkbox 同邏輯）
            $psz = (string)($_POST['pinSize'] ?? '');
            $meta['pinSize'] = in_array($psz, ['sm', 'lg'], true) ? $psz : 'md';
            $meta['pinBorder'] = isset($_POST['pinBorder']);
          }
          // 功能模組開關：checkbox 沒勾就不會出現在 $_POST，所以「沒出現」＝關閉（非「保留原值」）
          if (isset($_POST['features']) || isset($_POST['modules_submitted'])) {
            $features = is_array($_POST['features'] ?? null) ? $_POST['features'] : [];
            foreach (souliong_modules() as $mk => $info) {
              if ($mk === 'personExplore') {
                continue;
              }
              $meta['features'][$mk] = isset($features[$mk]);
            }
            $meta['personExplore'] = isset($_POST['personExplore']);
          }
          // 投稿設定（meta.json 的 contrib 區塊）。同樣是 checkbox，沒勾就不會出現在 $_POST，
          // 所以靠 hidden 旗標分辨「這次有送出這一區」與「這張表單根本沒有這一區」。
          if (isset($_POST['contrib_submitted'])) {
            $want = is_array($_POST['contrib_kinds'] ?? null) ? array_keys($_POST['contrib_kinds']) : [];
            // 依註冊表順序過濾，順便擋掉表單送來的任何非法 key（spot 不在 souliong_contrib_kinds() 裡）
            $kinds = array_values(array_intersect(souliong_contrib_kinds(), $want));
            if (!$kinds) $kinds = ['photo'];   // 一種都不留＝這張地圖不能投稿，那是「上傳投稿」模組的職責，不是這裡
            // 用合併而非整包覆寫，避免日後這裡再加簽表單沒涵蓋到的 contrib 子欄位時被整包洗掉
            $meta['contrib'] = array_merge($meta['contrib'] ?? [], [
              'kinds' => $kinds,
              'default' => (string)($_POST['contrib_default'] ?? ''),
              'newPoint' => (string)($_POST['contrib_newspot'] ?? 'off'),
            ]);
            // 存檔前先讓 souliong_contrib_cfg() 收斂一次：預設分頁若不在啟用型別的分頁裡會被換掉、
            // 權限值不在白名單裡會退回 off。寫進 meta.json 的就是前端實際拿到的東西，不留對不上的設定。
            $ccfg = souliong_contrib_cfg($meta);
            $meta['contrib']['default'] = $ccfg['default'];
            $meta['contrib']['newPoint'] = $ccfg['newPoint'];
          }
          // 主題包：只接受目前實際存在的包 id，避免存進一個已刪除／偽造的值。三態——
          //   ''      ＝跟隨全站預設 → 移除欄位（沒有欄位就是「沒指定」，見 packs.php）
          //   '!none' ＝這張地圖明確不套用 → 寫空字串，全站設了包也不跟
          //   包 id   ＝指定這一包
          // 用 '!none' 當標記是因為包 id 必須符合 ^[a-z0-9_-]+$，驚嘆號不可能是真的資料夾名稱，不會撞號。
          if (isset($_POST['pack'])) {
            $pk = (string)$_POST['pack'];
            if ($pk === '') {
              unset($meta['pack']);
            } elseif ($pk === '!none') {
              $meta['pack'] = '';
            } elseif (isset(souliong_pack_list($cfg, $p)[$pk])) {
              $meta['pack'] = $pk;
            }
          }
          // 地圖圖層：跟 pack 一樣只收目前實際存在的 id，差別是它是有序陣列。表單由上而下＝
          // 由頂層到底層（跟所有繪圖軟體的圖層面板一致），meta.json 存的是相反方向（由下往上
          // 疊，見 layers.php），所以這裡要反轉一次。
          // 兩態：沒有 layers 欄位＝跟隨 config 的 default_layers；有欄位＝這張地圖自己指定。
          // 全部取消勾選存成「移除欄位」而不是空陣列——空陣列在 souliong_layers_for() 裡本來
          // 就等同沒指定，寫下去只會讓人以為自己關掉了所有圖層，實際上照樣拿到預設底圖。
          if (isset($_POST['layers_submitted'])) {
            $avail = souliong_layer_list($cfg, $p);
            $picked = [];
            foreach ((array)($_POST['layers'] ?? []) as $lid) {
              $lid = (string)$lid;
              if (isset($avail[$lid]) && !in_array($lid, $picked, true)) $picked[] = $lid;
            }
            if ($picked) {
              $meta['layers'] = array_reverse($picked);
            } else {
              unset($meta['layers']);
            }
          }
          $json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
          if ($json !== false && is_dir(dirname($mf))) {
            @file_put_contents($mf, $json, LOCK_EX);
          }   // 編碼失敗絕不覆寫，避免清空 meta
          header('Location: ' . Route::manager($scopeProject !== '' ? $p : '', 'access'));
          exit;
        }
        // 平台全域設定（跨地圖，非單一專案）
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'settings') {
          $gate(null, 'manage_site', 'primary_only_settings_msg', Route::manager($scopeProject, 'tools'));
          need_sitewide_primary('primary_only_settings_msg');
          $s = souliong_settings_load($cfg);
          $s['random_explore'] = isset($_POST['random_explore']);
          $s['registration_open'] = isset($_POST['registration_open']);
          // 全站預設主題包：同樣只收實際存在的包 id，其餘（含「無」）一律存成空字串
          $sp = (string)($_POST['site_pack'] ?? '');
          $s['pack'] = isset(souliong_pack_list($cfg)[$sp]) ? $sp : '';
          souliong_settings_save($cfg, $s);
          header('Location: ' . Route::manager($scopeProject, 'tools'));
          exit;
        }
        // 全站儲存空間快取重新計算（手動觸發，算的是全站總量）
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'storagerecalc') {
          $gate(null, 'manage_site', 'primary_only_settings_msg', Route::manager($scopeProject, 'tools'));
          need_sitewide_primary('primary_only_settings_msg');
          $data = souliong_storage_compute($cfg);
          @file_put_contents(souliong_storage_cache_path($cfg), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
          audit_log($cfg, $auditWho(), 'storage_recalc', null, '');
          header('Location: ' . Route::manager($scopeProject, 'tools'));
          exit;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['addpin', 'delpin', 'setperm'], true)) {
          $gate(null, 'manage_site', 'primary_only_pin_perm_msg', Route::manager($scopeProject, 'access'));   // 權限管理限主 PIN
          $scope = ($_POST['scope'] ?? '') === 'primary' ? 'primary' : 'project';
          $tp = clean_id($_POST['project'] ?? '');
          $d = pins_load($cfg);
          if (($_POST['action']) === 'addpin') {
            $np = trim((string)($_POST['pin_new'] ?? ''));
            $label = substr(trim((string)($_POST['label'] ?? '')), 0, 80);
            if ($np !== '') {
              $entry = ['pin_hash' => pin_hash_of($cfg, $np), 'label' => $label, 'id' => bin2hex(random_bytes(4))];
              if ($scope === 'primary') {
                $d['primary'][] = $entry;
              } elseif ($tp !== '') {
                $entry['perms'] = pin_default_perms();   // 新專案 PIN 一律從全關始，之後在下方一覽表逐項開啟
                $d['projects'][$tp] = $d['projects'][$tp] ?? [];
                $d['projects'][$tp][] = $entry;
              }
            }
          } elseif (($_POST['action']) === 'setperm') {
            // 主 PIN 個別開關某把專案 PIN 的權限旗標（下放權限，不下放身分）
            $permKey = (string)($_POST['perm'] ?? '');
            $pinId = (string)($_POST['pin_id'] ?? '');
            $on = ($_POST['on'] ?? '') === '1';
            if ($tp !== '' && $pinId !== '' && array_key_exists($permKey, pin_default_perms())) {
              // foreach(...??[] as &$e) 是對暫存值取參照，不會寫回 $d：先確保鍵存在再用真正的變數取參照
              $d['projects'][$tp] = $d['projects'][$tp] ?? [];
              foreach ($d['projects'][$tp] as &$e) {
                if ((string)($e['id'] ?? '') === $pinId) { $e['perms'][$permKey] = $on; break; }
              }
              unset($e);
            }
          } else {
            $delId = (string)($_POST['pin_id'] ?? '');
            $filter = fn($list) => array_values(array_filter($list, fn($e) => (string)($e['id'] ?? '') !== $delId));
            if ($scope === 'primary') {
              $d['primary'] = $filter($d['primary']);
            } elseif ($tp !== '') {
              $d['projects'][$tp] = $filter($d['projects'][$tp] ?? []);
            }
          }
          pins_save($cfg, $d);
          header('Location: ' . Route::manager($scope === 'project' ? $tp : '', 'access'));
          exit;
        }
        // 建立「分享編輯連結」：投稿PIN（私人／僅限匿名）任何該專案管理者皆可建立；管理PIN 僅限主 PIN 或已被授權 grant_access 者。
        // 秘密（token／PIN）只透過網址 fragment（# 後面）帶出，伺服器與瀏覽器紀錄都不會留下──前端讀取後即用 history.replaceState 清除。
        // 特意不用 Location 導頁：導頁只能靠 query string 帶祕密回來，反而會落地在網址列/伺服器紀錄，所以留在同一次回應內顯示一次。
        $justCreatedShare = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sharelink') {
          $p = clean_id($_POST['project'] ?? '');
          $kindIn = ($_POST['kind'] ?? '') === 'admin' ? 'grant' : ($_POST['kind'] ?? '');   // admin：改名前的舊表單值，相容
          $kind = in_array($kindIn, ['code', 'grant'], true) ? $kindIn : 'code';
          // code（投稿代碼連結）歸投稿名單管理權限；grant（管理PIN邀請連結）維持只看 grant_access，
          // 兩者各自獨立判斷，不疊加——建立投稿代碼連結不該額外要求授權他人的能力，反之亦然。
          if ($kind === 'grant') {
            $gate($p, 'grant_access', 'admin_pin_share_permission_msg', Route::manager($p, 'access'));
          } else {
            $gate($p, 'manage_contrib', 'no_project_permission_msg', Route::manager($scopeProject, 'access'));
          }
          $label = substr(trim((string)($_POST['label'] ?? '')), 0, 80);
          $expiresRaw = trim((string)($_POST['expires_at'] ?? ''));
          $expiresAt = null;
          if ($expiresRaw !== '') {
            $ts = strtotime($expiresRaw);
            if ($ts !== false) $expiresAt = gmdate('c', $ts);
          }
          $maxUsesRaw = trim((string)($_POST['max_uses'] ?? ''));
          $maxUses = ($maxUsesRaw !== '' && is_numeric($maxUsesRaw)) ? max(1, (int)$maxUsesRaw) : null;
          if ($kind === 'code') {
            $pinInput = trim((string)($_POST['pin_new'] ?? ''));
            $grantedCode = codes_grant_create($cfg, $p, $pinInput, $label, $expiresAt, $maxUses);
            $justCreatedShare = ['project' => $p, 'kind' => 'code', 'url' => $mapUrl($p) . '?code=' . $grantedCode, 'code' => $grantedCode];
          } else {
            [$grantedToken] = pins_invite_create($cfg, $p, $expiresAt, $maxUses);
            $justCreatedShare = ['project' => $p, 'kind' => 'grant', 'url' => $mapUrl($p) . '#redeem=' . rawurlencode($grantedToken) . '&rmode=grant'];
          }
        }
        // 建立「舊 PIN → 帳號」轉換連結：primary（或已被授權 grant_access 的專案管理者，僅限 project 來源）
        // 產生一次性 token；收件人須先在啟用頁面重新輸入舊 PIN 證明本人，才能設定新的 userid/密碼。
        // 秘密（token）比照管理PIN邀請連結，只透過網址 fragment 帶出，不落地在 query string／伺服器紀錄。
        $justCreatedMigrate = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'migrate_create') {
          $mSource = in_array(($_POST['source'] ?? ''), ['bootstrap', 'primary', 'project'], true) ? $_POST['source'] : '';
          $mProject = $mSource === 'project' ? clean_id($_POST['project'] ?? '') : null;
          $mLegacyId = ($_POST['legacy_id'] ?? '') !== '' ? (string)$_POST['legacy_id'] : null;
          $mLabel = substr(trim((string)($_POST['label'] ?? '')), 0, 80);
          $migBack = Route::manager($scopeProject, 'access');
          if ($mSource === '') {
            error_page(403, $t('no_permission_title'), $t('primary_only_pin_perm_msg'), $migBack, $t('back_to_admin'));
          }
          // primary／bootstrap 兩種來源（全域身分）僅限主 PIN 本人操作
          if ($mSource === 'project') $gate($mProject, 'grant_access', 'primary_only_pin_perm_msg', $migBack);
          else $gate(null, 'manage_site', 'primary_only_pin_perm_msg', $migBack);
          $pending = account_migrate_create($cfg, $mSource, $mProject, $mLegacyId, $mLabel !== '' ? $mLabel : 'user', $mLabel);
          audit_log($cfg, $auditWho(), 'migrate_create', $mProject, $mSource . ':' . ($mLegacyId ?? ''));
          $justCreatedMigrate = ['source' => $mSource, 'project' => $mProject, 'kind' => 'migrate', 'url' => Route::abs(Route::manager('', '', 'activate=' . rawurlencode($pending['token']))), 'note' => $t('migrate_link_hint')];
        }
        // 撤銷管理PIN邀請連結（尚未兌換）：跟建立邀請同一權限門檻——主 PIN 或已被授權 grant_access 的專案 PIN 皆可
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delinvite') {
          $p = clean_id($_POST['project'] ?? '');
          $gate($p, 'grant_access', 'admin_pin_share_permission_msg', Route::manager($scopeProject, 'access'));
          $iid = (string)($_POST['invite_id'] ?? '');
          if ($iid !== '') {
            $d = pins_load($cfg);
            $d['projects'][$p] = array_values(array_filter($d['projects'][$p] ?? [], fn($e) => !(($e['kind'] ?? '') === 'invite' && (string)($e['id'] ?? '') === $iid)));
            pins_save($cfg, $d);
          }
          header('Location: ' . Route::manager($scopeProject, 'access'));
          exit;
        }
        // 帳號型專案管理者：直接以既有帳號的 userid 指派／調整權限／移除。跟 PIN 權限管理同門檻——僅限主 PIN。
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['addacctperm', 'delacctperm', 'setacctperm'], true)) {
          $gate(null, 'manage_site', 'primary_only_pin_perm_msg', Route::manager($scopeProject, 'access'));
          $tp = clean_id($_POST['project'] ?? '');
          if ($tp !== '') {
            if (($_POST['action']) === 'addacctperm') {
              $uid = account_userid_normalize((string)($_POST['userid'] ?? ''));
              $acc = $uid !== '' ? account_find_by_userid($cfg, $uid) : null;
              if ($acc !== null && project_account_perms($cfg, $tp, (string)$acc['id']) === null) {
                project_account_set($cfg, $tp, (string)$acc['id'], _account_default_perms());
                audit_log($cfg, $auditWho(), 'acctperm_add', $tp, (string)$acc['id']);
              }
            } elseif (($_POST['action']) === 'setacctperm') {
              $permKey = (string)($_POST['perm'] ?? '');
              $accountId = (string)($_POST['account_id'] ?? '');
              $on = ($_POST['on'] ?? '') === '1';
              if ($accountId !== '' && array_key_exists($permKey, _account_default_perms())) {
                $d = project_perms_load($cfg, $tp);
                foreach ($d['members'] as &$m) {
                  if ((string)($m['account_id'] ?? '') === $accountId) { $m['perms'][$permKey] = $on; break; }
                }
                unset($m);
                project_perms_save($cfg, $tp, $d);
              }
            } else {
              $accountId = (string)($_POST['account_id'] ?? '');
              if ($accountId !== '') {
                project_account_remove($cfg, $tp, $accountId);
                audit_log($cfg, $auditWho(), 'acctperm_remove', $tp, $accountId);
              }
            }
          }
          header('Location: ' . Route::manager($tp, 'access'));
          exit;
        }
        // 移除附加投稿代碼（立即失效；常駐碼另走 rotate）
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delcode') {
          $p = clean_id($_POST['project'] ?? '');
          $gate($p, 'manage_contrib', null, Route::manager($scopeProject, 'access'));
          $dc = preg_replace('/\D/', '', (string)($_POST['code_del'] ?? ''));
          if ($dc !== '') {
            codes_save($cfg, $p, array_values(array_filter(codes_load($cfg, $p), fn($e) => (string)($e['code'] ?? '') !== $dc)));
          }
          header('Location: ' . Route::manager($scopeProject, 'access'));
          exit;
        }
        // 移除投稿身分（含分享連結建立的、使用者自行設定的皆可）
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delcontrib') {
          $p = clean_id($_POST['project'] ?? '');
          $gate($p, 'manage_contrib', null, Route::manager($scopeProject, 'access'));
          $cid = (string)($_POST['contrib_id'] ?? '');
          if ($cid !== '') {
            $cd = contrib_load($cfg, $p);
            if (isset($cd[$cid])) { unset($cd[$cid]); contrib_save($cfg, $p, $cd); }
          }
          header('Location: ' . Route::manager($scopeProject, 'access'));
          exit;
        }
        // 鎖定／解除鎖定某個投稿身分（PIN 投稿者用 contrib_id、匿名裝置用 owner_hash）：只擋日後投稿，不影響已投稿內容
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['blockid', 'unblockid'], true)) {
          $p = clean_id($_POST['project'] ?? '');
          $gate($p, 'manage_contrib', null, Route::manager($scopeProject, 'access'));
          $kind = ($_POST['kind'] ?? '') === 'owner' ? 'owner' : 'contrib';
          $key = (string)($_POST['key'] ?? '');
          if ($key !== '') {
            if (($_POST['action']) === 'blockid') block_add($cfg, $p, $kind === 'owner' ? $key : null, $kind === 'contrib' ? $key : null);
            else block_remove($cfg, $p, $kind === 'owner' ? $key : null, $kind === 'contrib' ? $key : null);
          }
          header('Location: ' . Route::manager($scopeProject, 'access'));
          exit;
        }
        // 刪除某身分的全部投稿：跟單筆刪除同一權限規則（動別人的東西預設限主 PIN，或已授權 delete_others 的專案 PIN）
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delbyid') {
          $p = clean_id($_POST['project'] ?? '');
          $gate($p, 'delete_others');
          $field = ($_POST['kind'] ?? '') === 'owner' ? 'owner_hash' : 'contrib_id';
          $key = (string)($_POST['key'] ?? '');
          if ($key !== '') {
            // 沒有 edit_spots 就略過這批裡的 spot 記錄（點位本身），留著不刪、其餘照常整批刪除
            $excludeKinds = Auth::can($cfg, $p, 'edit_spots') ? [] : ['spot'];
            $removedList = store_delete_by($cfg, $p, $field, $key, $excludeKinds);
            foreach ($removedList as $removed) {
              store_purge_files($cfg, $removed);
            }
            if ($removedList) audit_log($cfg, $auditWho(), 'delete_by_' . $field, $p, $key . ' (' . count($removedList) . ')');
          }
          header('Location: ' . Route::manager($scopeProject, 'access'));
          exit;
        }
        // ── 備份（ZIP，含照片；純 PHP zip，零擴充依賴） ──
        if (isset($_GET['backup'])) {
          require_once __DIR__ . '/zip.php';
          // ── 主題包匯出：單一 pack 資料夾打包，路徑內含 <id>/ 前綴（比照 projects/<id>/ 的做法） ──
          if ($_GET['backup'] === 'pack') {
            $pid = clean_id($_GET['pack'] ?? '');
            $pp  = clean_id($_GET['project'] ?? '');
            $packs = souliong_pack_list($cfg, $pp);
            if ($pid === '' || !isset($packs[$pid])) {
              error_page(404, $t('error_404_title'), $t('pack_not_found_msg'), Route::manager('', 'tools'), $t('back_to_admin'));
            }
            // 全站包歸主要管理者，專案包歸該專案的管理者——權限跟著包實際住在哪裡走
            $isProj = ($packs[$pid]['scope'] ?? '') === 'project';
            if ($isProj ? !$canLayers($pp) : !Auth::can($cfg, null, 'manage_layers')) {
              error_page(403, $t('no_permission_title'), $t('primary_only_packs_msg'), Route::manager('', 'tools'), $t('back_to_admin'));
            }
            $pdir = souliong_pack_dir($cfg, $pid, $pp);
            $files = [];
            foreach (['pack.json', 'pack.css'] as $fn) {
              if (is_file($pdir . '/' . $fn)) $files[$pid . '/' . $fn] = $pdir . '/' . $fn;
            }
            $name = 'souliong-pack-' . $pid . '-' . date('Ymd-His') . '.zip';
            $tmp = tempnam(sys_get_temp_dir(), 'skpk');
            if (!zip_pack($tmp, $files)) {
              http_response_code(500);
              exit($t('backup_failed_msg'));
            }
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . filesize($tmp));
            readfile($tmp);
            @unlink($tmp);
            exit;
          }
          // ── 圖層匯出：整個圖層資料夾打包，路徑含 <id>/ 前綴（同 pack）。與 pack 的差別是檔案
          //    數量不固定——單張疊圖只有兩個檔，切好的圖磚金字塔可能上萬個——所以要遞迴走訪，
          //    並且設上限：超過就擋下來，請對方直接從伺服器取，而不是讓這個請求跑到逾時。 ──
          if ($_GET['backup'] === 'layer') {
            $lid = clean_id($_GET['layer'] ?? '');
            $lp  = clean_id($_GET['project'] ?? '');
            $all = souliong_layer_list($cfg, $lp);
            if ($lid === '' || !isset($all[$lid])) {
              error_page(404, $t('error_404_title'), $t('layer_not_found_msg'), Route::manager('', 'tools'), $t('back_to_admin'));
            }
            // 全站層歸主要管理者，專案層歸該專案的管理者——權限跟著圖層實際住在哪裡走
            $isProj = ($all[$lid]['scope'] ?? '') === 'project';
            if ($isProj ? !$canLayers($lp) : !Auth::can($cfg, null, 'manage_layers')) {
              error_page(403, $t('no_permission_title'), $t('primary_only_layers_msg'), Route::manager('', 'tools'), $t('back_to_admin'));
            }
            $ldir = souliong_layer_dir($cfg, $lid, $lp);
            $files = souliong_layer_files($ldir, $lid, $err);
            if ($err !== '') {
              error_page(413, $t('error_413_title'), $t($err), Route::manager('', 'tools'), $t('back_to_admin'));
            }
            // 「含原稿」：只有專案層可能有原稿（全站層沒有 layersrc/，見 api/layers.php），
            // 併進同一個 $files 陣列直接一起打包——兩邊的 key 都是 "<id>/相對路徑"，_src/ 前綴
            // 保證不會跟圖磚路徑撞名，不需要另外處理衝突。
            $withSrc = $isProj && !empty($_GET['src']);
            if ($withSrc) {
              $files += souliong_layersrc_files(souliong_layersrc_dir($cfg, $lp, $lid), $lid);
            }
            $name = 'souliong-layer-' . $lid . ($withSrc ? '-src' : '') . '-' . date('Ymd-His') . '.zip';
            $tmp = tempnam(sys_get_temp_dir(), 'sklyr');
            if (!zip_pack($tmp, $files)) {
              http_response_code(500);
              exit($t('backup_failed_msg'));
            }
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . filesize($tmp));
            readfile($tmp);
            @unlink($tmp);
            exit;
          }
          $bp = $_GET['backup'] === 'project' ? clean_id($_GET['project'] ?? '') : null;
          if ($bp === null && !Auth::can($cfg, null, 'manage_site')) {
            error_page(403, $t('no_permission_title'), $t('primary_only_backup_all_msg'), Route::manager($scopeProject, 'tools'), $t('back_to_admin'));
          }
          if ($bp !== null && !$canBackup($bp)) {
            error_page(403, $t('no_permission_title'), $t('no_project_permission_msg'), Route::manager((string)$bp, 'tools'), $t('back_to_admin'));
          }
          if ($bp === null) audit_log($cfg, $auditWho(), 'backup_all', null, '');
          $files = [];
          $addDir = function ($absDir, $prefix) use (&$files) {
            if (!is_dir($absDir)) return;
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absDir, FilesystemIterator::SKIP_DOTS)) as $f) {
              if (!$f->isFile() || strpos($f->getPathname(), DIRECTORY_SEPARATOR . '.rate' . DIRECTORY_SEPARATOR) !== false) continue;
              $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($absDir))), '/');
              $files[$prefix . '/' . $rel] = $f->getPathname();
            }
          };
          if ($bp) {
            $addDir(project_dir($cfg, $bp), 'projects/' . $bp);
            $name = 'souliong-' . $bp . '-' . date('Ymd-His') . '.zip';
          } else {
            $addDir($cfg['projects_dir'], 'projects');
            $addDir($cfg['state_dir'], 'data');
            $name = 'souliong-all-' . date('Ymd-His') . '.zip';
          }
          $tmp = tempnam(sys_get_temp_dir(), 'skbk');
          if (!zip_pack($tmp, $files)) {
            http_response_code(500);
            exit($t('backup_failed_msg'));
          }
          header('Content-Type: application/zip');
          header('Content-Disposition: attachment; filename="' . $name . '"');
          header('Content-Length: ' . filesize($tmp));
          readfile($tmp);
          @unlink($tmp);
          exit;
        }

        // ── 匯入還原（合併／覆蓋，ZIP 可能含任何專案的資料） ──
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
          $gate(null, 'manage_site', 'primary_only_import_msg', Route::manager($scopeProject, 'tools'));
          need_sitewide_primary('primary_only_import_msg');
          require_once __DIR__ . '/zip.php';
          $imported = 0;
          if (isset($_FILES['backup']) && $_FILES['backup']['error'] === UPLOAD_ERR_OK) {
            $mode = ($_POST['mode'] ?? 'merge') === 'replace' ? 'replace' : 'merge';
            // 只接受 projects/、data/pins.json 底下、無 .. 的安全路徑（data/admin_pins.json 是改名前的舊備份，相容）
            $accept = fn($nm) => strpos(str_replace('\\', '/', (string)$nm), '..') === false
              && preg_match('#^(projects/[A-Za-z0-9_./-]+|data/(?:admin_)?pins\.json)$#', str_replace('\\', '/', (string)$nm));
            $entries = zip_unpack($_FILES['backup']['tmp_name'], $accept);
            // 1) 資料（jsonl）：spots.jsonl／entries.jsonl 各自獨立比對、各自寫回對應路徑
            // （見 store.php 的 store_file()——備份的匯出本來就是整目錄打包，兩個檔名都會在 zip 裡）。
            foreach ($entries as $nm => $content) {
              if (!preg_match('#^projects/([a-z0-9_-]+)/(spots|entries)\.jsonl$#', str_replace('\\', '/', $nm), $mm)) continue;
              $proj = $mm[1];
              if ($mode === 'replace') {
                $dest = project_dir($cfg, $proj) . '/' . $mm[2] . '.jsonl';
                store_backup($dest);
                @file_put_contents($dest, $content, LOCK_EX);
              } else {
                $ids = [];
                foreach (store_all($cfg, $proj) as $r) {
                  $ids[(string)($r['id'] ?? '')] = true;
                }
                foreach (preg_split('/\r?\n/', (string)$content) as $ln) {
                  $ln = trim($ln);
                  if ($ln === '') continue;
                  $rec = json_decode($ln, true);
                  if (is_array($rec) && !isset($ids[(string)($rec['id'] ?? '')])) {
                    store_append($cfg, $proj, $rec);
                    $imported++;
                  }
                }
              }
            }
            // 2) 統計 / 投稿代碼（僅覆蓋模式才蓋掉）
            if ($mode === 'replace') {
              foreach ($entries as $nm => $content) {
                if (preg_match('#^projects/([a-z0-9_-]+)/(stats\.json|code\.txt|codes\.json|contrib\.json)$#', str_replace('\\', '/', $nm), $mm)) @file_put_contents(project_dir($cfg, $mm[1]) . '/' . $mm[2], $content, LOCK_EX);
                elseif ($nm === 'data/pins.json' || $nm === 'data/admin_pins.json') {
                  @file_put_contents(pins_file($cfg), $content, LOCK_EX);
                }
              }
            }
            // 3) 照片（合併只補缺的；覆蓋才蓋）
            // 內容需經 getimagesize 驗證是真的圖片，副檔名一律改用驗證後的真實類型推算，
            // 不採信 zip 內原始檔名的副檔名（比照 upload.php 的做法，避免備份 zip 夾帶偽裝成
            // 照片的可執行檔——單靠內容驗證不夠，因為攻擊者可以做出「內容是合法圖片、檔名卻是
            // .php」的多型檔案，實際執行與否看的是檔名副檔名，所以副檔名也必須是伺服器端推算）。
            foreach ($entries as $nm => $content) {
              if (!preg_match('#^projects/([a-z0-9_-]+)/photos/([A-Za-z0-9_.-]+)$#', str_replace('\\', '/', $nm), $mm)) continue;
              $info = @getimagesizefromstring($content);
              $mime = is_array($info) ? ($info['mime'] ?? '') : '';
              if (!isset($cfg['allowed_mime'][$mime])) continue;
              $base = preg_replace('/\.[A-Za-z0-9]+$/', '', $mm[2]);
              if ($base === '') continue;
              $fname = $base . '.' . $cfg['allowed_mime'][$mime];
              $destDir = project_dir($cfg, $mm[1]) . '/photos';
              if (!is_dir($destDir)) @mkdir($destDir, 0775, true);
              $dest = $destDir . '/' . $fname;
              if ($mode === 'replace' || !is_file($dest)) @file_put_contents($dest, $content);
            }
            // 3b) 影音（media/）：跟照片同一套規則，副檔名一律由伺服器依驗證過的內容推算。
            // 這裡多收一種情況——影片封面圖是張圖片，卻跟主檔一起放在 media/，所以圖片型別也放行。
            // 沒有 finfo 擴充就整段跳過（比照 upload.php，影音的型別驗證非它不可，寧可不還原也不能亂收）。
            if (class_exists('finfo')) {
              $mediaExt = [];
              foreach (souliong_kinds() as $kInfo) {
                if (($kInfo['file'] ?? null) === 'media') $mediaExt += ($kInfo['mimes'] ?? []);
              }
              $fi = new finfo(FILEINFO_MIME_TYPE);
              foreach ($entries as $nm => $content) {
                if (!preg_match('#^projects/([a-z0-9_-]+)/media/([A-Za-z0-9_.-]+)$#', str_replace('\\', '/', $nm), $mm)) continue;
                $mime = (string)$fi->buffer($content);
                $ext = $mediaExt[$mime] ?? ($cfg['allowed_mime'][$mime] ?? null);
                if ($ext === null) continue;
                $base = preg_replace('/\.[A-Za-z0-9]+$/', '', $mm[2]);
                if ($base === '') continue;
                $destDir = project_dir($cfg, $mm[1]) . '/media';
                if (!is_dir($destDir)) @mkdir($destDir, 0775, true);
                $dest = $destDir . '/' . $base . '.' . $ext;
                if ($mode === 'replace' || !is_file($dest)) @file_put_contents($dest, $content);
              }
            }
            audit_log($cfg, $auditWho(), 'import', null, $mode . ', +' . $imported . ' 筆');
          }
          header('Location: ' . Route::manager('', 'tools'));
          exit;
        }

        // ── 主題包匯入：zip 內路徑需含 <id>/ 前綴，id 只認資料夾名稱（避免 pack.json 內容偽造）。
        //    跟圖層同一套「匯到哪裡」：沒帶 project ＝全站包（主要管理者限定），帶了 project ＝
        //    該地圖自己的包。匯入不會刪掉 zip 裡沒有的舊檔（覆蓋不是取代）。 ──
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'packimport') {
          $pp = clean_id($_POST['project'] ?? '');
          $backTo = Route::manager($pp, 'tools');
          $gateLayers($pp, 'primary_only_packs_msg', $backTo);
          $roots = souliong_pack_roots($cfg, $pp);
          $destRoot = rtrim((string)($pp === '' ? ($roots['site'] ?? '') : ($roots['project'] ?? '')), '/\\');
          if ($destRoot === '') {
            error_page(500, $t('error_500_title'), $t('pack_dest_missing_msg'), $backTo, $t('back_to_admin'));
          }
          require_once __DIR__ . '/zip.php';
          if (isset($_FILES['pack']) && $_FILES['pack']['error'] === UPLOAD_ERR_OK) {
            $accept = fn($nm) => strpos(str_replace('\\', '/', (string)$nm), '..') === false
              && preg_match('#^[a-z0-9_-]+/(pack\.json|pack\.css)$#', str_replace('\\', '/', (string)$nm));
            $entries = zip_unpack($_FILES['pack']['tmp_name'], $accept);
            $ids = [];
            foreach ($entries as $nm => $content) {
              if (!preg_match('#^([a-z0-9_-]+)/(pack\.json|pack\.css)$#', str_replace('\\', '/', $nm), $mm)) continue;
              $destDir = $destRoot . '/' . $mm[1];
              if (!is_dir($destDir)) @mkdir($destDir, 0775, true);
              @file_put_contents($destDir . '/' . $mm[2], $content, LOCK_EX);
              $ids[$mm[1]] = true;
            }
            audit_log($cfg, $auditWho(), 'pack_import', $pp !== '' ? $pp : null, implode(',', array_keys($ids)));
          }
          header('Location: ' . $backTo);
          exit;
        }

        // ── 圖層匯入：zip 內路徑需含 <id>/ 前綴，id 只認資料夾名稱（避免 layer.json 內容偽造，同 pack）。
        //    比 pack 多一個「匯到哪裡」：沒帶 project ＝全站層（主要管理者限定），帶了 project ＝
        //    該地圖自己的圖層。切好的圖磚金字塔請匯進專案——projects/ 本來就不進版控。
        //    匯入不會刪掉 zip 裡沒有的舊檔（同 pack，是覆蓋不是取代）。 ──
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'layerimport') {
          $lp = clean_id($_POST['project'] ?? '');
          $backTo = Route::manager($lp, 'tools');
          $gateLayers($lp, 'primary_only_layers_msg', $backTo);
          $roots = souliong_layer_roots($cfg, $lp);
          $destRoot = rtrim((string)($lp === '' ? ($roots['site'] ?? '') : ($roots['project'] ?? '')), '/\\');
          if ($destRoot === '') {
            error_page(500, $t('error_500_title'), $t('layer_dest_missing_msg'), $backTo, $t('back_to_admin'));
          }
          require_once __DIR__ . '/zip.php';
          if (isset($_FILES['layer']) && $_FILES['layer']['error'] === UPLOAD_ERR_OK) {
            // <id>/(層層子目錄/)?(layer.json | 白名單副檔名的圖檔)。子目錄放行是因為圖磚就是
            // <z>/<x>/<y>.png 三層；副檔名白名單與 souliong_layer_files() 共用同一份名單。
            // 匯進來的 SVG 可能內嵌 <script>，那在圖檔端點是靠回應的 CSP sandbox 擋掉的，不是靠
            // 這裡的過濾——這裡只保證「副檔名是圖檔」，執行與否由 layerfile.php 決定。
            //
            // <id>/_src/(edit.json | p<idx>.圖檔) 是「含原稿」匯出（souliong_layersrc_files()）
            // 帶出來的原稿，要單獨用一條規則認出來、路由回 layersrc/ 而不是跟圖層本體混在一起
            // 落地——那邊沒有存取管制（見 souliong_layersrc_dir() 的說明），原稿一旦落進
            // layers/<id>/ 底下，任何猜到網址的人都拿得到。全站層沒有原稿，$lp==='' 時一律不收。
            $exts = implode('|', array_keys(souliong_layer_mimes()));
            // 子目錄那段要排掉 "_src/" 開頭——不然 _src/p0.png 這種原稿檔名同時也符合圖磚檔名的
            // 形狀，會被這條規則收走，跟下面 $reSrc 兩邊都收，寫檔迴圈裡 $reMain 先判到就直接
            // 落地到圖層本體資料夾，原稿因此漏進沒有存取管制的那一邊。
            $reMain = '#^([a-z0-9_-]+)/((?:(?!_src/)[A-Za-z0-9_.-]+/)*(?:layer\.json|[A-Za-z0-9_.-]+\.(?:' . $exts . ')))$#';
            $reSrc  = '#^([a-z0-9_-]+)/_src/(edit\.json|p[0-9]{1,2}\.(?:' . $exts . '))$#';
            $norm = fn($nm) => str_replace('\\', '/', (string)$nm);
            $accept = fn($nm) => strpos($norm($nm), '..') === false
              && (preg_match($reMain, $norm($nm)) || ($lp !== '' && preg_match($reSrc, $norm($nm))));
            $entries = zip_unpack($_FILES['layer']['tmp_name'], $accept);

            // 匯入是覆蓋不是取代（同主題包），但「覆蓋」要使用者確認過才做——不然同名圖層一匯
            // 就默默蓋掉，之前的位置、原稿全部沒了也不會有任何提示。id 要打開 ZIP 才知道，沒辦法
            // 像 tilecut.php 那樣在使用者送出前就先問，只能先掃一輪找出會撞名的 id，撞了又沒勾
            // 「覆蓋」就整包擋下，不寫任何檔案。
            $incoming = [];
            foreach (array_keys($entries) as $nm) {
              $nm2 = $norm($nm);
              if (preg_match($reMain, $nm2, $mm) || preg_match($reSrc, $nm2, $mm)) {
                $incoming[$mm[1]] = true;
              }
            }
            $clash = array_values(array_filter(array_keys($incoming), fn($iid) => is_dir($destRoot . '/' . $iid)));
            if ($clash && empty($_POST['overwrite'])) {
              error_page(409, $t('error_400_title'), i18n_t($DICT, 'layer_import_exists_msg', ['ids' => implode(', ', $clash)]), $backTo, $t('back_to_admin'));
            }

            $ids = [];
            foreach ($entries as $nm => $content) {
              $nm2 = $norm($nm);
              if (preg_match($reMain, $nm2, $mm)) {
                $dest = $destRoot . '/' . $mm[1] . '/' . $mm[2];
              } elseif ($lp !== '' && preg_match($reSrc, $nm2, $mm)) {
                $sdir = souliong_layersrc_dir($cfg, $lp, $mm[1]);
                if ($sdir === null) continue;
                $dest = $sdir . '/' . $mm[2];
              } else {
                continue;
              }
              $dd = dirname($dest);
              if (!is_dir($dd)) @mkdir($dd, 0775, true);
              @file_put_contents($dest, $content, LOCK_EX);
              $ids[$mm[1]] = true;
            }
            audit_log($cfg, $auditWho(), 'layer_import', $lp !== '' ? $lp : null, implode(',', array_keys($ids)));
          }
          header('Location: ' . $backTo);
          exit;
        }

        // ── 封面圖片：上傳／重設共用同一套存檔邏輯（api/coverlib.php），跟前台自動快照
        //    （api/cover.php）寫的是同一份檔案。手動上傳一律存 mode=custom，理由見 coverlib.php。 ──
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'coverupload') {
          $cp = clean_id($_POST['project'] ?? '');
          $backTo = Route::manager($scopeProject !== '' ? $cp : '', 'access');
          $gate($cp, 'edit_meta', 'no_permission_msg', $backTo);
          $cmf = $cfg['projects_dir'] . '/' . $cp . '/meta.json';
          $cbase = project_dir($cfg, $cp) . '/cover';
          $cmeta = is_file($cmf) ? json_decode((string)@file_get_contents($cmf), true) : [];
          if (!is_array($cmeta)) $cmeta = [];
          if (isset($_FILES['cover']) && $_FILES['cover']['error'] === UPLOAD_ERR_OK) {
            $bytes = @file_get_contents($_FILES['cover']['tmp_name']);
            if ($bytes !== false) {
              $r = cover_apply_bytes($cfg, $cbase, $cmf, $cmeta, $bytes, 'custom');
              if ($r['ok']) audit_log($cfg, $auditWho(), 'cover_upload', $cp, '');
            }
          }
          header('Location: ' . $backTo);
          exit;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'coverreset') {
          $cp = clean_id($_POST['project'] ?? '');
          $backTo = Route::manager($scopeProject !== '' ? $cp : '', 'access');
          $gate($cp, 'edit_meta', 'no_permission_msg', $backTo);
          $cmf = $cfg['projects_dir'] . '/' . $cp . '/meta.json';
          $cbase = project_dir($cfg, $cp) . '/cover';
          $cmeta = is_file($cmf) ? json_decode((string)@file_get_contents($cmf), true) : [];
          if (!is_array($cmeta)) $cmeta = [];
          cover_apply_reset($cbase, $cmf, $cmeta);
          audit_log($cfg, $auditWho(), 'cover_reset', $cp, '');
          header('Location: ' . $backTo);
          exit;
        }

        // ── 地圖標記圖片：上傳／重設，跟封面共用同一套影像處理（api/coverlib.php）。
        //    存在才會生效——action=meta 那邊若收到 pinMark=image 但沒有檔案會退回 number，
        //    所以這裡上傳成功才把 pinMark 切成 image，跟封面上傳自動轉 mode=custom 同一個道理。 ──
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pinmarkupload') {
          $cp = clean_id($_POST['project'] ?? '');
          $backTo = Route::manager($scopeProject !== '' ? $cp : '', 'access');
          $gate($cp, 'edit_meta', 'no_permission_msg', $backTo);
          $cmf = $cfg['projects_dir'] . '/' . $cp . '/meta.json';
          $pmbase = project_dir($cfg, $cp) . '/pinmark';
          $cmeta = is_file($cmf) ? json_decode((string)@file_get_contents($cmf), true) : [];
          if (!is_array($cmeta)) $cmeta = [];
          if (isset($_FILES['pinmark']) && $_FILES['pinmark']['error'] === UPLOAD_ERR_OK) {
            $bytes = @file_get_contents($_FILES['pinmark']['tmp_name']);
            if ($bytes !== false) {
              $r = pinmark_apply_bytes($cfg, $pmbase, $cmf, $cmeta, $bytes);
              if ($r['ok']) audit_log($cfg, $auditWho(), 'pinmark_upload', $cp, '');
            }
          }
          header('Location: ' . $backTo);
          exit;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pinmarkreset') {
          $cp = clean_id($_POST['project'] ?? '');
          $backTo = Route::manager($scopeProject !== '' ? $cp : '', 'access');
          $gate($cp, 'edit_meta', 'no_permission_msg', $backTo);
          $cmf = $cfg['projects_dir'] . '/' . $cp . '/meta.json';
          $pmbase = project_dir($cfg, $cp) . '/pinmark';
          $cmeta = is_file($cmf) ? json_decode((string)@file_get_contents($cmf), true) : [];
          if (!is_array($cmeta)) $cmeta = [];
          pinmark_apply_reset($pmbase, $cmf, $cmeta);
          audit_log($cfg, $auditWho(), 'pinmark_reset', $cp, '');
          header('Location: ' . $backTo);
          exit;
        }

        // ── 圖層的解析：刪除與就地編輯共用。刻意用「作用域對應的那個 root」而不是
        //    souliong_layer_dir()——後者同名時會偏好專案層，用在這裡的話，想刪全站層卻剛好有
        //    同名專案層時就會刪錯一邊。權限規則與 layerimport 相同：圖層住哪，權限就跟到哪。 ──
        $layerTarget = function (string $lp, string $lid) use ($cfg, $gateLayers, $t): array {
          $backTo = Route::manager($lp, 'tools');
          $gateLayers($lp, 'primary_only_layers_msg', $backTo);
          $roots = souliong_layer_roots($cfg, $lp);
          $root = rtrim((string)($lp === '' ? ($roots['site'] ?? '') : ($roots['project'] ?? '')), '/\\');
          $dir = ($root !== '' && preg_match('/^[a-z0-9_-]+$/', $lid)) ? $root . '/' . $lid : '';
          if ($dir === '' || !is_dir($dir) || !is_file($dir . '/layer.json')) {
            error_page(404, $t('error_404_title'), $t('layer_not_found_msg'), $backTo, $t('back_to_admin'));
          }
          return [$root, $dir, $backTo];
        };

        // ── 圖層刪除：整個資料夾。切好的金字塔可能上萬個檔，靠手動刪不實際，所以後台要有這條路。
        //    刪掉之後仍指著它的 meta.json 不必清理——souliong_layers_for() 對找不到的 id 本來就
        //    靜靜略過（同 pack），地圖只會少一層而不會開天窗。 ──
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'layerdelete') {
          $lp  = clean_id($_POST['project'] ?? '');
          $lid = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '', $_POST['layer'] ?? ''));
          [$root, $dir, $backTo] = $layerTarget($lp, $lid);
          // 全站預設層刪掉的話，所有沒自訂圖層的地圖會同時變成一片空白——這種「一鍵讓整站沒有
          // 底圖」的操作不該只靠一個 confirm 擋。要換預設請先改 config 的 default_layers。
          if ($lp === '' && in_array($lid, souliong_default_layers($cfg), true)) {
            error_page(409, $t('error_400_title'), $t('layer_is_default_msg', ['id' => $lid]), $backTo, $t('back_to_admin'));
          }
          if (!souliong_layer_rmtree($dir, $root)) {
            error_page(500, $t('error_500_title'), $t('layer_delete_failed_msg'), $backTo, $t('back_to_admin'));
          }
          // 保留的原稿跟著走。刪了圖層卻留下一份沒有任何入口進得去的高解析手稿，只是佔空間，
          // 而且是一份沒人記得自己還存著的東西。清不掉不擋流程（圖層已經沒了，退不回去），
          // 但要留在 audit log 裡，不然沒有人會發現。
          $lsrc = $lp !== '' ? souliong_layersrc_dir($cfg, $lp, $lid) : null;
          $srcLeft = $lsrc !== null && is_dir($lsrc) && !souliong_layer_rmtree($lsrc, dirname($lsrc));
          audit_log($cfg, $auditWho(), 'layer_delete', $lp !== '' ? $lp : null, $lid . ($srcLeft ? ' (layersrc left)' : ''));
          header('Location: ' . $backTo);
          exit;
        }

        // ── 圖層設定就地編輯：只動 layer.json 裡「位置與外觀」那幾欄。
        //
        //    這條路存在的理由是成本不對稱：切一次圖磚可能產生上萬個檔，卻只為了改個名字、調個
        //    不透明度或把疊圖往東挪幾公尺就要重切整包，太浪費。
        //
        //    讀進來的是完整 manifest，底下只覆寫表單認得的欄位，其餘（url、urlDark、subdomains、
        //    detectRetina、type、desc、maxNativeZoom、generated…）原樣寫回去。表單沒有的欄位
        //    不等於使用者想清掉它。 ──
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'layeredit') {
          $lp  = clean_id($_POST['project'] ?? '');
          $lid = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '', $_POST['layer'] ?? ''));
          [$root, $dir, $backTo] = $layerTarget($lp, $lid);
          $mf = $dir . '/layer.json';
          $manifest = json_decode((string)@file_get_contents($mf), true);
          if (!is_array($manifest)) {
            error_page(404, $t('error_404_title'), $t('layer_not_found_msg'), $backTo, $t('back_to_admin'));
          }
          unset($manifest['id'], $manifest['scope']);   // 這兩欄是列表時才補上的，不該落地

          $label = trim((string)($_POST['label'] ?? ''));
          $manifest['label'] = mb_substr($label !== '' ? $label : (string)($manifest['label'] ?? $lid), 0, 60);

          $pane = (string)($_POST['pane'] ?? '');
          $manifest['pane'] = in_array($pane, souliong_layer_panes(), true) ? $pane : (string)($manifest['pane'] ?? 'art');

          // 1 是 Leaflet 的預設值，寫進去只是雜訊；設回 1 就把這個 key 拿掉。
          $op = round(max(0.0, min(1.0, (float)($_POST['opacity'] ?? 1))), 3);
          if ($op >= 1.0) { unset($manifest['opacity']); } else { $manifest['opacity'] = $op; }

          $attr = trim((string)($_POST['attribution'] ?? ''));
          // 500 而不是 200：內建的 CARTO 那三層光是 attribution 就 215 字（兩個帶 target/rel 的
          // <a>），砍在 200 會把使用者從沒碰過的欄位默默截斷，正是不該發生的那種資料遺失。
          if ($attr === '') { unset($manifest['attribution']); } else { $manifest['attribution'] = mb_substr($attr, 0, 500); }

          // 邊界四格：四格全空＝這一層本來就沒有範圍限制（外部圖磚服務就是這樣），不無中生有；
          // 填了就要四格都填、而且要是投影畫得出來的範圍。只填一兩格是打到一半，當成錯誤。
          $nums = [];
          foreach (['south', 'west', 'north', 'east'] as $bk) {
            $bv = trim((string)($_POST[$bk] ?? ''));
            if ($bv !== '' && is_numeric($bv)) { $nums[$bk] = (float)$bv; }
          }
          if (count($nums) === 4) {
            if (!souliong_layer_bounds_valid($nums['south'], $nums['west'], $nums['north'], $nums['east'])) {
              error_page(400, $t('error_400_title'), $t('tilecut_bad_bounds_msg'), $backTo, $t('back_to_admin'));
            }
            $manifest['bounds'] = [[$nums['south'], $nums['west']], [$nums['north'], $nums['east']]];
          } elseif ($nums) {
            error_page(400, $t('error_400_title'), $t('tilecut_bad_bounds_msg'), $backTo, $t('back_to_admin'));
          } else {
            unset($manifest['bounds']);
          }

          // 縮放範圍：空白＝不寫，交給 Leaflet 的預設。maxNativeZoom 不開放編輯——那是「圖磚
          // 實際切到第幾級」，由切圖工具寫入，改它只會讓 Leaflet 去要不存在的磚。
          $zs = [];
          foreach (['minZoom', 'maxZoom'] as $zk) {
            $zv = trim((string)($_POST[$zk] ?? ''));
            if ($zv === '' || !is_numeric($zv)) { unset($manifest[$zk]); continue; }
            $zs[$zk] = max(0, min(24, (int)$zv));
            $manifest[$zk] = $zs[$zk];
          }
          if (isset($zs['minZoom'], $zs['maxZoom']) && $zs['minZoom'] > $zs['maxZoom']) {
            error_page(400, $t('error_400_title'), $t('layer_bad_zoom_msg'), $backTo, $t('back_to_admin'));
          }

          $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
          if ($json === false || @file_put_contents($mf, $json . "\n", LOCK_EX) === false) {
            error_page(500, $t('error_500_title'), $t('layer_save_failed_msg'), $backTo, $t('back_to_admin'));
          }
          audit_log($cfg, $auditWho(), 'layer_edit', $lp !== '' ? $lp : null, $lid);
          header('Location: ' . $backTo);
          exit;
        }

        // ── 主題包刪除：整包資料夾。權限與路徑解析方式同 $layerTarget——用作用域對應的
        //    root，不用 souliong_pack_dir()，避免同名時刪錯邊。刪掉之後仍指著它的 meta.json／
        //    settings.json 不必清理，souliong_pack_for() 對找不到的 id 本來就靜靜略過。 ──
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'packdelete') {
          $pp  = clean_id($_POST['project'] ?? '');
          $pid = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '', $_POST['pack'] ?? ''));
          $backTo = Route::manager($pp, 'tools');
          $gateLayers($pp, 'primary_only_packs_msg', $backTo);
          $roots = souliong_pack_roots($cfg, $pp);
          $root = rtrim((string)($pp === '' ? ($roots['site'] ?? '') : ($roots['project'] ?? '')), '/\\');
          $dir = ($root !== '' && preg_match('/^[a-z0-9_-]+$/', $pid)) ? $root . '/' . $pid : '';
          if ($dir === '' || !is_dir($dir) || !is_file($dir . '/pack.json')) {
            error_page(404, $t('error_404_title'), $t('pack_not_found_msg'), $backTo, $t('back_to_admin'));
          }
          // 全站預設包刪掉的話，所有沒自訂包的地圖會同時掉皮——這種「一鍵讓整站沒有主題」的
          // 操作不該只靠一個 confirm 擋。要換預設請先去「工具」分頁改掉全站預設。
          if ($pp === '' && $pid === souliong_site_pack($cfg)) {
            error_page(409, $t('error_400_title'), $t('pack_is_default_msg', ['id' => $pid]), $backTo, $t('back_to_admin'));
          }
          if (!souliong_layer_rmtree($dir, $root)) {
            error_page(500, $t('error_500_title'), $t('pack_delete_failed_msg'), $backTo, $t('back_to_admin'));
          }
          audit_log($cfg, $auditWho(), 'pack_delete', $pp !== '' ? $pp : null, $pid);
          header('Location: ' . $backTo);
          exit;
        }

        // ── 資料 ──（$allProjects 沿用前面「專案清單」算好的那份，中間的動作不會新增/刪除專案目錄）
        $viewProjects = $primary
          ? ($scopeProject !== '' ? [$scopeProject] : $allProjects)
          : ($isAcct ? ($scopeProject !== '' ? [$scopeProject] : $acctProjects) : [$reqProject]);

        $rows = [];
        foreach ($viewProjects as $p) {
          foreach (store_all($cfg, $p) as $r) {
            $r['project'] = $p;
            $rows[] = $r;
          }
        }
        usort($rows, fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
        $short = fn($s) => $s ? substr((string)$s, 0, 8) : '—';
        // 編輯版本的 photo/exif 依設計為 null（沿用原始投稿），顯示時要透過 edit_of 回原始那筆取值
        $byId = [];
        foreach ($rows as $r) { $byId[$r['project'] . '/' . (string)($r['id'] ?? '')] = $r; }
        // 依 project 分組，供下方專案清單迴圈依 key 取用。
        $rowsByProject = [];
        foreach ($rows as $r) { $rowsByProject[$r['project']][] = $r; }
        // 全站 pins 資料，讀一次供下方迴圈依 key 取用。
        $pinsAllData = pins_load($cfg);

        header('Content-Type: text/html; charset=utf-8');
          ?>
<!DOCTYPE html>
<html lang="<?= $LANG === 'en' ? 'en' : 'zh-Hant' ?>">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $t('app_title') ?> · <?= $t('admin_panel_suffix') ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <style>
    /* 間距、字級、點擊區一律用 rem：使用者把瀏覽器預設字級調大時，留白會跟著放大，
       版面不會因為字變大就擠成一團。只有不該縮放的東西留 px：1px 框線、藥丸圓角、陰影。 */
    :root {
      color-scheme: light dark;
      --bg: #f6f6f7;
      --fg: #1b1b1d;
      --muted: #6b6b70;
      --line: #e7e7ea;
      --card: #fff;
      --accent: #1b1b1d;
      --accent-fg: #fff;
      --r-lg: 1.25rem;
      --r-md: 0.8125rem;
      --r-sm: 0.625rem;
      --sh: 0 6px 24px rgba(0, 0, 0, .08);
      --danger: #c0392b;
      --t: .18s ease;
      /* 間距級距：同一層級用同一格，避免每處各寫一個數字 */
      --sp-1: 0.25rem;
      --sp-2: 0.5rem;
      --sp-3: 0.75rem;
      --sp-4: 1rem;
      --sp-5: 1.5rem;
      --sp-6: 2rem;
      /* 最小點擊區：WCAG 2.2 SC 2.5.8 下限是 24px，取 1.75rem（28px）留餘裕 */
      --tap: 1.75rem
    }

    @media (prefers-color-scheme:dark) {
      :root {
        --bg: #111113;
        --fg: #f1f1f3;
        --muted: #9c9ca3;
        --line: #2b2b2f;
        --card: #1c1c1f;
        --accent: #f1f1f3;
        --accent-fg: #151517;
        --sh: 0 6px 24px rgba(0, 0, 0, .4);
        --danger: #ff6b6b
      }
    }

    * {
      box-sizing: border-box
    }

    body {
      margin: 0;
      font-family: system-ui, sans-serif;
      /* 中文在 line-height:normal（約 1.2）下字行會黏在一起；1.6 是這頁的基準行距 */
      line-height: 1.6;
      background: var(--bg);
      color: var(--fg);
      -webkit-font-smoothing: antialiased
    }

    /* 語言切換：原本是 position:fixed 貼右上角，但 .wrap 只有 62.5rem 寬且置中，
       視窗窄於約 1192px 時就會壓在「登出」按鈕上。改成跟著版面走的一列，任何寬度都不會疊到。 */
    .langsw {
      display: flex;
      justify-content: flex-end;
      gap: var(--sp-1);
      font-size: 0.75rem;
      margin-bottom: var(--sp-2)
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

    .langsw a:not(.on):hover {
      background: var(--card)
    }

    .wrap {
      max-width: 62.5rem;
      margin: 0 auto;
      padding: var(--sp-5) var(--sp-4) var(--sp-6)
    }

    .top {
      display: flex;
      align-items: center;
      gap: var(--sp-3);
      flex-wrap: wrap
    }

    h1 {
      font-size: 1.375rem;
      line-height: 1.3;
      font-weight: 800;
      margin: 0;
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 0 var(--sp-2);
      flex: 1
    }

    h1 .sub {
      font-size: 0.75rem;
      font-weight: 500;
      color: var(--muted);
      white-space: nowrap
    }

    h2 {
      font-size: 0.8125rem;
      font-weight: 700;
      color: var(--muted);
      letter-spacing: .06em;
      text-transform: uppercase;
      margin: var(--sp-6) 0 var(--sp-3)
    }

    .card {
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: var(--r-lg);
      box-shadow: var(--sh)
    }

    /* 投稿代碼：一碼一張卡，排成 grid。用 auto-fill 不用 auto-fit——只有一張卡時
       auto-fit 會把它撐滿整列，QR 縮在左邊、右邊一大片空白。 */
    .code-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(17rem, 1fr));
      gap: var(--sp-3);
      margin-top: var(--sp-2)
    }

    /* 投稿代碼是要唸給人聽、打進欄位的，所以碼本身放大當主角；
       QR 只有真的要給人掃時才需要，收進「分享」按鈕展開全螢幕（.qr-trigger） */
    .codecard {
      display: flex;
      flex-direction: column;
      gap: var(--sp-2);
      padding: var(--sp-4)
    }

    .codecard-acts {
      display: flex;
      align-items: center;
      gap: var(--sp-2);
      margin-top: var(--sp-1)
    }

    .codecard-acts form {
      display: inline-flex;
      margin: 0
    }

    /* 同一排的圖示鈕跟「分享」鈕等高，看起來才是一組 */
    .codecard-acts .chipbtn,
    .codecard-acts .x {
      width: 2.25rem;
      height: 2.25rem
    }

    .codecard-acts .x {
      margin-left: auto
    }

    .sharenew .qr {
      background: #fff;
      padding: 0.375rem;
      border-radius: var(--r-sm);
      flex: none;
      width: 5.25rem;
      height: 5.25rem;
      cursor: zoom-in
    }

    /* 點 QR 展開全螢幕，方便現場給人掃描 */
    .qr-modal {
      position: fixed;
      inset: 0;
      z-index: 999;
      display: flex;
      align-items: center;
      justify-content: center;
      background: rgba(0, 0, 0, .78);
      padding: var(--sp-5)
    }

    .qr-modal .qr-modal-card {
      position: relative;
      background: #fff;
      border-radius: 1.5rem;
      padding: var(--sp-6) var(--sp-5) var(--sp-5);
      max-width: min(88vw, 20rem);
      width: 100%;
      text-align: center;
      box-shadow: 0 24px 70px rgba(0, 0, 0, .45)
    }

    .qr-modal .qr-modal-close {
      position: absolute;
      top: var(--sp-3);
      right: var(--sp-3);
      width: 2rem;
      height: 2rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border: 1px solid #e4e4e7;
      border-radius: 999px;
      background: #fff;
      color: #555;
      font-size: 0.8125rem;
      cursor: pointer
    }

    .qr-modal .qr-modal-title {
      font-size: 0.9375rem;
      font-weight: 700;
      color: #1a1a1a;
      margin-bottom: var(--sp-4)
    }

    /* QR 是配角：夠掃就好，不要整張卡都是它 */
    .qr-modal .qr-modal-box {
      width: 11.25rem;
      height: 11.25rem;
      margin: 0 auto
    }

    .qr-modal .qr-modal-box svg {
      width: 100%;
      height: 100%;
      display: block
    }

    .qr-modal .qr-modal-code {
      margin-top: var(--sp-4);
      font-family: ui-monospace, Consolas, monospace;
      font-size: 1.75rem;
      font-weight: 700;
      letter-spacing: .12em;
      color: #1a1a1a
    }

    .qr-modal .qr-modal-url {
      margin-top: var(--sp-2);
      font-family: ui-monospace, Consolas, monospace;
      font-size: 0.6875rem;
      color: #8a8a8f;
      word-break: break-all
    }

    .sharenew .qr svg {
      width: 100%;
      height: 100%;
      display: block
    }

    .codecard-title {
      display: flex;
      align-items: baseline;
      flex-wrap: wrap;
      gap: var(--sp-1) var(--sp-2)
    }

    .codecard-title .code-main {
      font-size: 1.75rem;
      font-weight: 800;
      line-height: 1.2;
      color: var(--fg);
      letter-spacing: .08em;
      word-break: break-all
    }

    .codecard-title .codecard-label {
      font-size: 0.75rem;
      color: var(--muted)
    }

    /* 身分管理：投稿者／管理 PIN 並列 */
    .idgrid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(18rem, 1fr));
      gap: var(--sp-5);
      margin-top: var(--sp-2);
      align-items: start
    }

    .idgroup {
      min-width: 0
    }

    .invite {
      font-family: ui-monospace, Consolas, monospace;
      font-size: 0.75rem;
      color: var(--muted);
      word-break: break-all;
      margin-top: var(--sp-2);
      background: var(--bg);
      padding: var(--sp-2) var(--sp-3);
      border-radius: var(--r-sm);
      border: 1px solid var(--line)
    }

    .row {
      display: flex;
      gap: var(--sp-2);
      align-items: center;
      flex-wrap: wrap;
      margin-top: var(--sp-3)
    }

    .metaedit {
      display: contents
    }

    /* display:contents 之下 summary 直接落在父層流裡，沒有這行就會緊貼上一塊（投稿代碼列表／空狀態） */
    .metaedit>summary {
      list-style: none;
      margin-top: var(--sp-3)
    }

    .metaedit>summary::-webkit-details-marker {
      display: none
    }

    .metaform {
      display: flex;
      flex-direction: column;
      gap: var(--sp-3)
    }

    /* 編輯專案描述：原本用 <details> 內嵌展開，但 .projactions 是 flex 排版，
       表單的 flex-basis:100% 只會相對「按鈕列」換行，不是相對整列，導致跑版；改用原生 <dialog> 徹底脫離該排版脈絡 */
    .metadlg {
      border: 1px solid var(--line);
      border-radius: var(--r-lg);
      background: var(--card);
      color: var(--fg);
      box-shadow: var(--sh);
      padding: var(--sp-5) var(--sp-5);
      max-width: 30rem;
      width: calc(100% - 2.5rem)
    }

    .metadlg::backdrop {
      background: rgba(0, 0, 0, .5)
    }

    .metadlg h3 {
      margin: 0 0 var(--sp-3);
      font-size: 0.9375rem;
      display: flex;
      align-items: center;
      gap: var(--sp-2)
    }

    .dlgactions {
      display: flex;
      gap: var(--sp-2);
      justify-content: flex-end;
      margin-top: var(--sp-2)
    }

    .metaform label {
      display: flex;
      flex-direction: column;
      gap: var(--sp-1);
      font-size: 0.75rem;
      color: var(--muted)
    }

    .metaform input,
    .metaform select,
    .metaform textarea {
      border: 1px solid var(--line);
      border-radius: var(--r-sm);
      background: var(--bg);
      color: var(--fg);
      padding: var(--sp-2) var(--sp-3);
      font-size: 0.8125rem;
      line-height: 1.5;
      width: 100%;
      resize: vertical
    }

    .modfields {
      display: flex;
      flex-direction: column;
      gap: var(--sp-3);
      border: 1px solid var(--line);
      border-radius: var(--r-sm);
      padding: var(--sp-3)
    }

    .modfields-head {
      font-size: 0.75rem;
      color: var(--muted);
      font-weight: 600
    }

    /* 說明文字要緊跟著它解釋的那一欄；全域 .hint 的上緣留白在這種小方框裡會散掉 */
    .modfields .hint {
      margin-top: 0;
      font-size: 0.75rem;
      line-height: 1.6
    }

    .metaform label.modrow {
      flex-direction: row;
      align-items: flex-start;
      gap: var(--sp-2);
      font-size: 0.8125rem;
      color: var(--fg)
    }

    .modrow input[type="checkbox"] {
      width: 1rem;
      height: 1rem;
      margin-top: 0.25rem;
      flex: none
    }

    /* 圖層挑選器：一列＝一層，右側是上下移動。列的順序就是疊圖順序，所以整份清單要看起來
       像一疊東西——列與列之間用細線分隔，而不是散開的間距。 */
    .lylist {
      display: flex;
      flex-direction: column;
      border: 1px solid var(--line);
      border-radius: var(--r-sm);
      overflow: hidden
    }

    .lyrow {
      display: flex;
      align-items: center;
      gap: var(--sp-2);
      padding: var(--sp-2)
    }

    .lyrow+.lyrow {
      border-top: 1px solid var(--line)
    }

    .metaform label.lypick {
      flex: 1 1 auto;
      flex-direction: row;
      align-items: center;
      gap: var(--sp-2);
      min-width: 0;
      font-size: 0.8125rem;
      color: var(--fg);
      cursor: pointer
    }

    .lypick input[type="checkbox"] {
      width: 1rem;
      height: 1rem;
      flex: none
    }

    /* 封面預覽：固定 16:9，圖讀不到（尚未有封面）時 onerror 幫 .cov-preview 掛上 .cov-empty，
       改顯示置中圖示，不留一個破圖示的瀏覽器預設樣式。 */
    .cov-preview {
      position: relative;
      width: 100%;
      max-width: 320px;
      aspect-ratio: 16 / 9;
      border: 1px solid var(--line);
      border-radius: var(--r-sm);
      overflow: hidden;
      background: var(--bg);
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .cov-preview img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .cov-preview .cov-empty-icon {
      display: none;
      font-size: 1.75rem;
      color: var(--muted);
    }

    .cov-preview.cov-empty img {
      display: none;
    }

    .cov-preview.cov-empty .cov-empty-icon {
      display: block;
    }

    .lymove {
      display: flex;
      gap: 2px;
      flex: none
    }

    .lybtn {
      border: 1px solid var(--line);
      border-radius: var(--r-sm);
      background: var(--bg);
      color: var(--muted);
      cursor: pointer;
      padding: 2px 7px;
      font-size: 0.75rem;
      line-height: 1.4
    }

    .lybtn:hover {
      color: var(--fg)
    }

    /* 首列不能再往上、末列不能再往下：按鈕留在原位（版面不跳動）但明確表示按了沒用 */
    .lybtn:disabled {
      opacity: 0.3;
      cursor: default
    }

    /* 圖層列右側的操作區。按鈕在窄螢幕會被擠扁，所以整組 flex:none——寧可名稱那一欄先截斷，
       也不要「匯出」變成一顆按不到的細條。 */
    .lyacts {
      display: flex;
      gap: var(--sp-2);
      flex: none;
      align-items: center
    }

    .lyacts .btn {
      padding: var(--sp-1) var(--sp-3);
      min-height: 1.875rem;
      font-size: 0.75rem
    }

    /* 邊界四格與縮放兩格：成對的數字欄位並排比疊成一長條好對照，窄螢幕再退回單欄 */
    .lygrid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: var(--sp-2)
    }

    @media (max-width:24rem) {
      .lygrid {
        grid-template-columns: 1fr
      }
    }

    /* 刪除擺在設定對話框最底下，用一條分隔線跟「儲存／取消」隔開：它跟上面那些欄位不是同一類
       操作，不該看起來像表單的一部分。 */
    .lydelrow {
      display: flex;
      justify-content: flex-end;
      margin-top: var(--sp-4);
      padding-top: var(--sp-3);
      border-top: 1px solid var(--line)
    }

    .row input {
      border: 1px solid var(--line);
      border-radius: var(--r-sm);
      background: var(--bg);
      color: var(--fg);
      padding: var(--sp-2) var(--sp-3);
      font-size: 0.8125rem;
      line-height: 1.5;
      flex: 1 1 8.75rem;
      min-width: 7.5rem
    }

    .row input[type="datetime-local"] {
      flex-basis: 11.25rem
    }

    .row input[type="number"] {
      flex: 0 1 8.75rem
    }

    .row label.fieldlabel {
      display: flex;
      flex-direction: column;
      gap: var(--sp-1);
      font-size: 0.75rem;
      color: var(--muted);
      flex: 1 1 8.75rem;
      min-width: 7.5rem
    }

    .row label.fieldlabel input {
      width: 100%;
      flex: 1 1 auto
    }

    .pin-toggle-wrap {
      position: relative;
      display: inline-flex;
      width: 100%
    }

    .pin-toggle-wrap input {
      width: 100%;
      padding-right: 2.25rem
    }

    .pin-toggle-btn {
      position: absolute;
      right: var(--sp-1);
      top: 50%;
      transform: translateY(-50%);
      width: var(--tap);
      height: var(--tap);
      border: none;
      background: transparent;
      color: var(--muted);
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: var(--r-sm)
    }

    .pin-toggle-btn:hover {
      background: var(--line)
    }

    .expirywidget {
      display: flex;
      flex-direction: column;
      gap: var(--sp-2)
    }

    .expirychips {
      display: flex;
      gap: var(--sp-1);
      flex-wrap: wrap
    }

    .chip {
      display: inline-flex;
      align-items: center;
      min-height: var(--tap);
      border: 1px solid var(--line);
      border-radius: 999px;
      background: var(--card);
      color: var(--muted);
      font-size: 0.75rem;
      padding: 0 var(--sp-3);
      cursor: pointer
    }

    .chip:not(.on):hover {
      background: var(--bg);
      color: var(--fg)
    }

    .chip.on {
      background: var(--accent);
      color: var(--accent-fg);
      border-color: var(--accent)
    }

    .btn {
      border: 1px solid var(--line);
      border-radius: 999px;
      background: var(--card);
      color: var(--fg);
      font-size: 0.8125rem;
      font-weight: 600;
      padding: var(--sp-2) var(--sp-4);
      min-height: 2.25rem;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: var(--sp-2);
      text-decoration: none;
      white-space: nowrap
    }

    .btn:hover {
      background: var(--bg)
    }

    .btn.danger {
      color: var(--danger)
    }

    .btn.solid {
      background: var(--accent);
      color: var(--accent-fg);
      border-color: var(--accent)
    }

    .tabs {
      display: flex;
      gap: var(--sp-2);
      flex-wrap: wrap;
      margin: var(--sp-4) 0 var(--sp-1)
    }

    .tab {
      font-size: 0.8125rem;
      font-weight: 600;
      padding: var(--sp-2) var(--sp-4);
      min-height: 2.25rem;
      display: inline-flex;
      align-items: center;
      border-radius: 999px;
      border: 1px solid var(--line);
      background: var(--card);
      color: var(--fg);
      text-decoration: none
    }

    .tab.on {
      background: var(--accent);
      color: var(--accent-fg);
      border-color: var(--accent)
    }

    /* 只有可點的分頁才有 hover。原本寫 .tab:hover，跟 .tab.on 同權重又排在後面，
       滑過目前所在的「全部」分頁時只有底色被蓋回淺色、字色仍是反白，整顆字就消失了。 */
    .tab:not(.on):hover {
      background: var(--bg)
    }

    /* 專案卡片列：取代「所有專案」那排純文字分頁——項目一多會一路換行、頁面被拉得很長。
       預設橫向捲動（手機用手勢，桌機用捲輪/拖曳），旁邊給一顆展開鈕改成換行顯示全部。
       跟 .tabs／.tab（分頁動作、單一「返回」連結）分開一組類別，語意不同、不互相套用。 */
    .pcards-wrap {
      display: flex;
      align-items: center;
      gap: var(--sp-2);
      margin: var(--sp-4) 0 var(--sp-1)
    }

    .pcards {
      display: flex;
      gap: var(--sp-2);
      overflow-x: auto;
      scrollbar-width: thin;
      padding-bottom: 2px;
      scroll-snap-type: x proximity
    }

    .pcards.expanded {
      flex-wrap: wrap;
      overflow-x: visible
    }

    .pcard {
      flex: 0 0 auto;
      scroll-snap-align: start;
      font-size: 0.8125rem;
      font-weight: 600;
      padding: var(--sp-2) var(--sp-4);
      min-height: 2.25rem;
      display: inline-flex;
      align-items: center;
      gap: var(--sp-2);
      border-radius: var(--r-md);
      border: 1px solid var(--line);
      background: var(--card);
      color: var(--fg);
      text-decoration: none;
      white-space: nowrap
    }

    .pcard.on {
      background: var(--accent);
      color: var(--accent-fg);
      border-color: var(--accent)
    }

    .pcard:not(.on):hover {
      background: var(--bg)
    }

    .pcards-toggle {
      flex: 0 0 auto;
      width: 2.25rem;
      height: 2.25rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: var(--r-md);
      border: 1px solid var(--line);
      background: var(--card);
      color: var(--fg);
      cursor: pointer
    }

    .pcards-toggle:hover {
      background: var(--bg)
    }

    .pcards-toggle i {
      transition: transform .15s
    }

    .pcards-toggle.on i {
      transform: rotate(180deg)
    }

    .stat-card {
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: var(--r-md);
      padding: var(--sp-4);
      margin-bottom: var(--sp-3)
    }

    .stat-card-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: var(--sp-3);
      margin-bottom: var(--sp-3)
    }

    .stat-card-head b {
      font-size: 0.8125rem
    }

    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(8.125rem, 1fr));
      gap: var(--sp-3)
    }

    .tile {
      background: var(--bg);
      border: 1px solid var(--line);
      border-radius: var(--r-md);
      padding: var(--sp-3) var(--sp-4)
    }

    .tile .n {
      font-size: 1.625rem;
      line-height: 1.2;
      font-weight: 800
    }

    .tile .l {
      font-size: 0.75rem;
      color: var(--muted)
    }

    /* 每格數字底下自己的一句話解釋，取代原本另開一張「這些數字的意思」卡 */
    .tile .d {
      font-size: 0.75rem;
      color: var(--muted);
      margin-top: var(--sp-1);
      padding-top: var(--sp-1);
      border-top: 1px solid var(--line)
    }

    .break {
      font-size: 0.75rem;
      color: var(--muted);
      margin-top: var(--sp-3)
    }

    .break b {
      color: var(--fg)
    }

    .stat-card .cols {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
      /* 不設 align-items 的話各欄會被拉成等高，只有一行字的那欄下面會空一大片 */
      align-items: start;
      gap: var(--sp-3);
      margin-top: var(--sp-4);
      padding-top: var(--sp-4);
      border-top: 1px dashed var(--line)
    }

    .stat-card .col {
      background: var(--bg);
      border: 1px solid var(--line);
      border-radius: var(--r-md);
      padding: var(--sp-3) var(--sp-4);
      font-size: 0.75rem
    }

    .stat-card .col h4 {
      margin: 0 0 var(--sp-1);
      font-size: 0.75rem
    }

    /* 排行欄位的副標：講清楚這一欄的數字是什麼，不必回頭查另一張卡 */
    .stat-card .col .colnote {
      color: var(--muted);
      margin: 0 0 var(--sp-2)
    }

    .stat-card .col ol {
      margin: 0;
      padding-left: 1.25rem;
      max-height: 15rem;
      overflow-y: auto
    }

    .stat-card .col p {
      margin: var(--sp-1) 0;
      color: var(--muted)
    }

    /* 統計圖表：純 CSS 長條，長度在 PHP 端就算好，不引圖表庫也不用 JS
       （這頁本來就不供靜態檔，為了幾張圖多一個相依不划算）。
       條本身是裝飾（aria-hidden），每一列旁邊都有文字標籤與數值，讀螢幕的人拿到的資訊一樣。 */
    .stat-card .col .statbars {
      list-style: none;
      margin: 0;
      padding: 0;
      max-height: 15rem;
      overflow-y: auto
    }

    .stat-card .col .statbars li {
      display: grid;
      grid-template-columns: minmax(3.25rem, 6rem) 1fr auto;
      align-items: center;
      gap: var(--sp-2);
      padding: 0.1875rem 0
    }

    .stat-card .col .statbars .lbl {
      color: var(--muted);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis
    }

    .stat-card .col .statbars .trk {
      height: 0.5rem;
      border-radius: 999px;
      background: var(--line);
      overflow: hidden
    }

    .stat-card .col .statbars .fil {
      display: block;
      height: 100%;
      border-radius: 999px;
      background: var(--accent)
    }

    .stat-card .col .statbars .val {
      font-weight: 700;
      font-variant-numeric: tabular-nums
    }

    .stat-card .col .statsub {
      color: var(--muted);
      margin: var(--sp-3) 0 var(--sp-1)
    }

    .stat-card .col .statsub:first-of-type {
      margin-top: 0
    }

    /* 直式分佈（時段／星期）：沒有資料的格子也要留位置，才看得出一整天的形狀 */
    .statcols {
      display: flex;
      align-items: flex-end;
      gap: 0.125rem;
      height: 4.5rem;
      margin-top: var(--sp-2)
    }

    .statcols .cc {
      flex: 1;
      display: flex;
      align-items: flex-end;
      height: 100%
    }

    .statcols .cc i {
      display: block;
      width: 100%;
      background: var(--accent);
      border-radius: 0.125rem 0.125rem 0 0
    }

    .statcols .cc.zero i {
      background: var(--line)
    }

    .statcols-axis {
      display: flex;
      gap: 0.125rem;
      margin-top: var(--sp-1);
      font-size: 0.6875rem;
      color: var(--muted)
    }

    .statcols-axis span {
      flex: 1;
      text-align: center;
      white-space: nowrap
    }

    /* 同一欄裡上下兩張圖（時段、星期）之間要留白，不然看起來像同一張 */
    .statcols-axis+.statcols {
      margin-top: var(--sp-4)
    }

    /* 兩類佔比（手機／桌機）：一條堆疊條，顏色對應上面兩個數字 */
    .statdev .b {
      color: var(--muted)
    }

    .statdev .sep {
      color: var(--muted);
      font-weight: 400;
      margin: 0 0.125rem
    }

    .statratio {
      display: flex;
      height: 0.375rem;
      margin-top: var(--sp-2);
      border-radius: 999px;
      overflow: hidden;
      background: var(--line)
    }

    .statratio span {
      display: block;
      height: 100%
    }

    .statratio .a {
      background: var(--accent)
    }

    .statratio .b {
      background: var(--muted)
    }

    /* 時段圖有 24 格，擠在一欄看不出形狀，寬螢幕給它兩欄 */
    @media (min-width: 40rem) {
      .stat-card .col.wide {
        grid-column: span 2
      }
    }

    .tablewrap {
      overflow-x: auto;
      border: 1px solid var(--line);
      border-radius: var(--r-lg);
      background: var(--card)
    }

    table {
      border-collapse: collapse;
      width: 100%;
      font-size: 0.8125rem;
      min-width: 51.25rem
    }

    th {
      background: var(--bg);
      position: sticky;
      top: 0;
      text-align: left;
      font-weight: 700;
      color: var(--muted);
      font-size: 0.75rem;
      letter-spacing: .04em;
      text-transform: uppercase;
      white-space: nowrap
    }

    th,
    td {
      padding: var(--sp-2) var(--sp-3);
      border-bottom: 1px solid var(--line);
      vertical-align: top
    }

    tr:hover td {
      background: var(--bg)
    }

    td img {
      width: 5rem;
      height: 5rem;
      object-fit: cover;
      border-radius: var(--r-sm);
      display: block
    }

    .mono {
      font-family: ui-monospace, Consolas, monospace;
      font-size: 0.75rem;
      color: var(--muted)
    }

    /* 同一格可能有兩個標籤（照片＋編修），用 flex 給列距，不要靠 <br> 疊在一起 */
    .tagcell {
      display: inline-flex;
      flex-wrap: wrap;
      gap: var(--sp-1)
    }

    .tag {
      display: inline-block;
      font-size: 0.75rem;
      font-weight: 600;
      padding: 0 var(--sp-2);
      border-radius: 999px;
      background: var(--bg);
      border: 1px solid var(--line);
      /* 表格「類型」欄被壓窄時，沒有這行中文標籤會一個字一行直排 */
      white-space: nowrap
    }

    .hint {
      font-size: 0.75rem;
      color: var(--muted);
      margin-top: var(--sp-2)
    }

    /* 圖示鈕與 .btn 用同一種外框：同一排按鈕不能有的有框、有的沒框 */
    .iconbtn {
      background: var(--card);
      border: 1px solid var(--line);
      color: var(--danger);
      cursor: pointer;
      font-size: 0.9375rem;
      width: var(--tap);
      height: var(--tap);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 999px
    }

    .iconbtn:hover {
      border-color: var(--danger);
      background: var(--line)
    }

    .badge {
      font-size: 0.75rem;
      color: var(--muted)
    }

    .pinlist {
      display: flex;
      gap: var(--sp-2);
      flex-wrap: wrap;
      align-items: flex-start;
      margin-top: var(--sp-2)
    }

    .pinchip {
      display: inline-flex;
      align-items: center;
      gap: var(--sp-2);
      font-size: 0.75rem;
      background: var(--bg);
      border: 1px solid var(--line);
      border-radius: 999px;
      padding: var(--sp-1) var(--sp-1) var(--sp-1) var(--sp-3)
    }

    .pinchip form {
      display: inline-flex;
      margin: 0
    }

    /* 移除／撤銷。原本 17×16px，遠低於 WCAG 2.2 SC 2.5.8 的 24×24 下限 */
    .x {
      background: var(--card);
      border: 1px solid var(--line);
      color: var(--danger);
      cursor: pointer;
      font-size: 1rem;
      line-height: 1;
      width: var(--tap);
      height: var(--tap);
      flex: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 999px;
      padding: 0
    }

    .x:hover {
      border-color: var(--danger);
      background: var(--line)
    }

    .pinchip-block {
      flex-direction: column;
      align-items: stretch;
      border-radius: var(--r-md);
      padding: var(--sp-3);
      gap: var(--sp-2)
    }

    /* 身分那一行：文字會換行，右邊的移除鈕要固定在同一行的開頭而不是被文字推走 */
    .pinchip-block .idline {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: var(--sp-2)
    }

    .pinchip-block .idline>span:first-child {
      min-width: 0;
      word-break: break-word
    }

    /* 那一行右側的操作鈕（QR／複製／移除）自成一組，不會被 space-between 拆開分散 */
    .idacts {
      display: inline-flex;
      align-items: center;
      gap: var(--sp-1);
      flex: none
    }

    .idacts form {
      display: inline-flex;
      margin: 0
    }

    .pinchip-block.blocked {
      border-color: var(--danger)
    }

    .permrow {
      display: flex;
      gap: var(--sp-2);
      flex-wrap: wrap;
      margin-top: var(--sp-1)
    }

    .permtoggle {
      display: inline-flex;
      align-items: center;
      gap: var(--sp-1);
      min-height: var(--tap);
      border: 1px solid var(--line);
      border-radius: 999px;
      background: var(--card);
      color: var(--muted);
      font-size: 0.75rem;
      padding: 0 var(--sp-3);
      cursor: pointer
    }

    .permtoggle:not(.on):hover {
      background: var(--bg);
      color: var(--fg)
    }

    .permtoggle.on {
      background: var(--accent);
      color: var(--accent-fg);
      border-color: var(--accent)
    }

    /* 存取與權限：投稿代碼卡片 grid + 身分管理（投稿者／管理PIN）grid，見上方 .code-grid / .idgrid */
    /* 用 block 不用 flex：flex 之下「投稿代碼」這種短標題會被後面的補充說明擠成一字一行 */
    .sechead {
      display: block;
      font-size: 0.8125rem;
      font-weight: 700;
      margin: var(--sp-5) 0 var(--sp-2)
    }

    /* 區塊標題本來就自帶上緣留白，接在專案標題後的第一個不用再加一次 */
    .projhead+.sechead,
    .idgroup>.sechead:first-child {
      margin-top: 0
    }

    .sechead .fa-solid {
      color: var(--accent);
      margin-right: var(--sp-2)
    }

    .sechead .sechint {
      margin-left: var(--sp-2);
      font-weight: 400;
      font-size: 0.75rem;
      color: var(--muted)
    }

    .projhead {
      display: flex;
      align-items: center;
      gap: var(--sp-3);
      flex-wrap: wrap;
      margin: var(--sp-6) 0 var(--sp-3)
    }

    .projactions {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      flex: 1 1 15rem;
      gap: var(--sp-2);
      flex-wrap: wrap
    }

    .projhead .projtitle {
      font-size: 0.9375rem;
      font-weight: 700
    }

    /* 剛建立的分享連結（accent 框）：QR＋連結並排 */
    .sharenew {
      padding: var(--sp-3) var(--sp-4);
      margin-top: var(--sp-3);
      border-color: var(--accent)
    }

    .sharenew-body {
      display: flex;
      gap: var(--sp-3);
      align-items: flex-start;
      flex-wrap: wrap;
      margin-top: var(--sp-2)
    }

    .sharenew-info {
      flex: 1;
      min-width: 14rem
    }

    .sharenew-info .invite {
      margin-top: 0
    }

    /* pinchip 內的小型連結按鈕（複製邀請連結等）。原本 18×14px，點不到 */
    .chipbtn {
      background: var(--card);
      border: 1px solid var(--line);
      color: var(--accent);
      cursor: pointer;
      font-size: 0.75rem;
      width: var(--tap);
      height: var(--tap);
      flex: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 999px;
      padding: 0
    }

    .chipbtn:hover {
      border-color: var(--accent);
      background: var(--line)
    }

    /* 鍵盤操作看得見焦點：所有可點元素統一一個外框 */
    a:focus-visible,
    button:focus-visible,
    summary:focus-visible,
    input:focus-visible,
    select:focus-visible,
    textarea:focus-visible {
      outline: 2px solid var(--accent);
      outline-offset: 2px
    }

    /* ── 專案總覽（scope=全部）：一張地圖一張精簡卡，細節請點進去 ── */
    .ov-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(13.75rem, 1fr));
      gap: var(--sp-3)
    }

    .ovcard {
      padding: var(--sp-3) var(--sp-4);
      display: flex;
      flex-direction: column;
      gap: var(--sp-2);
      text-decoration: none;
      color: inherit
    }

    .ovcard:hover {
      border-color: var(--fg)
    }

    .ovcard .t {
      font-weight: 700;
      font-size: 0.9375rem
    }

    .ovcard .n {
      font-size: 0.75rem;
      color: var(--muted)
    }

    .ovcard .stat-row {
      display: flex;
      gap: var(--sp-3);
      font-size: 0.75rem;
      color: var(--muted)
    }

    .ovcard .stat-row b {
      color: var(--fg);
      font-size: 0.9375rem;
      display: block
    }

    /* ── 單一專案工作區：頂端子導覽（分頁），一次只顯示一塊，減少「散落」感 ── */
    .subnav {
      display: flex;
      gap: var(--sp-1);
      flex-wrap: wrap;
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: 999px;
      padding: var(--sp-1);
      margin: var(--sp-4) 0
    }

    .subtab {
      border: none;
      background: none;
      color: var(--muted);
      font-size: 0.8125rem;
      font-weight: 600;
      padding: var(--sp-2) var(--sp-4);
      min-height: 2.25rem;
      border-radius: 999px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: var(--sp-2);
      white-space: nowrap
    }

    .subtab:not(.on):hover {
      background: var(--bg);
      color: var(--fg)
    }

    .subtab.on {
      background: var(--accent);
      color: var(--accent-fg)
    }

    .pane {
      display: none
    }

    .pane.on {
      display: block
    }

    .section-card {
      padding: var(--sp-5)
    }

    .section-card+.section-card {
      margin-top: var(--sp-3)
    }

    .modrow {
      display: flex;
      align-items: flex-start;
      gap: var(--sp-2)
    }

    .setrow {
      display: block;
      margin-top: var(--sp-3)
    }

    .setrow>b {
      display: block;
      margin-bottom: var(--sp-1)
    }

    /* 下拉選單跟輸入框長一樣，不留一顆瀏覽器原生樣式的白框 */
    .section-card select {
      border: 1px solid var(--line);
      border-radius: var(--r-sm);
      background: var(--bg);
      color: var(--fg);
      padding: var(--sp-2) var(--sp-3);
      font-size: 0.8125rem;
      min-height: 2.25rem;
      max-width: 100%
    }

    .danger-zone {
      border-color: var(--danger)
    }

    .searchbar {
      display: flex;
      align-items: center;
      gap: var(--sp-2);
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: 999px;
      padding: var(--sp-2) var(--sp-4);
      margin-bottom: var(--sp-3);
      color: var(--muted)
    }

    .searchbar input {
      border: none;
      background: none;
      color: var(--fg);
      font-size: 0.8125rem;
      outline: none;
      flex: 1;
      min-height: var(--tap)
    }

    /* 空狀態：原本是一行置中文字浮在空白裡，看起來像版面破了。給它虛線框，
       明確表示「這一格現在是空的」，並把下一步的按鈕就放在框裡。 */
    .emptystate {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: var(--sp-2);
      padding: var(--sp-4);
      margin-top: var(--sp-2);
      border: 1px dashed var(--line);
      border-radius: var(--r-md);
      text-align: center;
      color: var(--muted);
      font-size: 0.8125rem
    }
  </style>
</head>

<body>
  <div class="wrap">
    <?php $langUrl = fn(string $l) => $esc(Route::manager($scopeProject, $reqPane) . '?lang=' . $l); ?>
    <div class="langsw">
      <a href="<?= $langUrl('zh_TW') ?>" class="<?= $LANG === 'zh_TW' ? 'on' : '' ?>">中文</a>
      <a href="<?= $langUrl('en') ?>" class="<?= $LANG === 'en' ? 'on' : '' ?>">English</a>
    </div>
    <div class="top">
      <?php
        // 主標就寫「現在在哪」：總覽是所有專案，進到專案就是那個專案的名稱。
        // 站台名稱留在瀏覽器分頁標題即可，佔著 h1 只會讓人看不出目前位置。
        $headMeta = [];
        if ($scopeProject !== '') {
          $hm = json_decode((string)@file_get_contents($cfg['projects_dir'] . '/' . $scopeProject . '/meta.json'), true);
          if (is_array($hm)) $headMeta = $hm;
        }
        $headName = $scopeProject !== ''
          ? ((string)($headMeta['title'] ?? '') !== '' ? (string)$headMeta['title'] : $scopeProject)
          : $t('all_projects_heading');
      ?>
      <h1><i class="fa-solid <?= $scopeProject !== '' ? 'fa-map-location-dot' : 'fa-layer-group' ?>"></i> <?= $esc($headName) ?> <span class="sub"><?php if ($scopeProject !== '' && $headName !== $scopeProject): ?><span class="mono"><?= $esc($scopeProject) ?></span> · <?php endif; ?><?= $primary ? $t('primary_admin_label') : $t('project_admin_label') ?> · <?= $t('records_count_suffix', ['n' => count($rows)]) ?></span></h1>
      <?php
        // 回到公開網站。有指定專案就直接回那張地圖，沒有（主要管理者的總覽）就回平台首頁。
        // 走 Route 而不是寫相對連結：後台網址是 /manager/<mapid>/<pane> 這種深路徑，相對連結會被
        // index.php 的路由當成「同一張地圖底下的動作」而回不去。
        $frontUrl = $mapUrl($scopeProject);
      ?>
      <a class="btn" href="<?= $esc($frontUrl) ?>"><i class="fa-solid fa-arrow-left"></i> <?= $t($scopeProject !== '' ? 'back_to_map_btn' : 'back_to_site_btn') ?></a>
      <a class="btn" href="<?= $esc(Route::logout()) ?>"><i class="fa-solid fa-right-from-bracket"></i> <?= $t('logout_btn') ?></a>
    </div>

    <?php if (!is_writable($cfg['projects_dir'])): ?>
      <div class="card" style="border-color:var(--danger);color:var(--danger);padding:14px 18px;margin-top:12px;font-size:.85rem;line-height:1.7">
        <b><i class="fa-solid fa-triangle-exclamation"></i> <?= $t('dir_not_writable_title', ['dir' => $cfg['projects_dir']]) ?></b><br>
        <?= $tr('dir_not_writable_detail') ?>
      </div>
    <?php endif; ?>

    <?php if ($primary && $scopeProject === ''): ?>
      <div class="pcards-wrap">
        <div class="pcards" id="pcards-projects">
          <a class="pcard on"><?= $t('tab_all_count', ['n' => count($allProjects)]) ?></a>
          <?php foreach ($allProjects as $tp): ?><a class="pcard" href="<?= $esc(Route::manager($tp)) ?>"><?= $esc($tp) ?></a><?php endforeach; ?>
        </div>
        <button type="button" class="pcards-toggle" data-target="pcards-projects" aria-expanded="false" title="<?= $t('pcards_expand_btn') ?>"><i class="fa-solid fa-chevron-down"></i></button>
      </div>
    <?php elseif ($primary): ?>
      <div class="tabs">
        <a class="tab" href="<?= $esc(Route::manager()) ?>"><i class="fa-solid fa-arrow-left"></i> <?= $t('back_to_all_projects') ?></a>
      </div>
    <?php elseif ($isAcct && count($acctProjects) > 1 && $scopeProject === ''): ?>
      <div class="pcards-wrap">
        <div class="pcards" id="pcards-projects">
          <a class="pcard on"><?= $t('tab_all_count', ['n' => count($acctProjects)]) ?></a>
          <?php foreach ($acctProjects as $tp): ?><a class="pcard" href="<?= $esc(Route::manager($tp)) ?>"><?= $esc($tp) ?></a><?php endforeach; ?>
        </div>
        <button type="button" class="pcards-toggle" data-target="pcards-projects" aria-expanded="false" title="<?= $t('pcards_expand_btn') ?>"><i class="fa-solid fa-chevron-down"></i></button>
      </div>
    <?php elseif ($isAcct && count($acctProjects) > 1): ?>
      <div class="tabs">
        <a class="tab" href="<?= $esc(Route::manager()) ?>"><i class="fa-solid fa-arrow-left"></i> <?= $t('back_to_all_my_projects') ?></a>
      </div>
    <?php endif; ?>

    <?php
      // 表單送出後回到對應分頁，避免每次都跳回總覽：多數動作走 redirect，目的地已經帶好 #pane 片段（見上方各 header('Location: …#…')）；
      // 只有 sharelink 例外——它不 redirect、直接在同一次回應內把頁面畫出來，此時網址列還沒有片段，得靠這裡補上
      // 送出分享／邀請表單後強制跳到「權限與碼」，否則就照網址（Route::parseManager 拆出的 $reqPane）
      $forcePane = in_array(($_POST['action'] ?? ''), ['sharelink', 'migrate_create'], true) ? 'access' : $reqPane;
    ?>
    <div class="subnav">
      <button type="button" class="subtab on" data-pane="overview"><i class="fa-solid fa-chart-simple"></i> <?= $t('overview_tab') ?></button>
      <button type="button" class="subtab" data-pane="records"><i class="fa-solid fa-table-list"></i> <?= $t('records_tab_count', ['n' => count($rows)]) ?></button>
      <button type="button" class="subtab" data-pane="access"><i class="fa-solid fa-key"></i> <?= $t('access_tab') ?></button>
      <?php if ($sitewideOnly): ?><button type="button" class="subtab" data-pane="tools"><i class="fa-solid fa-screwdriver-wrench"></i> <?= $t('tools_tab') ?></button><?php endif; ?>
    </div>

    <div class="pane" id="pane-access">
    <?php if ($sitewideOnly): $mpins = pins_load($cfg)['primary']; ?>
      <h2><?= $t('primary_pins_heading') ?></h2>
      <div class="hint" style="margin:-6px 0 12px"><?= $t('primary_pins_hint') ?></div>
      <div class="card" style="padding:16px 18px">
        <div class="pinlist">
          <span class="pinchip"><?= $t('primary_config_label') ?> · <span class="mono">config</span>
            <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="migrate_create"><input type="hidden" name="source" value="bootstrap"><input type="hidden" name="label" value="<?= $esc($cfg['admin_pin_label'] ?? '') ?>"><button type="submit" class="chipbtn" title="<?= $t('migrate_to_account_title') ?>"><i class="fa-solid fa-right-left"></i></button></form>
          </span>
          <?php foreach ($mpins as $e): ?><span class="pinchip"><?= $esc(($e['label'] ?? '') !== '' ? $e['label'] : $t('no_nickname_label')) ?> · <span class="mono">••••••</span>
              <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="migrate_create"><input type="hidden" name="source" value="primary"><input type="hidden" name="legacy_id" value="<?= $esc($e['id'] ?? '') ?>"><input type="hidden" name="label" value="<?= $esc($e['label'] ?? '') ?>"><button type="submit" class="chipbtn" title="<?= $t('migrate_to_account_title') ?>"><i class="fa-solid fa-right-left"></i></button></form>
              <form method="post"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="delpin"><input type="hidden" name="scope" value="primary"><input type="hidden" name="pin_id" value="<?= $esc($e['id'] ?? '') ?>"><button class="x" title="<?= $t('remove_title') ?>">×</button></form>
            </span>
          <?php endforeach; ?>
        </div>
        <form class="row" method="post" style="margin-top:10px"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="addpin"><input type="hidden" name="scope" value="primary">
          <input name="pin_new" placeholder="<?= $t('add_primary_pin_placeholder') ?>" autocomplete="off"><input name="label" placeholder="<?= $t('nickname_optional_placeholder') ?>" autocomplete="off"><button class="btn"><?= $t('add_btn') ?></button>
        </form>
      </div>
      <?php if ($justCreatedMigrate && $justCreatedMigrate['project'] === null): ?>
        <div class="card sharenew">
          <div class="badge"><i class="fa-solid fa-circle-check"></i> <?= $t('migrate_link_created_badge') ?><?= $t('share_created_once_note') ?></div>
          <div class="sharenew-body">
            <div class="qr" data-url="<?= $esc($justCreatedMigrate['url']) ?>" data-title="<?= $t('migrate_to_account_title') ?>"></div>
            <div class="sharenew-info">
              <div class="invite"><?= $esc($justCreatedMigrate['url']) ?></div>
              <div class="row"><button type="button" class="btn" data-copy="<?= $esc($justCreatedMigrate['url']) ?>"><i class="fa-solid fa-copy"></i> <?= $t('copy_link') ?></button></div>
              <div class="hint" style="margin-top:6px"><?= $t('migrate_link_hint') ?></div>
            </div>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <?php
      // 容量快取（state/storage.json）跟千位元組格式化函式：$layerAdminRow、$packAdminRow、
      // pane-overview 的專案卡片三處共用，只在這裡讀一次、算一次，不要各自重複讀檔。
      // $storageCache 是 null 代表從來沒按過「重新計算容量」，畫面上要顯示「還沒算過」而不是 0。
      $storageCache = souliong_storage_cache($cfg);
      // 真的是 0 bytes 就老實顯示 0 KB；max(1,...) 只用來避免「有檔案但不到 1KB」被四捨五入成看起來像沒東西的 0 KB。
      $fmtBytes = fn(int $b): string => $b <= 0 ? '0 KB' : ($b >= 1048576
        ? number_format($b / 1048576, 1) . ' MB'
        : max(1, (int)round($b / 1024)) . ' KB');
    ?>
    <?php
      // 圖層列右側的「設定」「匯出」兩顆按鈕，以及設定對話框（刪除在對話框最底下）。專案的圖層對話框與工具分頁的
      // 全站圖層清單長得不一樣，但「能對一個圖層做什麼」該是同一組，所以只寫這一份。
      // $lp === '' 代表全站層，非空代表某張地圖自己的層——差別只在多帶一個 project 參數，
      // 權限與路徑解析都由後端的 $layerTarget 決定，不靠前端少畫一顆按鈕來把關。
      $layerAdminRow = function (string $lid, array $linfo, string $lp) use ($t, $esc, $esc_csrf, $DICT, $cfg, $storageCache, $fmtBytes) {
        $dlgId = 'lyedit-' . ($lp !== '' ? $lp : 'site') . '-' . $lid;
        // 留著原稿的那幾層可以直接回切磚工具改，不必把來源檔再找出來一次。只有專案層有原稿
        // （全站層進版控，數十 MB 的手稿本來就不該塞進 repo）。
        $reedit = $lp !== '' && souliong_layersrc_editable($cfg, $lp, $lid)
          ? Route::tool('tilecut', $lp, ['load' => $lid])
          : '';
        // 原稿佔多少空間，順便決定要不要多顯示一顆「含原稿」匯出——沒有原稿的話那顆按鈕
        // 匯出的內容會跟旁邊那顆完全一樣，不如不顯示。
        $srcBytes = $lp !== '' ? souliong_layersrc_bytes(souliong_layersrc_dir($cfg, $lp, $lid)) : 0;
        // 這一層總容量（圖磚＋原稿），跟上面 $srcBytes 是兩件事：這裡有快取才顯示，沒算過就不畫。
        $totalBytes = $storageCache['layers'][$lp][$lid] ?? null;
        $bnd = (array)($linfo['bounds'] ?? []);
        $bv = fn(int $i, int $j) => isset($bnd[$i][$j]) && is_numeric($bnd[$i][$j]) ? (string)$bnd[$i][$j] : '';
        $zv = fn(string $k) => isset($linfo[$k]) && is_numeric($linfo[$k]) ? (string)(int)$linfo[$k] : '';
        $curPane = (string)($linfo['pane'] ?? 'art');
        $delMsg = i18n_t($DICT, 'layer_delete_confirm', ['id' => $lid])
          . ($reedit !== '' ? ' ' . i18n_t($DICT, 'layer_delete_src_note') : '');
    ?>
      <span class="lyacts">
        <?php if ($reedit !== ''): ?>
        <a class="btn" href="<?= $esc($reedit) ?>" title="<?= $t('layer_reedit_title') ?>"><i class="fa-solid fa-rotate-left"></i> <?= $t('layer_reedit_btn') ?></a>
        <?php endif; ?>
        <button type="button" class="btn" onclick="document.getElementById('<?= $esc($dlgId) ?>').showModal()"><i class="fa-solid fa-sliders"></i> <?= $t('layer_edit_btn') ?></button>
        <a class="btn" href="<?= $esc(Route::backupLayer($lid, $lp)) ?>" title="<?= $t('pack_export_btn') ?>" aria-label="<?= $t('pack_export_btn') ?>"><i class="fa-solid fa-download"></i></a>
        <?php if ($srcBytes > 0): ?>
        <a class="btn" href="<?= $esc(Route::backupLayer($lid, $lp, true)) ?>" title="<?= $t('layer_export_src_title') ?>" aria-label="<?= $t('layer_export_src_title') ?>"><i class="fa-solid fa-file-zipper"></i></a>
        <span class="hint mono" title="<?= $t('layer_src_size_title') ?>"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> <?= $esc($fmtBytes($srcBytes)) ?></span>
        <?php endif; ?>
        <?php if ($totalBytes !== null): ?>
        <span class="hint mono" title="<?= $t('layer_total_size_title') ?>"><i class="fa-solid fa-hard-drive" aria-hidden="true"></i> <?= $esc($fmtBytes($totalBytes)) ?></span>
        <?php endif; ?>
      </span>
      <dialog id="<?= $esc($dlgId) ?>" class="metadlg" onclick="if(event.target===this)this.close()">
        <form method="post" class="metaform">
          <input type="hidden" name="csrf" value="<?= $esc_csrf ?>">
          <input type="hidden" name="action" value="layeredit">
          <input type="hidden" name="layer" value="<?= $esc($lid) ?>">
          <?php if ($lp !== ''): ?><input type="hidden" name="project" value="<?= $esc($lp) ?>"><?php endif; ?>
          <h3><i class="fa-solid fa-sliders"></i> <?= $t('layer_edit_heading', ['id' => $lid]) ?></h3>
          <div class="hint"><?= $t('layer_edit_hint') ?></div>
          <label><?= $t('tilecut_label_label') ?>
            <input name="label" maxlength="60" value="<?= $esc($linfo['label'] ?? $lid) ?>"></label>
          <label><?= $t('tilecut_pane_label') ?>
            <select name="pane">
              <?php foreach (['art', 'road', 'paper', 'base'] as $pn): ?>
              <option value="<?= $pn ?>" <?= $curPane === $pn ? 'selected' : '' ?>><?= $t('tilecut_pane_' . $pn) ?></option>
              <?php endforeach; ?>
            </select></label>
          <label><?= $t('tilecut_opacity_label') ?>
            <input type="number" name="opacity" min="0" max="1" step="any" value="<?= $esc(isset($linfo['opacity']) && is_numeric($linfo['opacity']) ? (string)$linfo['opacity'] : '1') ?>"></label>
          <label><?= $t('tilecut_attr_label') ?>
            <input name="attribution" maxlength="500" value="<?= $esc($linfo['attribution'] ?? '') ?>"></label>
          <div class="modfields">
            <div class="modfields-head"><?= $t('layer_bounds_heading') ?></div>
            <div class="hint"><?= $t('layer_bounds_hint') ?></div>
            <div class="lygrid">
              <label><?= $t('tilecut_north') ?><input type="number" step="any" name="north" value="<?= $esc($bv(1, 0)) ?>"></label>
              <label><?= $t('tilecut_south') ?><input type="number" step="any" name="south" value="<?= $esc($bv(0, 0)) ?>"></label>
              <label><?= $t('tilecut_west') ?><input type="number" step="any" name="west" value="<?= $esc($bv(0, 1)) ?>"></label>
              <label><?= $t('tilecut_east') ?><input type="number" step="any" name="east" value="<?= $esc($bv(1, 1)) ?>"></label>
            </div>
          </div>
          <div class="modfields">
            <div class="modfields-head"><?= $t('layer_zoom_heading') ?></div>
            <div class="hint"><?= $t('layer_zoom_hint') ?></div>
            <div class="lygrid">
              <label><?= $t('tilecut_zoom_min') ?><input type="number" min="0" max="24" step="1" name="minZoom" value="<?= $esc($zv('minZoom')) ?>"></label>
              <label><?= $t('tilecut_zoom_max') ?><input type="number" min="0" max="24" step="1" name="maxZoom" value="<?= $esc($zv('maxZoom')) ?>"></label>
            </div>
          </div>
          <div class="dlgactions">
            <button type="button" class="btn" onclick="this.closest('dialog').close()"><?= $t('cancel') ?></button>
            <button class="btn solid"><i class="fa-solid fa-floppy-disk"></i> <?= $t('save_settings_btn') ?></button>
          </div>
        </form>
        <?php // 刪除自成一個 form（form 不能互相巢狀），擺在對話框最底下：要刪得先打開設定，順手誤觸不了 ?>
        <form method="post" class="lydelrow" onsubmit="return confirm(<?= $esc(json_encode($delMsg, JSON_UNESCAPED_UNICODE)) ?>)">
          <input type="hidden" name="csrf" value="<?= $esc_csrf ?>">
          <input type="hidden" name="action" value="layerdelete">
          <input type="hidden" name="layer" value="<?= $esc($lid) ?>">
          <?php if ($lp !== ''): ?><input type="hidden" name="project" value="<?= $esc($lp) ?>"><?php endif; ?>
          <button class="btn danger"><i class="fa-solid fa-trash-can"></i> <?= $t('layer_delete_btn') ?></button>
        </form>
      </dialog>
    <?php }; ?>
    <?php
      // 主題包列右側的「匯出」「刪除」兩顆按鈕。$pp === '' 代表全站包，非空代表某張地圖自己
      // 的包——跟 $layerAdminRow 一樣，差別只在多帶一個 project 參數，權限與路徑解析都由
      // 後端的 action=packdelete 決定。沒有設定對話框（材質變數目前只靠 pack.css 本身調），
      // 所以不像圖層另開一層 dialog，直接把兩顆按鈕放進清單列。
      $packAdminRow = function (string $pid, string $pp) use ($t, $esc, $esc_csrf, $DICT, $storageCache, $fmtBytes) {
        $delMsg = i18n_t($DICT, 'pack_delete_confirm', ['id' => $pid]);
        $pBytes = $storageCache['packs'][$pp][$pid] ?? null;
    ?>
      <span class="lyacts">
        <a class="btn" href="<?= $esc(Route::backupPack($pid, $pp)) ?>" title="<?= $t('pack_export_btn') ?>" aria-label="<?= $t('pack_export_btn') ?>"><i class="fa-solid fa-download"></i></a>
        <?php if ($pBytes !== null): ?>
        <span class="hint mono" title="<?= $t('pack_size_title') ?>"><i class="fa-solid fa-hard-drive" aria-hidden="true"></i> <?= $esc($fmtBytes($pBytes)) ?></span>
        <?php endif; ?>
        <form method="post" style="margin:0" onsubmit="return confirm(<?= $esc(json_encode($delMsg, JSON_UNESCAPED_UNICODE)) ?>)">
          <input type="hidden" name="csrf" value="<?= $esc_csrf ?>">
          <input type="hidden" name="action" value="packdelete">
          <input type="hidden" name="pack" value="<?= $esc($pid) ?>">
          <?php if ($pp !== ''): ?><input type="hidden" name="project" value="<?= $esc($pp) ?>"><?php endif; ?>
          <button class="btn danger" title="<?= $t('pack_delete_btn') ?>" aria-label="<?= $t('pack_delete_btn') ?>"><i class="fa-solid fa-trash-can"></i></button>
        </form>
      </span>
    <?php }; ?>
    <h2><?= $t('project_access_heading') ?></h2>
    <?php foreach ($viewProjects as $p):
      $meta = json_decode((string)@file_get_contents($cfg['projects_dir'] . '/' . $p . '/meta.json'), true);
      $contribOpen = contrib_open($cfg, $p);   // 有沒有還有效的投稿代碼＝這張地圖現在開不開放投稿
      $ppinsAll = $pinsAllData['projects'][$p] ?? [];
      $realPins = array_values(array_filter($ppinsAll, fn($e) => ($e['kind'] ?? 'pin') !== 'invite'));
      $invites = array_values(array_filter($ppinsAll, fn($e) => ($e['kind'] ?? 'pin') === 'invite'));
    ?>
      <div class="projhead">
        <div class="projtitle"><i class="fa-solid fa-map-location-dot"></i> <?= $esc($meta['title'] ?? $p) ?>（<?= $esc($p) ?>）</div>
        <div class="projactions">
          <a class="btn" href="<?= $esc(Route::backupProject($p)) ?>"><i class="fa-solid fa-download"></i> <?= $t('backup_project_btn') ?></a>
          <?php if ($canProject($p)):
            // 本區塊內 pack／layer 清單，供下方設定對話框與專案專屬清單共用。
            $packListAll = souliong_pack_list($cfg, $p);
            $layAllForP = souliong_layer_list($cfg, $p);
          ?>
          <button type="button" class="btn" onclick="document.getElementById('metadlg-<?= $esc($p) ?>').showModal()"><i class="fa-solid fa-pen-to-square"></i> <?= $t('edit_project_desc_btn') ?></button>
          <dialog id="metadlg-<?= $esc($p) ?>" class="metadlg" onclick="if(event.target===this)this.close()">
            <form method="post" class="metaform">
              <h3><i class="fa-solid fa-pen-to-square"></i> <?= $t('edit_project_desc_btn') ?></h3>
              <input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="meta"><input type="hidden" name="project" value="<?= $esc($p) ?>">
              <label><?= $t('field_title_label') ?><input name="title" maxlength="300" value="<?= $esc($meta['title'] ?? '') ?>" placeholder="<?= $t('map_title_placeholder') ?>"></label>
              <label><?= $t('field_subtitle_label') ?><input name="subtitle" maxlength="300" value="<?= $esc($meta['subtitle'] ?? '') ?>" placeholder="<?= $t('subtitle_placeholder') ?>"></label>
              <label><?= $t('field_desc_label') ?><textarea name="desc" rows="2" maxlength="300" placeholder="<?= $t('desc_optional_placeholder') ?>"><?= $esc($meta['desc'] ?? '') ?></textarea></label>
              <label><?= $t('field_source_label') ?><input name="source" maxlength="300" value="<?= $esc($meta['source'] ?? '') ?>" placeholder="<?= $t('source_placeholder') ?>"></label>
              <label><?= $t('field_credit_label') ?><input name="credit" maxlength="300" value="<?= $esc($meta['credit'] ?? '') ?>" placeholder="<?= $t('credit_placeholder') ?>"></label>
              <?php $numberingCur = in_array($meta['numbering'] ?? '', ['prefix', 'disable'], true) ? $meta['numbering'] : 'suffix'; ?>
              <label><?= $t('field_numbering_label') ?>
                <select name="numbering">
                  <option value="suffix" <?= $numberingCur === 'suffix' ? 'selected' : '' ?>><?= $t('numbering_suffix_option') ?></option>
                  <option value="prefix" <?= $numberingCur === 'prefix' ? 'selected' : '' ?>><?= $t('numbering_prefix_option') ?></option>
                  <option value="disable" <?= $numberingCur === 'disable' ? 'selected' : '' ?>><?= $t('numbering_disable_option') ?></option>
                </select>
              </label>
              <?php
                $pinMarkHasImg = cover_file_of(project_dir($cfg, $p) . '/pinmark') !== null;
                $pinMarkCur = in_array($meta['pinMark'] ?? '', ['blank', 'shape', 'image'], true) ? $meta['pinMark'] : 'number';
              ?>
              <label><?= $t('field_pinmark_label') ?>
                <select name="pinMark">
                  <option value="number" <?= $pinMarkCur === 'number' ? 'selected' : '' ?>><?= $t('pinmark_number_option') ?></option>
                  <option value="blank" <?= $pinMarkCur === 'blank' ? 'selected' : '' ?>><?= $t('pinmark_blank_option') ?></option>
                  <option value="shape" <?= $pinMarkCur === 'shape' ? 'selected' : '' ?>><?= $t('pinmark_shape_option') ?></option>
                  <?php if ($pinMarkHasImg): ?>
                  <option value="image" <?= $pinMarkCur === 'image' ? 'selected' : '' ?>><?= $t('pinmark_image_option') ?></option>
                  <?php endif; ?>
                </select>
              </label>
              <div class="hint"><?= $t('pinmark_image_hint') ?></div>
              <?php $pinSizeCur = in_array($meta['pinSize'] ?? '', ['sm', 'lg'], true) ? $meta['pinSize'] : 'md'; ?>
              <label><?= $t('field_pinsize_label') ?>
                <select name="pinSize">
                  <option value="sm" <?= $pinSizeCur === 'sm' ? 'selected' : '' ?>><?= $t('pinsize_sm_option') ?></option>
                  <option value="md" <?= $pinSizeCur === 'md' ? 'selected' : '' ?>><?= $t('pinsize_md_option') ?></option>
                  <option value="lg" <?= $pinSizeCur === 'lg' ? 'selected' : '' ?>><?= $t('pinsize_lg_option') ?></option>
                </select>
              </label>
              <label class="modrow">
                <input type="checkbox" name="pinBorder" <?= ($meta['pinBorder'] ?? true) ? 'checked' : '' ?>>
                <span><?= $t('field_pinborder_label') ?></span>
              </label>
              <?php
                // 三態下拉（對應上面 action=meta 的 pack 處理）：沒有 pack 欄位＝跟隨全站，
                // 空字串＝這張地圖明確不套用。順便把全站目前設的是哪一包寫在選項裡，
                // 才不用切到「工具」分頁才知道「跟隨全站」實際上會長什麼樣。
                $packList = $packListAll;
                $packHas  = is_array($meta) && array_key_exists('pack', $meta) && is_string($meta['pack']);
                $packSel  = $packHas ? $meta['pack'] : null;
                $sitePack = souliong_site_pack($cfg);
                $siteName = ($sitePack !== '' && isset($packList[$sitePack]))
                  ? ($packList[$sitePack]['label'] ?? $sitePack)
                  : i18n_t($DICT, 'site_pack_unset_name');
              ?>
              <label><?= $t('field_pack_label') ?>
                <select name="pack">
                  <option value="" <?= $packSel === null ? 'selected' : '' ?>><?= $t('pack_follow_site_option', ['pack' => $siteName]) ?></option>
                  <option value="!none" <?= $packSel === '' ? 'selected' : '' ?>><?= $t('no_pack_option') ?></option>
                  <?php foreach ($packList as $pid => $pinfo):
                    $plabel = ($pinfo['label'] ?? $pid) . (($pinfo['scope'] ?? '') === 'project' ? ' · ' . i18n_t($DICT, 'layer_scope_project') : '');
                  ?>
                  <option value="<?= $esc($pid) ?>" <?= $packSel === $pid ? 'selected' : '' ?>><?= $esc($plabel) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <?php
                // 圖層挑選器。清單由上而下＝由頂層到底層（跟繪圖軟體的圖層面板一致），
                // meta.json 存的是相反方向，兩邊各自反轉一次，見上面 action=meta 的處理。
                // 勾選的排在前面（照現有疊法），沒勾的接在後面等著被叫上來。
                $layAll  = $layAllForP;
                $layCur  = array_values(array_filter(
                  array_reverse((array)($meta['layers'] ?? [])),
                  fn($lid) => is_string($lid) && isset($layAll[$lid])
                ));
                $layRows = $layCur;
                foreach (array_keys($layAll) as $lid) {
                  if (!in_array($lid, $layRows, true)) $layRows[] = $lid;
                }
                $layDefault = implode('、', souliong_default_layers($cfg));
              ?>
              <input type="hidden" name="layers_submitted" value="1">
              <div class="modfields lyfields">
                <div class="modfields-head"><?= $t('layers_heading') ?></div>
                <div class="hint"><?= $t('layers_pick_hint', ['default' => $layDefault]) ?></div>
                <?php if ($layRows): ?>
                <div class="lylist lysort">
                  <?php foreach ($layRows as $lid): $li = $layAll[$lid]; ?>
                  <div class="lyrow">
                    <label class="lypick">
                      <input type="checkbox" name="layers[]" value="<?= $esc($lid) ?>" <?= in_array($lid, $layCur, true) ? 'checked' : '' ?>>
                      <span><b><?= $esc($li['label'] ?? $lid) ?></b>
                        <span class="hint mono"><?= $esc($lid) ?> · <?= $esc($li['pane'] ?? 'art') ?><?= ($li['scope'] ?? '') === 'project' ? ' · ' . $t('layer_scope_project') : '' ?></span></span>
                    </label>
                    <span class="lymove">
                      <button type="button" class="lybtn" data-lymove="-1" aria-label="<?= $t('layer_move_up_aria') ?>" title="<?= $t('layer_move_up_aria') ?>"><i class="fa-solid fa-chevron-up"></i></button>
                      <button type="button" class="lybtn" data-lymove="1" aria-label="<?= $t('layer_move_down_aria') ?>" title="<?= $t('layer_move_down_aria') ?>"><i class="fa-solid fa-chevron-down"></i></button>
                    </span>
                  </div>
                  <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="hint"><?= $t('no_layers_msg') ?></div>
                <?php endif; ?>
              </div>
              <input type="hidden" name="modules_submitted" value="1">
              <div class="modfields">
                <div class="modfields-head"><?= $t('feature_modules_heading') ?></div>
                <?php foreach (souliong_modules() as $mk => $minfo): $mon = souliong_module_on($meta, $mk); ?>
                <label class="modrow">
                  <input type="checkbox" name="<?= $mk === 'personExplore' ? 'personExplore' : 'features[' . $esc($mk) . ']' ?>" <?= $mon ? 'checked' : '' ?>>
                  <span><b><?= $esc($minfo['label']) ?></b><br><span class="hint"><?= $esc($minfo['desc']) ?></span></span>
                </label>
                <?php endforeach; ?>
              </div>
              <?php
                // 投稿設定。現值一律走 souliong_contrib_cfg() 而不是直接讀 $meta['contrib']，
                // 沒有 contrib 區塊的舊地圖才會顯示成它實際的行為（只有照片、不能建點），
                // 而不是全部空白——按下儲存也就不會把「看起來沒設定」變成真的關掉。
                $ccur = souliong_contrib_cfg($meta);
                $ckinds = souliong_kinds();
                $ctabs = [];
                foreach (souliong_contrib_kinds() as $ck) {
                  $tb = $ckinds[$ck]['tab'];
                  if (!in_array($tb, $ctabs, true)) $ctabs[] = $tb;
                }
              ?>
              <input type="hidden" name="contrib_submitted" value="1">
              <div class="modfields">
                <div class="modfields-head"><?= $t('contrib_kinds_heading') ?></div>
                <div class="hint"><?= $t('contrib_kinds_hint') ?></div>
                <?php foreach (souliong_contrib_kinds() as $ck): ?>
                <label class="modrow">
                  <input type="checkbox" name="contrib_kinds[<?= $esc($ck) ?>]" <?= in_array($ck, $ccur['kinds'], true) ? 'checked' : '' ?>>
                  <span><?= $esc($ckinds[$ck]['label']) ?></span>
                </label>
                <?php endforeach; ?>
                <label><?= $t('contrib_default_tab_label') ?>
                  <select name="contrib_default">
                    <?php foreach ($ctabs as $tb): ?>
                    <option value="<?= $esc($tb) ?>" <?= $ccur['default'] === $tb ? 'selected' : '' ?>><?= $t('tab_' . $tb) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <div class="hint"><?= $t('contrib_default_tab_hint') ?></div>
                <label><?= $t('contrib_newspot_label') ?>
                  <select name="contrib_newspot">
                    <?php foreach (['off', 'admin', 'contributor'] as $np): ?>
                    <option value="<?= $np ?>" <?= $ccur['newPoint'] === $np ? 'selected' : '' ?>><?= $t('contrib_newspot_' . $np) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <div class="hint"><?= $t('contrib_newspot_hint') ?></div>
              </div>
              <div class="dlgactions">
                <button type="button" class="btn" onclick="this.closest('dialog').close()"><?= $t('cancel') ?></button>
                <button class="btn solid"><i class="fa-solid fa-floppy-disk"></i> <?= $t('save_desc_btn') ?></button>
              </div>
            </form>
          </dialog>
          <?php
            // 這張地圖自己的圖層（projects/<id>/layers/）。放在專案卡而不是「工具」分頁，因為
            // 工具分頁只有主要管理者看得到，而自繪插畫本來就該由該地圖的管理者自己換。
            // 全站層在這裡只列不給刪改——要動它得去工具分頁。
            $projLayers = array_filter($layAllForP, fn($li) => ($li['scope'] ?? '') === 'project');
          ?>
          <button type="button" class="btn" onclick="document.getElementById('lyrdlg-<?= $esc($p) ?>').showModal()"><i class="fa-solid fa-layer-group"></i> <?= $t('layers_heading') ?></button>
          <dialog id="lyrdlg-<?= $esc($p) ?>" class="metadlg" onclick="if(event.target===this)this.close()">
            <div class="metaform">
              <h3><i class="fa-solid fa-layer-group"></i> <?= $t('project_layers_heading') ?></h3>
              <div class="hint"><?= $t('project_layers_hint') ?></div>
              <?php // 切圖磚工具是「產生一個新的專案層」的另一條路（匯入 ZIP 是既有的那條），所以入口擺在一起 ?>
              <div class="row" style="margin:2px 0 4px">
                <a class="btn" href="<?= $esc(Route::tool('tilecut', $p)) ?>"><i class="fa-solid fa-scissors"></i> <?= $t('open_tilecut_btn') ?></a>
                <span class="hint"><?= $t('tilecut_entry_hint') ?></span>
              </div>
              <?php
                // edit_3d_regions 預設關閉（跟 grant_access 等其他委派權限一樣），沒開的話這裡
                // 整段不出現——存檔會被伺服器擋，與其讓人填完整個編輯流程才在最後一步撞牆，不如
                // 一開始就不給入口。
                $canEdit3d = Auth::can($cfg, $p, 'edit_3d_regions');
                $projRegions = $canEdit3d ? souliong_region3d_list($cfg, $p) : [];
              ?>
              <?php if ($canEdit3d): ?>
              <div class="row" style="margin:2px 0 4px">
                <a class="btn" href="<?= $esc(Route::tool('region3d', $p)) ?>"><i class="fa-solid fa-cube"></i> <?= $t('open_region3d_btn') ?></a>
                <span class="hint"><?= $t('region3d_entry_hint') ?></span>
              </div>
              <?php if ($projRegions): ?>
              <div class="lylist">
                <?php foreach ($projRegions as $rid => $rinfo): ?>
                <div class="lyrow">
                  <span style="flex:1 1 auto;min-width:0;font-size:0.8125rem"><b><?= $esc($rinfo['label'] ?? $rid) ?></b>
                    <span class="hint mono"><?= $esc($rid) ?></span></span>
                  <a class="btn" href="<?= $esc(Route::tool('region3d', $p, ['load' => $rid])) ?>"><i class="fa-solid fa-pen"></i> <?= $t('region3d_edit_link_btn') ?></a>
                </div>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
              <?php endif; ?>
              <?php if ($projLayers): ?>
              <div class="lylist">
                <?php foreach ($projLayers as $lid => $linfo): ?>
                <div class="lyrow">
                  <span style="flex:1 1 auto;min-width:0;font-size:0.8125rem"><b><?= $esc($linfo['label'] ?? $lid) ?></b>
                    <span class="hint mono"><?= $esc($lid) ?> · <?= $esc($linfo['type'] ?? 'raster') ?></span></span>
                  <?php $layerAdminRow($lid, $linfo, $p); ?>
                </div>
                <?php endforeach; ?>
              </div>
              <?php else: ?>
              <div class="hint"><?= $t('no_project_layers_msg') ?></div>
              <?php endif; ?>
              <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                <input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="layerimport"><input type="hidden" name="project" value="<?= $esc($p) ?>">
                <span class="badge"><i class="fa-solid fa-upload"></i> <?= $t('layer_import_badge') ?></span>
                <label class="btn" style="cursor:pointer"><i class="fa-solid fa-folder-open"></i> <span data-file><?= $t('choose_zip_btn') ?></span>
                  <input type="file" name="layer" accept=".zip" required hidden onchange="this.parentNode.querySelector('[data-file]').textContent=this.files[0]?this.files[0].name:<?= json_encode(i18n_t($DICT, 'choose_zip_btn'), JSON_UNESCAPED_UNICODE) ?>"></label>
                <label style="display:inline-flex;align-items:center;gap:6px;font-size:0.8125rem"><input type="checkbox" name="overwrite" value="1"> <?= $t('layer_import_overwrite_label') ?></label>
                <button class="btn"><i class="fa-solid fa-upload"></i> <?= $t('restore_btn') ?></button>
              </form>
              <div class="dlgactions">
                <button type="button" class="btn" onclick="this.closest('dialog').close()"><?= $t('close') ?></button>
              </div>
            </div>
          </dialog>
          <?php
            // 這張地圖的主引擎：直接呼叫跟 pages/view.php 相同的 souliong_layers_for()，
            // 而不是用上面圖層挑選器的 $layCur——$layCur 只放 meta.json 明講的那幾筆，沒明講
            // （跟全站預設圖層）的專案會是空陣列，就看不出其實在吃向量底圖。只有 MapLibre 才能
            // 在前台擷圖，Leaflet 專案這裡不給「強制刷新」入口，只能靠管理者自訂上傳。
            $pEngine = 'leaflet';
            foreach (souliong_layers_for($cfg, $meta, $p) as $lm) {
              if (($lm['type'] ?? '') === 'vector') { $pEngine = 'maplibre'; break; }
            }
          ?>
          <button type="button" class="btn" onclick="document.getElementById('covdlg-<?= $esc($p) ?>').showModal()"><i class="fa-solid fa-image"></i> <?= $t('cover_heading') ?></button>
          <dialog id="covdlg-<?= $esc($p) ?>" class="metadlg" onclick="if(event.target===this)this.close()">
            <div class="metaform">
              <h3><i class="fa-solid fa-image"></i> <?= $t('cover_heading') ?></h3>
              <div class="hint"><?= $t('cover_hint') ?></div>
              <div class="cov-preview">
                <img src="<?= $esc(Route::api('cover', ['project' => $p])) ?>" alt="" onerror="this.closest('.cov-preview').classList.add('cov-empty')">
                <div class="cov-empty-icon"><i class="fa-solid fa-map-location-dot"></i></div>
              </div>
              <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:8px">
                <input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="coverupload"><input type="hidden" name="project" value="<?= $esc($p) ?>">
                <label class="btn" style="cursor:pointer"><i class="fa-solid fa-folder-open"></i> <span data-file><?= $t('choose_image_btn') ?></span>
                  <input type="file" name="cover" accept="image/*" required hidden onchange="this.parentNode.querySelector('[data-file]').textContent=this.files[0]?this.files[0].name:<?= json_encode(i18n_t($DICT, 'choose_image_btn'), JSON_UNESCAPED_UNICODE) ?>"></label>
                <button class="btn"><i class="fa-solid fa-upload"></i> <?= $t('cover_upload_btn') ?></button>
              </form>
              <div class="row" style="margin:10px 0;gap:10px;flex-wrap:wrap;align-items:center">
                <?php if ($pEngine === 'maplibre'): ?>
                <a class="btn" href="<?= $esc(Route::map($p) . '?snapcover=force') ?>" target="_blank" rel="noopener"><i class="fa-solid fa-camera-rotate"></i> <?= $t('cover_force_refresh_btn') ?></a>
                <span class="hint"><?= $t('cover_force_refresh_hint') ?></span>
                <?php else: ?>
                <span class="hint"><i class="fa-solid fa-circle-info"></i> <?= $t('cover_no_snapshot_msg') ?></span>
                <?php endif; ?>
              </div>
              <form method="post" onsubmit="return confirm(<?= $esc(json_encode($tr('cover_reset_confirm'), JSON_UNESCAPED_UNICODE)) ?>)">
                <input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="coverreset"><input type="hidden" name="project" value="<?= $esc($p) ?>">
                <button class="btn danger"><i class="fa-solid fa-rotate-left"></i> <?= $t('cover_reset_btn') ?></button>
              </form>
              <div class="dlgactions">
                <button type="button" class="btn" onclick="this.closest('dialog').close()"><?= $t('close') ?></button>
              </div>
            </div>
          </dialog>
          <button type="button" class="btn" onclick="document.getElementById('pmkdlg-<?= $esc($p) ?>').showModal()"><i class="fa-solid fa-location-dot"></i> <?= $t('pinmark_image_heading') ?></button>
          <dialog id="pmkdlg-<?= $esc($p) ?>" class="metadlg" onclick="if(event.target===this)this.close()">
            <div class="metaform">
              <h3><i class="fa-solid fa-location-dot"></i> <?= $t('pinmark_image_heading') ?></h3>
              <div class="hint"><?= $t('pinmark_image_dlg_hint') ?></div>
              <?php if ($pinMarkHasImg): ?>
              <div class="cov-preview">
                <img src="<?= $esc(Route::api('pinmark', ['project' => $p])) ?>" alt="">
              </div>
              <?php endif; ?>
              <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:8px">
                <input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="pinmarkupload"><input type="hidden" name="project" value="<?= $esc($p) ?>">
                <label class="btn" style="cursor:pointer"><i class="fa-solid fa-folder-open"></i> <span data-file><?= $t('choose_image_btn') ?></span>
                  <input type="file" name="pinmark" accept="image/*" required hidden onchange="this.parentNode.querySelector('[data-file]').textContent=this.files[0]?this.files[0].name:<?= json_encode(i18n_t($DICT, 'choose_image_btn'), JSON_UNESCAPED_UNICODE) ?>"></label>
                <button class="btn"><i class="fa-solid fa-upload"></i> <?= $t('cover_upload_btn') ?></button>
              </form>
              <?php if ($pinMarkHasImg): ?>
              <form method="post" onsubmit="return confirm(<?= $esc(json_encode($tr('pinmark_reset_confirm'), JSON_UNESCAPED_UNICODE)) ?>)">
                <input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="pinmarkreset"><input type="hidden" name="project" value="<?= $esc($p) ?>">
                <button class="btn danger"><i class="fa-solid fa-rotate-left"></i> <?= $t('cover_reset_btn') ?></button>
              </form>
              <?php endif; ?>
              <div class="dlgactions">
                <button type="button" class="btn" onclick="this.closest('dialog').close()"><?= $t('close') ?></button>
              </div>
            </div>
          </dialog>
          <?php
            // 這張地圖自己的主題包（projects/<id>/packs/）。跟上面的圖層對話框同一個道理：
            // 客製材質不想污染全站清單時，直接匯進這裡。全站包在這裡只列不給刪改。
            $projPacks = array_filter($packListAll, fn($pk) => ($pk['scope'] ?? '') === 'project');
          ?>
          <button type="button" class="btn" onclick="document.getElementById('pkrdlg-<?= $esc($p) ?>').showModal()"><i class="fa-solid fa-swatchbook"></i> <?= $t('packs_heading') ?></button>
          <dialog id="pkrdlg-<?= $esc($p) ?>" class="metadlg" onclick="if(event.target===this)this.close()">
            <div class="metaform">
              <h3><i class="fa-solid fa-swatchbook"></i> <?= $t('project_packs_heading') ?></h3>
              <div class="hint"><?= $t('project_packs_hint') ?></div>
              <?php if ($projPacks): ?>
              <div class="lylist">
                <?php foreach ($projPacks as $pid => $pinfo): ?>
                <div class="lyrow">
                  <span style="flex:1 1 auto;min-width:0;font-size:0.8125rem"><b><?= $esc($pinfo['label'] ?? $pid) ?></b>
                    <span class="hint mono"><?= $esc($pid) ?></span></span>
                  <?php $packAdminRow($pid, $p); ?>
                </div>
                <?php endforeach; ?>
              </div>
              <?php else: ?>
              <div class="hint"><?= $t('no_project_packs_msg') ?></div>
              <?php endif; ?>
              <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                <input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="packimport"><input type="hidden" name="project" value="<?= $esc($p) ?>">
                <span class="badge"><i class="fa-solid fa-upload"></i> <?= $t('pack_import_badge') ?></span>
                <label class="btn" style="cursor:pointer"><i class="fa-solid fa-folder-open"></i> <span data-file><?= $t('choose_zip_btn') ?></span>
                  <input type="file" name="pack" accept=".zip" required hidden onchange="this.parentNode.querySelector('[data-file]').textContent=this.files[0]?this.files[0].name:<?= json_encode(i18n_t($DICT, 'choose_zip_btn'), JSON_UNESCAPED_UNICODE) ?>"></label>
                <button class="btn"><i class="fa-solid fa-upload"></i> <?= $t('restore_btn') ?></button>
              </form>
              <div class="dlgactions">
                <button type="button" class="btn" onclick="this.closest('dialog').close()"><?= $t('close') ?></button>
              </div>
            </div>
          </dialog>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($canProject($p)):
        $canGrantAccess = Auth::can($cfg, $p, 'grant_access');
        $permLabels = [];
        foreach (auth_registry() as $pk => $pdef) {
          if ($pdef['scope'] === 'project' && $pdef['label'] !== null) $permLabels[$pk] = $t($pdef['label']);
        }
        $cList = contrib_load($cfg, $p);
        $codesList = codes_load($cfg, $p);
        $blocked = blocked_load($cfg, $p);
        // 依實際投稿紀錄統計每個身分的則數：有 PIN 身分的算 contrib_id，其餘（含匿名）算 owner_hash 分組。
        $contribCounts = [];
        $ownerGroups = [];
        foreach ($rowsByProject[$p] ?? [] as $r) {
          $cid = $r['contrib_id'] ?? null;
          if ($cid) { $contribCounts[$cid] = ($contribCounts[$cid] ?? 0) + 1; continue; }
          $oh = $r['owner_hash'] ?? null;
          if (!$oh) continue;
          if (!isset($ownerGroups[$oh])) $ownerGroups[$oh] = ['count' => 0, 'last_name' => '', 'last_at' => ''];
          $ownerGroups[$oh]['count']++;
          $at = (string)($r['created_at'] ?? '');
          if ($at > $ownerGroups[$oh]['last_at']) { $ownerGroups[$oh]['last_at'] = $at; $ownerGroups[$oh]['last_name'] = (string)($r['name'] ?? ''); }
        }
        $canDeleteOthers = Auth::can($cfg, $p, 'delete_others');
        // 剛建立的憑證：只在本次回應顯示一次，畫在所屬區塊內（屬「正在分享」，維持明碼）
        $justHere = fn(...$kinds) => $justCreatedShare && $justCreatedShare['project'] === $p && in_array($justCreatedShare['kind'], $kinds, true);
        $shareNew = function (array $s, string $kindLabel) use ($esc, $p, $meta, $t) { ?>
          <div class="card sharenew">
            <div class="badge"><i class="fa-solid fa-circle-check"></i> <?= $t('share_created_badge', ['kind' => $kindLabel]) ?><?= $s['kind'] === 'code' ? '' : $t('share_created_once_note') ?></div>
            <div class="sharenew-body">
              <div class="qr" data-url="<?= $esc($s['url']) ?>" data-title="<?= $esc($meta['title'] ?? $p) ?>" data-code="<?= isset($s['code']) ? $esc($s['code']) : '' ?>"></div>
              <div class="sharenew-info">
                <div class="invite"><?= $esc($s['url']) ?></div>
                <div class="row"><button type="button" class="btn" data-copy="<?= $esc($s['url']) ?>"><i class="fa-solid fa-copy"></i> <?= $t('copy_link') ?></button></div>
                <?php if (isset($s['code'])): ?><div class="hint" style="margin-top:6px"><?= $t('share_code_hint', ['code' => $s['code']]) ?></div><?php endif; ?>
                <?php if (isset($s['note'])): ?><div class="hint" style="margin-top:6px"><?= $s['note'] ?></div><?php endif; ?>
              </div>
            </div>
          </div>
        <?php };
      ?>
        <!-- 投稿代碼：一碼一張卡，連結／QR／限制／用量都在同一張卡上 -->
        <div class="sechead"><i class="fa-solid fa-ticket"></i> <?= $t('contrib_code') ?><span class="sechint"><?= $contribOpen ? $t('codes_gated_hint') : $t('codes_none_hint') ?></span></div>
        <?php if ($codesList): ?>
          <div class="code-grid">
            <?php foreach ($codesList as $ce2): $cc = (string)($ce2['code'] ?? ''); $inviteC = $mapUrl($p) . '?code=' . $cc; ?>
              <div class="card codecard">
                <div class="codecard-title">
                  <span class="mono code-main"><?= $esc($cc) ?></span>
                  <?php if (($ce2['label'] ?? '') !== ''): ?><span class="codecard-label"><?= $esc($ce2['label']) ?></span><?php endif; ?>
                </div>
                <div class="codecard-info">
                  <div class="badge">
                    <?= !empty($ce2['expires_at']) ? $t('expires_at_label', ['date' => substr((string)$ce2['expires_at'], 0, 16)]) : $t('no_expiry_label') ?>
                    ・<?= isset($ce2['max_uses']) && $ce2['max_uses'] !== null ? $t('used_of_max_label', ['used' => (int)($ce2['used_count'] ?? 0), 'max' => (int)$ce2['max_uses']]) : $t('unlimited_used_label', ['used' => (int)($ce2['used_count'] ?? 0)]) ?>
                  </div>
                </div>
                <div class="codecard-acts">
                  <button type="button" class="btn qr-trigger" data-url="<?= $esc($inviteC) ?>" data-title="<?= $esc($meta['title'] ?? $p) ?>" data-code="<?= $esc($cc) ?>" title="<?= $t('show_qr_title') ?>"><i class="fa-solid fa-share-nodes"></i> <?= $t('share_code_btn') ?></button>
                  <button type="button" class="chipbtn" data-copy="<?= $esc($inviteC) ?>" title="<?= $t('copy_code_invite_link_title') ?>"><i class="fa-solid fa-link"></i></button>
                  <form method="post"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="delcode"><input type="hidden" name="project" value="<?= $esc($p) ?>"><input type="hidden" name="code_del" value="<?= $esc($cc) ?>"><button class="x" title="<?= $t('remove_code_title') ?>">×</button></form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="emptystate"><i class="fa-solid fa-ticket" aria-hidden="true"></i><?= $t('no_codes_yet_msg') ?></div>
        <?php endif; ?>
        <details class="metaedit">
          <summary class="btn"><i class="fa-solid fa-plus"></i> <?= $t('add_code_btn') ?></summary>
          <form class="row expirywidget-row" method="post" style="flex-wrap:wrap;margin-top:10px;padding-top:10px;border-top:1px solid var(--line)">
            <input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="sharelink"><input type="hidden" name="kind" value="code"><input type="hidden" name="project" value="<?= $esc($p) ?>">
            <label class="fieldlabel"><?= $t('contrib_code') ?><input name="pin_new" autocomplete="off" placeholder="<?= $t('code_field_placeholder') ?>" data-pin-toggle data-pin-digits-only></label>
            <label class="fieldlabel"><?= $t('col_nickname') ?><input name="label" autocomplete="off" placeholder="<?= $t('optional_placeholder') ?>"></label>
            <label class="fieldlabel"><?= $t('expiry_time_label') ?>
              <div class="expirywidget">
                <div class="expirychips">
                  <button type="button" class="chip" data-preset="none"><?= $t('no_expiry_label') ?></button>
                  <button type="button" class="chip" data-preset="1h"><?= $t('preset_1h') ?></button>
                  <button type="button" class="chip" data-preset="1d"><?= $t('preset_1d') ?></button>
                  <button type="button" class="chip" data-preset="1w"><?= $t('preset_1w') ?></button>
                </div>
                <input name="expires_at" type="datetime-local" class="expirycustom" title="<?= $t('expiry_input_title') ?>">
              </div>
            </label>
            <label class="fieldlabel"><?= $t('max_uses_label') ?><input name="max_uses" type="number" min="1" placeholder="<?= $t('max_uses_placeholder') ?>"></label>
            <button class="btn solid"><i class="fa-solid fa-check"></i> <?= $t('confirm_add_btn') ?></button>
          </form>
        </details>
        <?php if ($justHere('code')) $shareNew($justCreatedShare, $t('contrib_code')); ?>

        <!-- 身分管理：投稿者（純自助）與管理 PIN 並列 -->
        <div class="sechead"><i class="fa-solid fa-users"></i> <?= $t('identity_management_heading') ?></div>
        <div class="idgrid">
          <div class="idgroup">
            <div class="sechead"><i class="fa-solid fa-id-badge"></i> <?= $t('contributors_heading') ?></div>
            <?php if ($cList || $ownerGroups): ?>
              <div class="pinlist">
                <?php foreach ($cList as $cid => $ce):
                  $cnt = $contribCounts[$cid] ?? 0;
                  $isBlocked = in_array($cid, $blocked['contribs'], true);
                ?>
                  <div class="pinchip pinchip-block<?= $isBlocked ? ' blocked' : '' ?>">
                    <div class="idline"><span><?= $esc(($ce['label'] ?? '') !== '' ? $ce['label'] : $t('no_nickname_contributor_label')) ?> · <span class="mono"><?= $esc($cid) ?></span> · <?= $t('record_count_suffix', ['n' => $cnt]) ?><?php if ($isBlocked): ?> · <span class="tag"><?= $t('locked_tag') ?></span><?php endif; ?></span><span class="idacts">
                      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="delcontrib"><input type="hidden" name="project" value="<?= $esc($p) ?>"><input type="hidden" name="contrib_id" value="<?= $esc($cid) ?>"><button class="x" title="<?= $t('revoke_contrib_title') ?>">×</button></form>
                    </span></div>
                    <div class="permrow">
                      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="<?= $isBlocked ? 'unblockid' : 'blockid' ?>"><input type="hidden" name="project" value="<?= $esc($p) ?>"><input type="hidden" name="kind" value="contrib"><input type="hidden" name="key" value="<?= $esc($cid) ?>"><button class="permtoggle<?= $isBlocked ? ' on' : '' ?>" title="<?= $t('lock_after_title') ?>"><?= $isBlocked ? $t('locked_btn') : $t('lock_btn') ?></button></form>
                      <?php if ($cnt > 0 && $canDeleteOthers): ?>
                        <form method="post" style="display:inline" onsubmit="return confirm(<?= $esc(json_encode(i18n_t($DICT, 'confirm_delete_all_contrib', ['project' => $p, 'n' => $cnt]), JSON_UNESCAPED_UNICODE)) ?>)"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="delbyid"><input type="hidden" name="project" value="<?= $esc($p) ?>"><input type="hidden" name="kind" value="contrib"><input type="hidden" name="key" value="<?= $esc($cid) ?>"><button class="permtoggle" title="<?= $t('delete_all_title') ?>"><i class="fa-solid fa-trash"></i> <?= $t('delete_all_btn') ?></button></form>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
                <?php foreach ($ownerGroups as $oh => $og):
                  $isBlocked = in_array($oh, $blocked['owners'], true);
                  $nameNote = ($og['last_name'] !== '' && $og['last_name'] !== '匿名') ? $t('used_name_note', ['name' => $og['last_name']]) : $t('anon_contributor_label');
                ?>
                  <div class="pinchip pinchip-block<?= $isBlocked ? ' blocked' : '' ?>">
                    <div class="idline"><span><?= $nameNote ?> · <span class="mono"><?= $esc(substr($oh, 0, 8)) ?></span> · <?= $t('record_count_suffix', ['n' => $og['count']]) ?><?php if ($isBlocked): ?> · <span class="tag"><?= $t('locked_tag') ?></span><?php endif; ?></span></div>
                    <div class="permrow">
                      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="<?= $isBlocked ? 'unblockid' : 'blockid' ?>"><input type="hidden" name="project" value="<?= $esc($p) ?>"><input type="hidden" name="kind" value="owner"><input type="hidden" name="key" value="<?= $esc($oh) ?>"><button class="permtoggle<?= $isBlocked ? ' on' : '' ?>" title="<?= $t('lock_after_owner_title') ?>"><?= $isBlocked ? $t('locked_btn') : $t('lock_btn') ?></button></form>
                      <?php if ($canDeleteOthers): ?>
                        <form method="post" style="display:inline" onsubmit="return confirm(<?= $esc(json_encode(i18n_t($DICT, 'confirm_delete_all_contrib', ['project' => $p, 'n' => $og['count']]), JSON_UNESCAPED_UNICODE)) ?>)"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="delbyid"><input type="hidden" name="project" value="<?= $esc($p) ?>"><input type="hidden" name="kind" value="owner"><input type="hidden" name="key" value="<?= $esc($oh) ?>"><button class="permtoggle" title="<?= $t('delete_all_title') ?>"><i class="fa-solid fa-trash"></i> <?= $t('delete_all_btn') ?></button></form>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="emptystate"><i class="fa-solid fa-inbox" aria-hidden="true"></i><?= $t('no_records_yet_msg') ?></div>
            <?php endif; ?>
          </div>

          <?php if ($primary || $canGrantAccess): ?>
          <div class="idgroup">
            <div class="sechead"><i class="fa-solid fa-user-gear"></i> <?= $t('admin_pins_heading') ?></div>
            <?php if ($realPins): ?>
              <div class="pinlist">
                <?php if ($primary):
                  foreach ($realPins as $e): $pid = (string)($e['id'] ?? ''); $perms = $e['perms'] ?? pin_default_perms(); ?>
                  <div class="pinchip pinchip-block">
                    <div class="idline"><span><?= $esc(($e['label'] ?? '') !== '' ? $e['label'] : $t('no_nickname_label')) ?> · <span class="mono">••••••</span>
                      <?php if (!empty($e['via_link'])): ?><span class="tag"><?= $t('invite_redeemed_tag') ?></span><?php endif; ?></span><span class="idacts">
                      <?php if ($pid !== ''): ?>
                      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="migrate_create"><input type="hidden" name="source" value="project"><input type="hidden" name="project" value="<?= $esc($p) ?>"><input type="hidden" name="legacy_id" value="<?= $esc($pid) ?>"><input type="hidden" name="label" value="<?= $esc($e['label'] ?? '') ?>"><button type="submit" class="chipbtn" title="<?= $t('migrate_to_account_title') ?>"><i class="fa-solid fa-right-left"></i></button></form>
                      <?php endif; ?>
                      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="delpin"><input type="hidden" name="scope" value="project"><input type="hidden" name="project" value="<?= $esc($p) ?>"><input type="hidden" name="pin_id" value="<?= $esc($pid) ?>"><button class="x" title="<?= $t('remove_title') ?>">×</button></form>
                    </span></div>
                    <?php if ($pid !== ''): ?>
                    <div class="permrow">
                      <?php foreach ($permLabels as $pk => $ptext): $on = !empty($perms[$pk]); ?>
                        <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="setperm"><input type="hidden" name="scope" value="project"><input type="hidden" name="project" value="<?= $esc($p) ?>"><input type="hidden" name="pin_id" value="<?= $esc($pid) ?>"><input type="hidden" name="perm" value="<?= $esc($pk) ?>"><input type="hidden" name="on" value="<?= $on ? '0' : '1' ?>">
                          <button class="permtoggle<?= $on ? ' on' : '' ?>" title="<?= $t('grant_perm_title') ?>"><?= $on ? '✓ ' : '' ?><?= $esc($ptext) ?></button>
                        </form>
                      <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; else: ?>
                  <?php foreach ($realPins as $e): ?>
                    <span class="pinchip" title="<?= $t('primary_only_pin_visible_title') ?>"><?= $esc(($e['label'] ?? '') !== '' ? $e['label'] : $t('no_nickname_label')) ?></span>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            <?php endif; ?>
            <?php if ($primary): ?>
              <form class="row" method="post" style="flex-wrap:wrap;margin-top:8px"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="addpin"><input type="hidden" name="scope" value="project"><input type="hidden" name="project" value="<?= $esc($p) ?>">
                <label class="fieldlabel"><?= $t('add_pin_direct_label') ?><input name="pin_new" autocomplete="off" placeholder="<?= $t('add_pin_direct_placeholder') ?>"></label>
                <label class="fieldlabel"><?= $t('col_nickname') ?><input name="label" autocomplete="off" placeholder="<?= $t('optional_placeholder') ?>"></label>
                <button class="btn"><i class="fa-solid fa-plus"></i> <?= $t('add_pin_direct_btn') ?></button>
              </form>
            <?php endif; ?>
            <?php if ($primary && $invites): ?>
              <div class="sechead"><i class="fa-solid fa-envelope-open-text"></i> <?= $t('pending_invites_heading') ?></div>
              <div class="pinlist">
                <?php foreach ($invites as $e): $inviteId = (string)($e['id'] ?? ''); $inviteUrl = $mapUrl($p) . '#redeem=' . rawurlencode((string)($e['token'] ?? '')) . '&rmode=grant'; ?>
                  <div class="pinchip pinchip-block">
                    <div class="idline"><span><?= $t('pending_invite_label') ?></span><span class="idacts">
                      <button type="button" class="chipbtn qr-trigger" data-url="<?= $esc($inviteUrl) ?>" data-title="<?= $esc($meta['title'] ?? $p) ?>" title="<?= $t('show_qr_title') ?>"><i class="fa-solid fa-qrcode"></i></button>
                      <button type="button" class="chipbtn" data-copy="<?= $esc($inviteUrl) ?>" title="<?= $t('copy_invite_link_title') ?>"><i class="fa-solid fa-link"></i></button>
                      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="delinvite"><input type="hidden" name="project" value="<?= $esc($p) ?>"><input type="hidden" name="invite_id" value="<?= $esc($inviteId) ?>"><button class="x" title="<?= $t('revoke_invite_title') ?>">×</button></form>
                    </span></div>
                    <div class="badge">
                      <?= !empty($e['expires_at']) ? $t('expires_at_label', ['date' => substr((string)$e['expires_at'], 0, 16)]) : $t('no_expiry_label') ?>
                      ・<?= isset($e['max_uses']) && $e['max_uses'] !== null ? $t('redeemed_of_max_label', ['used' => (int)($e['used_count'] ?? 0), 'max' => (int)$e['max_uses']]) : $t('redeemed_unlimited_label', ['used' => (int)($e['used_count'] ?? 0)]) ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <?php if ($canGrantAccess): ?>
              <details class="metaedit">
                <summary class="btn"><i class="fa-solid fa-share-nodes"></i> <?= $t('create_invite_link_btn') ?></summary>
                <form class="row expirywidget-row" method="post" style="flex-wrap:wrap;margin-top:10px;padding-top:10px;border-top:1px solid var(--line)">
                  <input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="sharelink"><input type="hidden" name="kind" value="grant"><input type="hidden" name="project" value="<?= $esc($p) ?>">
                  <label class="fieldlabel"><?= $t('expiry_time_label') ?>
                    <div class="expirywidget">
                      <div class="expirychips">
                        <button type="button" class="chip" data-preset="none"><?= $t('no_expiry_label') ?></button>
                        <button type="button" class="chip" data-preset="1h"><?= $t('preset_1h') ?></button>
                        <button type="button" class="chip" data-preset="1d"><?= $t('preset_1d') ?></button>
                        <button type="button" class="chip" data-preset="1w"><?= $t('preset_1w') ?></button>
                      </div>
                      <input name="expires_at" type="datetime-local" class="expirycustom" title="<?= $t('expiry_input_title') ?>">
                    </div>
                  </label>
                  <label class="fieldlabel"><?= $t('max_redeem_label') ?><input name="max_uses" type="number" min="1" placeholder="<?= $t('max_uses_placeholder') ?>"></label>
                  <button class="btn solid"><i class="fa-solid fa-check"></i> <?= $t('confirm_create_btn') ?></button>
                </form>
              </details>
            <?php endif; ?>
            <?php if ($justHere('grant')) $shareNew($justCreatedShare, $t('admin_pin_invite_share_label')); ?>
            <?php if ($justCreatedMigrate && $justCreatedMigrate['project'] === $p) $shareNew($justCreatedMigrate, $t('migrate_to_account_title')); ?>
          </div>
          <?php endif; ?>

          <?php if ($primary): $acctAdmins = project_perms_load($cfg, $p)['members']; ?>
          <div class="idgroup">
            <div class="sechead"><i class="fa-solid fa-user-shield"></i> <?= $t('account_admins_heading') ?></div>
            <?php if ($acctAdmins): ?>
              <div class="pinlist">
                <?php foreach ($acctAdmins as $m):
                  $accountId = (string)($m['account_id'] ?? '');
                  $acc = account_find_by_id($cfg, $accountId);
                  $perms = $m['perms'] ?? _account_default_perms();
                  $accLabel = $acc !== null ? (($acc['label'] ?? '') !== '' ? $acc['label'] : $acc['userid']) : $t('account_admin_unknown_label');
                ?>
                  <div class="pinchip pinchip-block">
                    <div class="idline"><span><?= $esc($accLabel) ?><?php if ($acc !== null): ?> · <span class="mono"><?= $esc($acc['userid']) ?></span><?php endif; ?></span><span class="idacts">
                      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="delacctperm"><input type="hidden" name="project" value="<?= $esc($p) ?>"><input type="hidden" name="account_id" value="<?= $esc($accountId) ?>"><button class="x" title="<?= $t('remove_title') ?>">×</button></form>
                    </span></div>
                    <div class="permrow">
                      <?php foreach ($permLabels as $pk => $ptext): $on = !empty($perms[$pk]); ?>
                        <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="setacctperm"><input type="hidden" name="project" value="<?= $esc($p) ?>"><input type="hidden" name="account_id" value="<?= $esc($accountId) ?>"><input type="hidden" name="perm" value="<?= $esc($pk) ?>"><input type="hidden" name="on" value="<?= $on ? '0' : '1' ?>">
                          <button class="permtoggle<?= $on ? ' on' : '' ?>" title="<?= $t('grant_perm_title') ?>"><?= $on ? '✓ ' : '' ?><?= $esc($ptext) ?></button>
                        </form>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="emptystate"><i class="fa-solid fa-user-shield" aria-hidden="true"></i><?= $t('no_account_admins_msg') ?></div>
            <?php endif; ?>
            <form class="row" method="post" style="flex-wrap:wrap;margin-top:8px"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="addacctperm"><input type="hidden" name="project" value="<?= $esc($p) ?>">
              <label class="fieldlabel"><?= $t('add_account_admin_label') ?><input name="userid" autocomplete="off" placeholder="<?= $t('add_account_admin_placeholder') ?>"></label>
              <button class="btn"><i class="fa-solid fa-plus"></i> <?= $t('add_account_admin_btn') ?></button>
            </form>
          </div>
          <?php endif; ?>
        </div>

      <?php endif; ?>
    <?php endforeach; ?>
    </div><!-- /pane-access -->

    <div class="pane on" id="pane-overview">
    <?php if (count($viewProjects) > 1): ?>
      <div class="pcards-wrap">
        <div class="pcards" id="pcards-stats-jump">
          <?php foreach ($viewProjects as $jp): ?><a class="pcard" href="#stat-<?= $esc($jp) ?>"><?= $esc($jp) ?></a><?php endforeach; ?>
        </div>
        <button type="button" class="pcards-toggle" data-target="pcards-stats-jump" aria-expanded="false" title="<?= $t('pcards_expand_btn') ?>"><i class="fa-solid fa-chevron-down"></i></button>
      </div>
    <?php endif; ?>
    <h2><?= $t('stats_summary_heading') ?></h2>
    <?php
      // 統計圖表：長度在 PHP 端就算好，純 CSS 畫條，不引圖表庫也不用 JS。
      // 條是裝飾（aria-hidden），每一列旁邊都有文字標籤與數值，讀螢幕的人拿到的資訊一樣。
      $statBars = function (array $arr, callable $label, int $limit = 20) use ($esc): string {
        if (!$arr) return '';
        $max = max($arr) ?: 1;
        $out = '<ol class="statbars">';
        foreach (array_slice($arr, 0, $limit, true) as $k => $v) {
          $w = max(2, (int)round((int)$v / $max * 100));   // 最小 2%：只被點過一次也要看得到一小截
          $lb = (string)$label($k);
          $out .= '<li><span class="lbl" title="' . $esc($lb) . '">' . $esc($lb) . '</span>'
            . '<span class="trk" aria-hidden="true"><span class="fil" style="width:' . $w . '%"></span></span>'
            . '<span class="val">' . (int)$v . '</span></li>';
        }
        return $out . '</ol>';
      };
      // $cells = [['v' => 次數, 'title' => 滑過顯示的字, 'axis' => 軸標（空字串＝這格不標）], ...]
      $statCols = function (array $cells, string $aria) use ($esc): string {
        $max = 0;
        foreach ($cells as $c) $max = max($max, (int)$c['v']);
        $max = $max ?: 1;
        $bars = '';
        $axis = '';
        foreach ($cells as $c) {
          $v = (int)$c['v'];
          $h = $v > 0 ? max(8, (int)round($v / $max * 100)) : 3;   // 0 也畫一條淺淺的底，看得出這格存在
          $bars .= '<div class="cc' . ($v ? '' : ' zero') . '" title="' . $esc((string)$c['title']) . '"><i style="height:' . $h . '%"></i></div>';
          $axis .= '<span>' . $esc((string)$c['axis']) . '</span>';
        }
        return '<div class="statcols" role="img" aria-label="' . $esc($aria) . '">' . $bars . '</div>'
          . '<div class="statcols-axis" aria-hidden="true">' . $axis . '</div>';
      };
    ?>
    <?php foreach ($viewProjects as $p): $s = stats_read($cfg, $p);
      if (!$s) continue;
      $s['spots'] = $s['spots'] ?? [];
      $s['cameras'] = $s['cameras'] ?? [];
      $s['kinds'] = $s['kinds'] ?? [];
      arsort($s['spots']);
      arsort($s['cameras']);
      arsort($s['kinds']);
      $top = function ($arr, $n = 5) {
        $o = [];
        foreach (array_slice($arr, 0, $n, true) as $k => $v) $o[] = $k . '·' . $v;
        return $o ? implode('、', $o) : '—';
      };
      $featLabels = souliong_features();
      $feats = $s['features'] ?? [];
      // 舊統計的 story／sound 鍵已併入 content
      foreach (['story', 'sound'] as $oldKey) {
          if (isset($feats[$oldKey])) { $feats['content'] = ($feats['content'] ?? 0) + $feats[$oldKey]; unset($feats[$oldKey]); }
      }
      arsort($feats);
      $byHour = $s['by_hour'] ?? [];
      arsort($byHour);
      $byDow = $s['by_dow'] ?? [];
      arsort($byDow);
      $dowNames = explode(',', i18n_t($DICT, 'weekday_short_csv'));
      $browsers = $s['browser'] ?? [];
      arsort($browsers);
      $oses = $s['os'] ?? [];
      arsort($oses);
      // 標籤交給 $statBars 統一跳脫，這裡拿未跳脫的原文（用 $t 會變成跳脫兩次）
      $bLabels = ['chrome' => 'Chrome', 'safari' => 'Safari', 'edge' => 'Edge', 'firefox' => 'Firefox', 'samsung' => 'Samsung Internet', 'opera' => 'Opera', 'line' => i18n_t($DICT, 'browser_line'), 'facebook' => i18n_t($DICT, 'browser_fb'), 'instagram' => i18n_t($DICT, 'browser_ig'), 'wechat' => i18n_t($DICT, 'browser_wechat'), 'duckduckgo' => 'DuckDuckGo', 'other' => i18n_t($DICT, 'other_label')];
      $oLabels = ['ios' => 'iOS', 'android' => 'Android', 'windows' => 'Windows', 'macos' => 'macOS', 'linux' => 'Linux', 'other' => i18n_t($DICT, 'other_label')];
      $mob = (int)($s['device']['mobile'] ?? 0);
      $desk = (int)($s['device']['desktop'] ?? 0);
      $mobPct = ($mob + $desk) > 0 ? (int)round($mob / ($mob + $desk) * 100) : 0;
      // 時段／星期：連沒有資料的格子都要列出來，才看得出一整天、一整週的形狀
      $hourCells = [];
      for ($hx = 0; $hx <= 23; $hx++) {
        $hv = (int)(($s['by_hour'] ?? [])[$hx] ?? 0);
        $hourCells[] = ['v' => $hv, 'title' => i18n_t($DICT, 'hour_item', ['h' => $hx, 'n' => $hv]), 'axis' => $hx % 3 === 0 ? (string)$hx : ''];
      }
      $dowCells = [];
      for ($dx = 0; $dx <= 6; $dx++) {
        $dv = (int)(($s['by_dow'] ?? [])[$dx] ?? 0);
        $dn = $dowNames[$dx] ?? (string)$dx;
        $dowCells[] = ['v' => $dv, 'title' => i18n_t($DICT, 'weekday_item', ['d' => $dn, 'n' => $dv]), 'axis' => $dn];
      }
      // 圖是給眼睛看的，讀螢幕的人拿到的是這行前三名摘要（跟改版前那行文字一樣）
      $hTop = [];
      foreach (array_slice($byHour, 0, 3, true) as $k => $v) $hTop[] = i18n_t($DICT, 'hour_item', ['h' => (int)$k, 'n' => (int)$v]);
      $dTop = [];
      foreach (array_slice($byDow, 0, 3, true) as $k => $v) $dTop[] = i18n_t($DICT, 'weekday_item', ['d' => $dowNames[(int)$k] ?? $k, 'n' => (int)$v]);
      $hourAria = i18n_t($DICT, 'top_hours_label') . ($hTop ? implode('、', $hTop) : i18n_t($DICT, 'no_data_msg'));
      $dowAria = i18n_t($DICT, 'top_weekdays_label') . ($dTop ? implode('、', $dTop) : i18n_t($DICT, 'no_data_msg'));
      // 空間佔用：純算術讀 $storageCache（見上面的共用宣告），沒算過就是 null，畫面顯示「—」而不是 0
      $upB = $lyB = $pkB = null;
      $projBytes = null;
      if ($storageCache !== null) {
        $u = $storageCache['uploads'][$p] ?? ['photos' => 0, 'media' => 0];
        $upB = (int)($u['photos'] ?? 0) + (int)($u['media'] ?? 0);
        $lyB = array_sum($storageCache['layers'][$p] ?? []);
        $pkB = array_sum($storageCache['packs'][$p] ?? []);
        $projBytes = $upB + $lyB + $pkB;
      }
      // 轉換率：工作階段中有多少比例真的送出投稿。沒有工作階段資料就沒有分母，不算、不顯示這格。
      $sessions = (int)($s['sessions'] ?? 0);
      $uploads  = (int)($s['uploads'] ?? 0);
      $convPct  = $sessions > 0 ? round($uploads / $sessions * 100, 1) : null;
      $convStr  = $convPct !== null ? rtrim(rtrim(number_format($convPct, 1, '.', ''), '0'), '.') : '';
      // 這張卡完全沒有任何「有沒有資料」都要判斷的內容時，底下的排行／分布欄位一格都不會顯示——
      // 這是正常狀態（例如剛上線還沒人來過），不是錯誤，所以不額外畫「尚無資料」的空卡片。
    ?>
      <div class="stat-card" id="stat-<?= $esc($p) ?>">
        <div class="stat-card-head">
          <b><?= $esc($p) ?></b>
          <a class="btn" href="<?= $esc(Route::manager($p, 'records')) ?>"><i class="fa-solid fa-table-list"></i> <?= $t('view_records_link') ?></a>
        </div>
        <div class="stats-grid">
          <div class="tile">
            <div class="n"><?= (int)($s['views'] ?? 0) ?></div>
            <div class="l"><?= $t('stat_views_label') ?></div>
            <div class="d"><?= $t('stat_views_desc') ?></div>
          </div>
          <div class="tile">
            <div class="n"><?= $sessions ?></div>
            <div class="l"><?= $t('stat_sessions_label') ?></div>
            <div class="d"><?= $t('stat_sessions_desc') ?></div>
          </div>
          <div class="tile">
            <div class="n"><?= $uploads ?></div>
            <div class="l"><?= $t('stat_uploads_label') ?></div>
            <div class="d"><?= $t('stat_uploads_desc') ?></div>
          </div>
          <?php if ($convPct !== null): ?>
          <div class="tile">
            <div class="n"><?= $esc($convStr) ?>%</div>
            <div class="l"><?= $t('stat_conversion_label') ?></div>
            <div class="d"><?= $t('stat_conversion_desc') ?></div>
          </div>
          <?php endif; ?>
          <?php if ($mob + $desk > 0): ?>
          <div class="tile">
            <div class="n statdev"><span class="a"><?= $mob ?></span><span class="sep">/</span><span class="b"><?= $desk ?></span></div>
            <div class="statratio" aria-hidden="true"><span class="a" style="width:<?= $mobPct ?>%"></span><span class="b" style="width:<?= 100 - $mobPct ?>%"></span></div>
            <div class="l"><?= $t('stat_mobile_desktop_label') ?></div>
            <div class="d"><?= $t('stat_device_desc') ?></div>
          </div>
          <?php endif; ?>
          <div class="tile">
            <div class="n" <?= $projBytes !== null ? 'title="' . $esc(i18n_t($DICT, 'stat_storage_breakdown', ['up' => $fmtBytes($upB), 'ly' => $fmtBytes($lyB), 'pk' => $fmtBytes($pkB)])) . '"' : '' ?>><?= $projBytes !== null ? $esc($fmtBytes($projBytes)) : '—' ?></div>
            <div class="l"><?= $t('stat_storage_label') ?></div>
            <div class="d"><?= $t('stat_storage_desc') ?></div>
          </div>
        </div>
        <?php if ($s['spots'] || $s['cameras']): ?>
        <div class="break"><?= $t('top_spots_label') ?><b><?= $esc($top($s['spots'])) ?></b><br><?= $t('top_cameras_label') ?><b><?= $esc($top($s['cameras'])) ?></b></div>
        <?php endif; ?>
        <?php
          // 每一欄都是「有資料才畫」：尚無資料的排行／分布不再顯示空卡片或佔位文字。
          // 全部欄位都沒資料時（例如剛上線還沒人來過）連 .cols 外框（虛線分隔＋留白）都不畫，
          // 不然會留一段看起來像版面壞掉的空白。
          $hasAnyCol = $s['spots'] || $s['kinds'] || $s['cameras'] || $feats || $browsers || $oses || $byHour;
        ?>
        <?php if ($hasAnyCol): ?>
        <div class="cols">
          <?php if ($s['spots']): ?>
          <div class="col">
            <h4><?= $t('spots_rank_heading') ?></h4>
            <p class="colnote"><?= $t('spots_rank_note') ?></p>
            <?= $statBars($s['spots'], fn($k) => i18n_t($DICT, 'spot_short_label', ['k' => $k])) ?>
          </div>
          <?php endif; ?>
          <?php if ($s['kinds']): ?>
          <div class="col">
            <h4><?= $t('kinds_rank_heading') ?></h4>
            <p class="colnote"><?= $t('kinds_rank_note') ?></p>
            <?= $statBars($s['kinds'], fn($k) => souliong_kind_label($k)) ?>
          </div>
          <?php endif; ?>
          <?php if ($s['cameras']): ?>
          <div class="col">
            <h4><?= $t('cameras_rank_heading') ?></h4>
            <p class="colnote"><?= $t('cameras_rank_note') ?></p>
            <?= $statBars($s['cameras'], fn($k) => (string)$k) ?>
          </div>
          <?php endif; ?>
          <?php if ($feats): ?>
          <div class="col">
            <h4><?= $t('feature_usage_heading') ?></h4>
            <p class="colnote"><?= $t('feature_usage_note') ?></p>
            <?= $statBars($feats, fn($k) => $featLabels[$k] ?? $k) ?>
          </div>
          <?php endif; ?>
          <?php if ($browsers || $oses): ?>
          <div class="col">
            <h4><?= $t('browser_os_heading') ?></h4>
            <p class="colnote"><?= $t('browser_os_note') ?></p>
            <?php if ($browsers): ?><div class="statsub"><?= $t('browser_label') ?></div><?= $statBars($browsers, fn($k) => $bLabels[$k] ?? $k, 8) ?><?php endif; ?>
            <?php if ($oses): ?><div class="statsub"><?= $t('os_label') ?></div><?= $statBars($oses, fn($k) => $oLabels[$k] ?? $k, 8) ?><?php endif; ?>
          </div>
          <?php endif; ?>
          <?php if ($byHour): ?>
          <div class="col wide">
            <h4><?= $t('visit_time_heading') ?></h4>
            <p class="colnote"><?= $t('visit_time_note') ?></p>
            <?= $statCols($hourCells, $hourAria) ?>
            <?= $statCols($dowCells, $dowAria) ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div><!-- /pane-overview -->

    <div class="pane" id="pane-records">
    <h2><?= $t('records_heading') ?></h2>
    <div class="searchbar"><i class="fa-solid fa-magnifying-glass"></i><input id="recsearch" type="search" placeholder="<?= $t('records_search_placeholder') ?>" autocomplete="off"></div>
    <div class="tablewrap">
      <table id="rectable">
        <tr>
          <th><?= $t('col_num') ?></th>
          <th><?= $t('col_project') ?></th>
          <th><?= $t('col_spot') ?></th>
          <th><?= $t('col_type') ?></th>
          <th><?= $t('col_photo') ?></th>
          <th><?= $t('col_nickname') ?></th>
          <th><?= $t('col_content') ?></th>
          <th><?= $t('col_camera') ?></th>
          <th><?= $t('col_time') ?></th>
          <th><?= $t('col_coords') ?></th>
          <th>o/s</th>
          <th></th>
        </tr>
        <?php $idx = count($rows);
        foreach ($rows as $r):
          $refOrig = !empty($r['edit_of']) ? ($byId[$r['project'] . '/' . $r['edit_of']] ?? null) : null;
          $dispPhoto = !empty($r['photo']) ? $r['photo'] : ($refOrig['photo'] ?? null);
          $dispThumb = !empty($r['thumb']) ? $r['thumb'] : ($refOrig['thumb'] ?? null);
          $dispExif  = is_array($r['exif'] ?? null) ? $r['exif'] : (is_array($refOrig['exif'] ?? null) ? $refOrig['exif'] : null);
          $photoUrl  = $dispPhoto ? Route::api('photo', ['f' => $dispPhoto]) : null;
          $thumbUrl  = $dispThumb ? Route::api('photo', ['f' => $dispThumb]) : ($photoUrl !== null ? $photoUrl . '&th=1' : null);   // 縮圖預覽，點開連到原圖；舊投稿沒 thumb 欄位就請 photo.php 自動產（&th=1）
        ?>
          <tr data-row>
            <td class="mono"><?= $idx-- ?></td>
            <td><?= $esc($r['project']) ?></td>
            <td><?= $esc($r['item_num'] ?? '') ?></td>
            <td><span class="tagcell"><span class="tag"><?= $esc(souliong_kind_label($r['kind'] ?? 'photo')) ?></span><?= !empty($r['edit_of']) ? '<span class="tag">' . $t('edited_record_tag') . '</span>' : '' ?></span></td>
            <td><?= $photoUrl ? '<a href="' . $esc($photoUrl) . '" target="_blank"><img loading="lazy" src="' . $esc($thumbUrl) . '" alt=""></a>' : '' ?></td>
            <td><?= $esc($r['name'] ?? '') ?></td>
            <td><?= nl2br($esc($r['comment'] ?? '')) ?></td>
            <td class="mono"><?= $esc($dispExif ? implode(' ', $dispExif) : '') ?></td>
            <td class="mono"><?= $esc(substr((string)($r['photo_time'] ?? $r['created_at'] ?? ''), 0, 16)) ?></td>
            <td class="mono"><?= $esc(round((float)($r['lat'] ?? 0), 4)) ?>,<?= $esc(round((float)($r['lon'] ?? 0), 4)) ?><br><?= $esc($r['loc_source'] ?? '') ?></td>
            <td class="mono"><?= $esc($short($r['owner_hash'] ?? '')) ?><br><?= $esc($short($r['src_hash'] ?? '')) ?></td>
            <?php
              // 「全部」檢視時各專案投稿混排在同一張表，只寫「確定刪除？」容易點錯專案，訊息裡把專案名複述一次
              $delConfirm = i18n_t($DICT, 'confirm_delete_record', ['project' => $r['project'], 'name' => (($r['name'] ?? '') !== '' ? '（' . $r['name'] . '）' : '')]);
            ?>
            <td>
              <form method="post" onsubmit="return confirm(<?= $esc(json_encode($delConfirm, JSON_UNESCAPED_UNICODE)) ?>)"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="delete">
                <input type="hidden" name="project" value="<?= $esc($r['project']) ?>"><input type="hidden" name="id" value="<?= $esc($r['id'] ?? '') ?>">
                <button class="iconbtn" title="<?= $t('delete_record_title') ?>"><i class="fa-solid fa-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <div class="hint"><?= $t('record_hash_legend') ?><?= $primary ? $t('primary_scope_note') : '' ?></div>
    </div><!-- /pane-records -->

    <?php if ($sitewideOnly): ?>
    <div class="pane" id="pane-tools">
      <h2><?= $t('tools_heading') ?></h2>
      <div class="card section-card">
        <div class="badge"><i class="fa-solid fa-hard-drive"></i> <?= $t('storage_heading') ?></div>
        <?php if ($storageCache !== null):
          $lySite = array_sum($storageCache['layers'][''] ?? []);
          $pkSite = array_sum($storageCache['packs'][''] ?? []);
          $upAll = $lyProj = $pkProj = 0;
          foreach (store_projects($cfg) as $sp2) {
            $u2 = $storageCache['uploads'][$sp2] ?? ['photos' => 0, 'media' => 0];
            $upAll += (int)($u2['photos'] ?? 0) + (int)($u2['media'] ?? 0);
            $lyProj += array_sum($storageCache['layers'][$sp2] ?? []);
            $pkProj += array_sum($storageCache['packs'][$sp2] ?? []);
          }
          $grandTotal = $lySite + $pkSite + $upAll + $lyProj + $pkProj;
        ?>
        <div class="hint" style="margin-top:6px"><?= i18n_t($DICT, 'storage_summary_hint', ['total' => $fmtBytes($grandTotal), 'when' => date('Y-m-d H:i', (int)($storageCache['computed_at'] ?? 0))]) ?></div>
        <div class="stats-grid" style="margin-top:8px">
          <div class="tile">
            <div class="n"><?= $esc($fmtBytes($upAll)) ?></div>
            <div class="l"><?= $t('storage_uploads_label') ?></div>
          </div>
          <div class="tile">
            <div class="n"><?= $esc($fmtBytes($lyProj + $lySite)) ?></div>
            <div class="l"><?= $t('storage_layers_label') ?></div>
          </div>
          <div class="tile">
            <div class="n"><?= $esc($fmtBytes($pkProj + $pkSite)) ?></div>
            <div class="l"><?= $t('storage_packs_label') ?></div>
          </div>
        </div>
        <?php else: ?>
        <div class="hint" style="margin-top:6px"><?= $t('storage_never_computed_msg') ?></div>
        <?php endif; ?>
        <form method="post" style="margin-top:8px">
          <input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="storagerecalc">
          <button class="btn"><i class="fa-solid fa-rotate"></i> <?= $t('storage_recalc_btn') ?></button>
        </form>
      </div>
      <div class="card section-card">
        <div class="badge"><i class="fa-solid fa-toggle-on"></i> <?= $t('global_features_badge') ?></div>
        <div class="hint" style="margin-top:6px"><?= $t('global_features_hint') ?></div>
        <form method="post" style="margin-top:8px">
          <input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="settings">
          <label class="modrow">
            <input type="checkbox" name="random_explore" <?= souliong_random_explore_on($cfg) ? 'checked' : '' ?>>
            <span><b><?= $t('random_explore_toggle_label') ?></b><br><span class="hint"><?= $t('random_explore_toggle_hint') ?></span></span>
          </label>
          <label class="modrow" style="margin-top:var(--sp-3)">
            <input type="checkbox" name="registration_open" <?= souliong_registration_open($cfg) ? 'checked' : '' ?>>
            <span><b><?= $t('registration_open_toggle_label') ?></b><br><span class="hint"><?= $t('registration_open_toggle_hint') ?></span></span>
          </label>
          <?php $sitePackCur = souliong_site_pack($cfg); $installedPacks = souliong_pack_list($cfg); ?>
          <label class="setrow"><b><?= $t('site_pack_label') ?></b>
            <select name="site_pack">
              <option value=""><?= $t('no_pack_option') ?></option>
              <?php foreach ($installedPacks as $pid => $pinfo): ?>
              <option value="<?= $esc($pid) ?>" <?= $sitePackCur === $pid ? 'selected' : '' ?>><?= $esc($pinfo['label'] ?? $pid) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <div class="hint"><?= $t('site_pack_hint') ?></div>
          <div class="dlgactions" style="justify-content:flex-start;margin-top:8px"><button class="btn solid"><i class="fa-solid fa-floppy-disk"></i> <?= $t('save_settings_btn') ?></button></div>
        </form>
      </div>
      <form class="card section-card danger-zone" method="post" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="import">
        <span class="badge"><i class="fa-solid fa-upload"></i> <?= $t('import_backup_badge') ?></span>
        <label class="btn" style="cursor:pointer"><i class="fa-solid fa-folder-open"></i> <span data-file><?= $t('choose_zip_btn') ?></span>
          <input type="file" name="backup" accept=".zip" required hidden onchange="this.parentNode.querySelector('[data-file]').textContent=this.files[0]?this.files[0].name:<?= json_encode(i18n_t($DICT, 'choose_zip_btn'), JSON_UNESCAPED_UNICODE) ?>"></label>
        <select name="mode">
          <option value="merge"><?= $t('import_mode_merge') ?></option>
          <option value="replace"><?= $t('import_mode_replace') ?></option>
        </select>
        <button class="btn"><?= $t('restore_btn') ?></button>
      </form>
      <div class="card section-card">
        <div class="badge"><i class="fa-solid fa-kit-medical"></i> <?= $t('data_repair_badge') ?></div>
        <div class="hint" style="margin-top:6px"><?= $t('data_repair_hint') ?></div>
        <?php // 目前在看哪個專案就帶著走；「全部」時不硬塞一個專案給工具頁 ?>
        <div class="row" style="margin-top:8px"><a class="btn" href="<?= $esc(Route::tool('exiffix', $scopeProject)) ?>"><i class="fa-solid fa-kit-medical"></i> <?= $t('open_exiffix_btn') ?></a>
          <a class="btn" href="<?= $esc(Route::tool('thumbfix', $scopeProject)) ?>"><i class="fa-solid fa-images"></i> <?= $t('open_thumbfix_btn') ?></a>
          <a class="btn" href="<?= $esc(Route::tool('tilecut', $scopeProject)) ?>"><i class="fa-solid fa-scissors"></i> <?= $t('open_tilecut_btn') ?></a>
          <a class="btn" href="<?= $esc(Route::tool('spotmigrate', $scopeProject)) ?>"><i class="fa-solid fa-chair"></i> <?= $t('open_spotmigrate_btn') ?></a>
          <a class="btn" href="<?= $esc(Route::backupAll()) ?>"><i class="fa-solid fa-download"></i> <?= $t('backup_all_btn') ?></a></div>
      </div>
      <div class="card section-card">
        <div class="badge"><i class="fa-solid fa-swatchbook"></i> <?= $t('packs_heading') ?></div>
        <div class="hint" style="margin-top:6px"><?= $t('packs_hint') ?></div>
        <?php if ($installedPacks): ?>
        <div style="display:flex;flex-direction:column;gap:6px;margin-top:10px">
          <?php foreach ($installedPacks as $pid => $pinfo): ?>
          <div style="display:flex;align-items:center;gap:8px;justify-content:space-between">
            <span><b><?= $esc($pinfo['label'] ?? $pid) ?></b> <span class="hint mono"><?= $esc($pid) ?></span></span>
            <?php $packAdminRow($pid, ""); ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="hint" style="margin-top:8px"><?= $t('no_packs_msg') ?></div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px">
          <input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="packimport">
          <span class="badge"><i class="fa-solid fa-upload"></i> <?= $t('pack_import_badge') ?></span>
          <label class="btn" style="cursor:pointer"><i class="fa-solid fa-folder-open"></i> <span data-file><?= $t('choose_zip_btn') ?></span>
            <input type="file" name="pack" accept=".zip" required hidden onchange="this.parentNode.querySelector('[data-file]').textContent=this.files[0]?this.files[0].name:<?= json_encode(i18n_t($DICT, 'choose_zip_btn'), JSON_UNESCAPED_UNICODE) ?>"></label>
          <button class="btn"><i class="fa-solid fa-upload"></i> <?= $t('restore_btn') ?></button>
        </form>
      </div>
      <div class="card section-card">
        <div class="badge"><i class="fa-solid fa-layer-group"></i> <?= $t('layers_heading') ?></div>
        <div class="hint" style="margin-top:6px"><?= $t('site_layers_hint') ?></div>
        <?php $siteLayers = souliong_layer_list($cfg); ?>
        <?php if ($siteLayers): ?>
        <div class="lylist" style="margin-top:10px">
          <?php foreach ($siteLayers as $lid => $linfo): ?>
          <div class="lyrow">
            <span style="flex:1 1 auto;min-width:0;font-size:0.8125rem"><b><?= $esc($linfo['label'] ?? $lid) ?></b> <span class="hint mono"><?= $esc($lid) ?> · <?= $esc($linfo['type'] ?? 'raster') ?></span></span>
            <?php $layerAdminRow($lid, $linfo, ""); ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="hint" style="margin-top:8px"><?= $t('no_layers_msg') ?></div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px">
          <input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="layerimport">
          <span class="badge"><i class="fa-solid fa-upload"></i> <?= $t('layer_import_badge') ?></span>
          <label class="btn" style="cursor:pointer"><i class="fa-solid fa-folder-open"></i> <span data-file><?= $t('choose_zip_btn') ?></span>
            <input type="file" name="layer" accept=".zip" required hidden onchange="this.parentNode.querySelector('[data-file]').textContent=this.files[0]?this.files[0].name:<?= json_encode(i18n_t($DICT, 'choose_zip_btn'), JSON_UNESCAPED_UNICODE) ?>"></label>
          <label style="display:inline-flex;align-items:center;gap:6px;font-size:0.8125rem"><input type="checkbox" name="overwrite" value="1"> <?= $t('layer_import_overwrite_label') ?></label>
          <button class="btn"><i class="fa-solid fa-upload"></i> <?= $t('restore_btn') ?></button>
        </form>
        <div class="row" style="margin:10px 0 2px">
          <a class="btn" href="<?= $esc(Route::tool('layermigrate', $scopeProject)) ?>"><i class="fa-solid fa-snowflake"></i> <?= $t('open_layermigrate_btn') ?></a>
          <span class="hint"><?= $t('layermigrate_entry_hint') ?></span>
        </div>
      </div>
    </div><!-- /pane-tools -->
    <?php endif; ?>
  </div>
  <script>window.I18N = <?= json_encode($DICT, JSON_UNESCAPED_UNICODE) ?>; window.LANG = <?= json_encode($LANG) ?>;</script>
  <script>
    <?php readfile(__DIR__ . '/../assets/js/vendor/qrcode-generator.js'); ?>
  </script>
  <script>
    <?php readfile(__DIR__ . '/../assets/js/pin-input.js'); ?>
  </script>
  <script>
    // 圖層排序：送出時 layers[] 就是 DOM 由上到下的順序，所以「排序」＝把整列搬位置，
    // 不需要任何隱藏的序號欄位。沒勾的列一樣能搬，只是不會被送出去。
    //
    // 掛的是 .lysort 而不是 .lylist：.lylist 純粹是「一疊列」的外觀，專案層清單也在用，
    // 但那一份沒有上下鍵。行為要自己的鉤子，不能跟外觀共用同一個 class。
    document.querySelectorAll('.lysort').forEach(function(list) {
      function refresh() {
        var rows = list.querySelectorAll('.lyrow');
        rows.forEach(function(row, i) {
          row.querySelector('[data-lymove="-1"]').disabled = (i === 0);
          row.querySelector('[data-lymove="1"]').disabled = (i === rows.length - 1);
        });
      }
      list.addEventListener('click', function(ev) {
        var btn = ev.target.closest('[data-lymove]');
        if (!btn) return;
        var row = btn.closest('.lyrow');
        var sib = btn.dataset.lymove === '-1' ? row.previousElementSibling : row.nextElementSibling;
        if (!sib) return;
        if (btn.dataset.lymove === '-1') list.insertBefore(row, sib);
        else list.insertBefore(sib, row);
        refresh();
        btn.focus();   // 連按時焦點要跟著那一列走，不然第二下會落在別層上
      });
      refresh();
    });
    document.querySelectorAll('.qr').forEach(function(el) {
      try {
        var qr = qrcode(0, 'M');
        qr.addData(el.dataset.url);
        qr.make();
        el.innerHTML = qr.createSvgTag({
          cellSize: 4,
          margin: 2,
          scalable: true
        });
      } catch (e) {
        el.textContent = <?= json_encode(i18n_t($DICT, 'qr_generate_failed'), JSON_UNESCAPED_UNICODE) ?>;
        return;
      }
      el.title = <?= json_encode(i18n_t($DICT, 'qr_click_hint'), JSON_UNESCAPED_UNICODE) ?>;
      el.addEventListener('click', function() { openQrModal(el.dataset.url, el.dataset.title, el.dataset.code); });
    });
    document.querySelectorAll('.qr-trigger').forEach(function(btn) {
      btn.addEventListener('click', function() { openQrModal(btn.dataset.url, btn.dataset.title, btn.dataset.code); });
    });
    function openQrModal(url, title, codeText) {
      var wrap = document.createElement('div');
      wrap.className = 'qr-modal';
      wrap.innerHTML = '<div class="qr-modal-card">'
        + '<button type="button" class="qr-modal-close" aria-label="' + <?= json_encode(i18n_t($DICT, 'close'), JSON_UNESCAPED_UNICODE) ?> + '"><i class="fa-solid fa-xmark"></i></button>'
        + (title ? '<div class="qr-modal-title"></div>' : '')
        + '<div class="qr-modal-box"></div>'
        + (codeText ? '<div class="qr-modal-code"></div>' : '')
        + '<div class="qr-modal-url"></div></div>';
      if (title) wrap.querySelector('.qr-modal-title').textContent = title;
      wrap.querySelector('.qr-modal-url').textContent = url;
      if (codeText) wrap.querySelector('.qr-modal-code').textContent = codeText;
      try {
        var qr = qrcode(0, 'M');
        qr.addData(url);
        qr.make();
        wrap.querySelector('.qr-modal-box').innerHTML = qr.createSvgTag({ cellSize: 8, margin: 2, scalable: true });
      } catch (e) {}
      function close() { wrap.remove(); document.removeEventListener('keydown', onKey); }
      function onKey(e) { if (e.key === 'Escape') close(); }
      // 點卡片本身不關（碼要能選取複製），只有點背景或右上角關閉鈕才關
      wrap.addEventListener('click', function(e) { if (e.target === wrap) close(); });
      wrap.querySelector('.qr-modal-close').addEventListener('click', close);
      document.addEventListener('keydown', onKey);
      document.body.appendChild(wrap);
      wrap.querySelector('.qr-modal-close').focus();
    }
    document.querySelectorAll('.expirywidget').forEach(function(widget) {
      var input = widget.querySelector('.expirycustom');
      var chips = widget.querySelectorAll('.chip');
      function pad(n) { return String(n).padStart(2, '0'); }
      function toLocalValue(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes()); }
      function markOn(chip) { chips.forEach(function(c) { c.classList.toggle('on', c === chip); }); }
      chips.forEach(function(chip) {
        chip.addEventListener('click', function() {
          var preset = chip.dataset.preset;
          if (preset === 'none') { input.value = ''; }
          else {
            var ms = { '1h': 3600e3, '1d': 86400e3, '1w': 604800e3 }[preset] || 0;
            input.value = toLocalValue(new Date(Date.now() + ms));
          }
          markOn(chip);
        });
      });
      input.addEventListener('input', function() {
        var noneChip = widget.querySelector('.chip[data-preset="none"]');
        markOn(input.value === '' ? noneChip : null);
      });
      if (input.value === '') markOn(widget.querySelector('.chip[data-preset="none"]'));
    });
    document.querySelectorAll('[data-copy]').forEach(function(btn) {
      btn.addEventListener('click', function() {
        var v = btn.getAttribute('data-copy');
        (navigator.clipboard ? navigator.clipboard.writeText(v) : Promise.reject()).then(function() {
          var t = btn.innerHTML;
          btn.innerHTML = '<i class="fa-solid fa-check"></i> ' + <?= json_encode(i18n_t($DICT, 'copied'), JSON_UNESCAPED_UNICODE) ?>;
          setTimeout(function() { btn.innerHTML = t; }, 1500);
        }).catch(function() { alert(v); });
      });
    });

    // ── 專案卡片列：展開鈕在「橫向捲動」跟「全部換行顯示」間切換，狀態不記憶——
    // 每次進頁面都先給最省空間的捲動列，要看全部再自己展開。──
    document.querySelectorAll('.pcards-toggle').forEach(function(btn) {
      var row = document.getElementById(btn.dataset.target);
      if (!row) return;
      btn.addEventListener('click', function() {
        var on = row.classList.toggle('expanded');
        btn.classList.toggle('on', on);
        btn.setAttribute('aria-expanded', on ? 'true' : 'false');
      });
    });

    // ── 分頁切換：網址 > 表單送出後指定 > 上次停留，重新整理不迷路 ──
    // 分頁全部都已經在同一頁裡渲染好了，切換只是換 class，所以不重新載入；但網址還是要跟著換成
    // 該分頁的正規路徑（一樣由 Route::manager() 產生，前端不自己拼），加書籤與貼給別人才會停在同一頁。
    var PANE_URL = <?= json_encode(array_combine(Route::PANES, array_map(fn($pn) => Route::manager($scopeProject, $pn), Route::PANES)), JSON_UNESCAPED_SLASHES) ?>;
    function showPane(name) {
      if (!document.getElementById('pane-' + name)) return;
      document.querySelectorAll('.subtab').forEach(function(b) { b.classList.toggle('on', b.dataset.pane === name); });
      document.querySelectorAll('.pane').forEach(function(p) { p.classList.toggle('on', p.id === 'pane-' + name); });
      try { sessionStorage.setItem('adminTab', name); } catch (e) {}
      if (PANE_URL[name]) { try { history.replaceState(null, '', PANE_URL[name] + location.search); } catch (e) {} }
    }
    document.querySelectorAll('.subtab').forEach(function(b) {
      b.addEventListener('click', function() { showPane(b.dataset.pane); });
    });
    var forcePane = <?= json_encode($forcePane) ?>;
    // forcePane 已含網址上的分頁；hash 那條是為了舊書籤（#tools 這種）而留著
    var initPane = forcePane || (location.hash || '').replace('#', '');
    if (!document.getElementById('pane-' + initPane)) {
      try { initPane = sessionStorage.getItem('adminTab') || ''; } catch (e) { initPane = ''; }
    }
    if (initPane) showPane(initPane);

    // ── 投稿紀錄即時搜尋 ──
    var recSearch = document.getElementById('recsearch');
    if (recSearch) recSearch.addEventListener('input', function() {
      var v = recSearch.value.trim().toLowerCase();
      document.querySelectorAll('#rectable tr[data-row]').forEach(function(tr) {
        tr.style.display = !v || tr.textContent.toLowerCase().indexOf(v) !== -1 ? '' : 'none';
      });
    });
  </script>
</body>

</html>