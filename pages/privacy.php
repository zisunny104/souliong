<?php

/**
 * 隱私與資料說明頁（站內、主題感、中英對照）。由路由 <base>/privacy 顯示。
 * 內容為平台通則；不含任何個別使用者資料。
 */
$cfg = @include __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/routes.php';   // 網址表：掛載根路徑的演算法只有這一份（見 api/routes.php）
require_once __DIR__ . '/../api/markdown.php';
$base = Route::base();
$b = htmlspecialchars($base, ENT_QUOTES);

// 內容單一來源：docs/PRIVACY.md。開頭的最後更新日期／給 .md 讀者看的版本說明／
// 開場白這三段頁面已經另外排版（見下方 <h1>／.lead），所以從 site:content 標記之後才算繪。
$md = file_get_contents(__DIR__ . '/../docs/PRIVACY.md') ?: '';
$marker = '<!-- site:content -->';
$pos = strpos($md, $marker);
$body = Markdown::toHtml($pos !== false ? substr($md, $pos + strlen($marker)) : $md, ['heading_offset' => 1]);

$body = preg_replace('/<h3\b/', '<h3 aria-level="2"', $body);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh-Hant">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>隱私與資料說明 · Souliong 循跡</title>
  <script>
    try {
      var t = localStorage.getItem('theme');
      if (t === 'dark' || t === 'light') document.documentElement.dataset.theme = t;
    } catch (e) {}
  </script>
  <style>
    :root {
      color-scheme: light dark;
      --bg: #fafafa;
      --fg: #1b1b1d;
      --muted: #6b6b70;
      --line: #e7e7ea;
      --card: #fff;
      --accent: #1b1b1d;
      --r: 1.25rem
    }

    @media(prefers-color-scheme:dark) {
      :root {
        --bg: #141416;
        --fg: #f1f1f3;
        --muted: #9c9ca3;
        --line: #2e2e31;
        --card: #1d1d20;
        --accent: #f1f1f3
      }
    }

    :root[data-theme=light] {
      --bg: #fafafa;
      --fg: #1b1b1d;
      --muted: #6b6b70;
      --line: #e7e7ea;
      --card: #fff;
      --accent: #1b1b1d
    }

    :root[data-theme=dark] {
      --bg: #141416;
      --fg: #f1f1f3;
      --muted: #9c9ca3;
      --line: #2e2e31;
      --card: #1d1d20;
      --accent: #f1f1f3
    }

    * {
      box-sizing: border-box
    }

    body {
      margin: 0;
      font-family: system-ui, sans-serif;
      background: var(--bg);
      color: var(--fg);
      line-height: 1.75;
      -webkit-font-smoothing: antialiased
    }

    .wrap {
      max-width: 44rem;
      margin: 0 auto;
      padding: 2.5rem 1.25rem 4rem
    }

    a {
      color: inherit
    }

    h1 {
      font-size: 1.6rem;
      font-weight: 800;
      margin: 0 0 .25rem
    }

    .lead {
      color: var(--muted);
      font-size: .95rem;
      margin: 0 0 1.75rem
    }

    h3 {
      font-size: 1.15rem;
      font-weight: 800;
      margin: 2rem 0 .5rem
    }

    p {
      margin: .75rem 0
    }

    ul, ol {
      margin: .4rem 0;
      padding-left: 1.2rem
    }

    li {
      margin: .3rem 0
    }

    blockquote {
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: var(--r);
      margin: .75rem 0;
      padding: 1.1rem 1.3rem
    }

    blockquote p {
      margin: 0
    }

    hr {
      border: none;
      border-top: 1px solid var(--line);
      margin: 2rem 0
    }

    .back {
      display: inline-block;
      margin-bottom: 1.5rem;
      font-size: .9rem;
      color: var(--muted);
      text-decoration: none
    }

    code {
      font-family: ui-monospace, Consolas, monospace;
      font-size: .85em;
      background: var(--line);
      padding: .1em .35em;
      border-radius: .3em
    }

    footer {
      margin-top: 2.5rem;
      padding-top: 1rem;
      border-top: 1px solid var(--line);
      color: var(--muted);
      font-size: .8rem
    }
  </style>
</head>

<body>
  <div class="wrap" role="main">
    <a class="back" href="<?= $b ?>">← 返回地圖列表 · Back</a>
    <h1>隱私與資料說明</h1>
    <p class="lead">Privacy &amp; Data Notice — 我們盡量少收資料、以去識別方式處理，且不使用第三方追蹤或廣告。<br>We collect as little as possible, keep it de-identified, and use no third-party tracking or ads.</p>

    <?= $body ?>

    <footer role="none">© 2026 prjToka · Souliong 循跡 · 本頁為平台通則，各地圖另可能有自訂的投稿說明。</footer>
  </div>
</body>

</html>