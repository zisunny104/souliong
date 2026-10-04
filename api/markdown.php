<?php

/**
 * Souliong 的 Markdown → HTML —— 全站唯一一份「.md 怎麼變成畫面」的定義。
 *
 * 為什麼自己寫而不是拉一個套件：整個平台沒有 composer，也刻意不供應靜態檔，
 * 為了幾份說明文件扛一包相依不划算。這裡只做文件實際用得到的語法子集，
 * 認不得的一律當純文字——說明書排版跑掉是小事，把內容當成標記解讀才是問題。
 *
 * 用途：頁面直接算繪 .md，文件就是畫面，說明文字只維護一份。
 *
 * 安全模型：來源一律當成不可信。原始 HTML **不透傳**，全部逸出之後才組標籤；
 * 連結只放行 http／https／mailto 與站內相對路徑，其餘（`javascript:` 之類）退回純文字。
 * 所以就算哪天把使用者寫的內容餵進來，也不會變成 XSS。已經寫成實體的 `&amp;`
 * 不會被二次逸出（`double_encode` 關掉），否則作者寫 `&amp;` 會在畫面上看到 `&amp;amp;`。
 *
 * 支援：ATX 標題、段落、圍欄程式碼、行內程式碼、粗體、斜體、連結、清單（可縮排一層）、
 * 表格、引言、水平線。列表與表格是逐行狀態機，不是先照空行切塊——文件裡的表格與
 * 清單常常上下都黏著文字，照空行切會把它們切碎。
 *
 * 換行就是換行：段落內的單一換行預設算繪成 `<br>`（`soft_breaks` 可關）。專案的說明
 * 文件都是一行一句、中文一行英文一行，照 CommonMark 把它們併成一段會讓中英文黏在一起。
 */

final class Markdown
{
    /** 連結放行的協定。其餘一律退回純文字，包含 `javascript:`、`data:`。 */
    private const SAFE_SCHEMES = ['http', 'https', 'mailto'];

    /**
     * 把一段 Markdown 算繪成 HTML 片段（不含 <html>／<body> 外殼）。
     *
     * @param array{skip_h1?:bool,heading_ids?:bool,heading_offset?:int,soft_breaks?:bool} $opt
     *        skip_h1        略過第一個 # 標題（頁面自己有標題時用）
     *        heading_ids    幫標題加 id，讓目錄能連過去（預設開）
     *        heading_offset 標題整體降級，例如 1 表示 # 變成 <h2>
     *        soft_breaks    段落內換行算繪成 <br>（預設開，見檔頭說明）
     */
    public static function toHtml(string $md, array $opt = []): string
    {
        $skipH1  = (bool)($opt['skip_h1'] ?? false);
        $withIds = (bool)($opt['heading_ids'] ?? true);
        $offset  = max(0, min(5, (int)($opt['heading_offset'] ?? 0)));
        $soft    = (bool)($opt['soft_breaks'] ?? true);

        $lines = explode("\n", str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $md));
        $out   = [];
        $n     = count($lines);
        $i     = 0;
        $seen  = [];          // 標題 id 撞名時加序號
        $para  = [];          // 累積中的段落

        // 段落是「遇到別的東西才結束」，所以每個分支開頭都要先把它吐掉
        $flush = function () use (&$para, &$out, $soft): void {
            if ($para === []) {
                return;
            }
            $out[] = '<p>' . self::join($para, $soft) . '</p>';
            $para = [];
        };

        while ($i < $n) {
            $line = $lines[$i];
            $trim = trim($line);

            // ── 圍欄程式碼：裡面一個字都不能解讀，連結束圍欄都要照原樣比對 ──
            if (preg_match('/^```+\s*([A-Za-z0-9_+-]*)\s*$/', $trim, $m)) {
                $flush();
                $lang = $m[1];
                $buf  = [];
                $i++;
                while ($i < $n && !preg_match('/^```+\s*$/', trim($lines[$i]))) {
                    $buf[] = $lines[$i];
                    $i++;
                }
                $i++;   // 吃掉收尾圍欄；沒收尾就是讀到檔尾，照樣結束
                $cls = $lang !== '' ? ' class="language-' . self::esc($lang) . '"' : '';
                $out[] = '<pre><code' . $cls . '>' . self::esc(implode("\n", $buf)) . "\n</code></pre>";
                continue;
            }

            // ── 空行：段落結束 ──
            if ($trim === '') {
                $flush();
                $i++;
                continue;
            }

            // ── 水平線（要先於清單判斷，`---` 也長得像 `-` 開頭）──
            if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $trim)) {
                $flush();
                $out[] = '<hr>';
                $i++;
                continue;
            }

            // ── 標題 ──
            if (preg_match('/^(#{1,6})\s+(.*)$/', $trim, $m)) {
                $flush();
                $lv   = min(6, strlen($m[1]) + $offset);
                $text = rtrim($m[2], " #");
                if ($skipH1 && strlen($m[1]) === 1) {
                    $skipH1 = false;      // 只吃掉第一個
                    $i++;
                    continue;
                }
                $id = '';
                if ($withIds) {
                    $slug = self::slug($text);
                    if ($slug !== '') {
                        $seen[$slug] = ($seen[$slug] ?? 0) + 1;
                        $id = ' id="' . self::esc($slug . ($seen[$slug] > 1 ? '-' . $seen[$slug] : '')) . '"';
                    }
                }
                $out[] = "<h$lv$id>" . self::inline($text) . "</h$lv>";
                $i++;
                continue;
            }

            // ── 表格：現在這行有 |，下一行是分隔列 ──
            if (strpos($line, '|') !== false && $i + 1 < $n && self::isTableRule($lines[$i + 1])) {
                $flush();
                $out[] = self::table($lines, $i, $n);
                continue;
            }

            // ── 引言 ──
            if (preg_match('/^>\s?(.*)$/', $line, $m)) {
                $flush();
                $buf = [];
                while ($i < $n && preg_match('/^>\s?(.*)$/', $lines[$i], $mm)) {
                    $buf[] = $mm[1];
                    $i++;
                }
                $out[] = '<blockquote>' . self::toHtml(implode("\n", $buf), $opt + ['heading_ids' => false]) . '</blockquote>';
                continue;
            }

            // ── 清單 ──
            if (preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $line)) {
                $flush();
                $out[] = self::list_($lines, $i, $n, 0, $soft);
                continue;
            }

            $para[] = $line;
            $i++;
        }
        $flush();

        return implode("\n", $out);
    }

    // ── 以下為內部實作 ────────────────────────────────────────────────

    /** 分隔列：`|---|:--:|` 這種。只有它能把一堆含 | 的行變成表格。 */
    private static function isTableRule(string $line): bool
    {
        return (bool)preg_match('/^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/', $line);
    }

    /** @param string[] $lines */
    private static function table(array $lines, int &$i, int $n): string
    {
        $cells = static function (string $row): array {
            $row = trim($row);
            $row = preg_replace('/^\|/', '', $row);
            $row = preg_replace('/\|$/', '', $row);
            return array_map('trim', explode('|', (string)$row));
        };

        $head = $cells($lines[$i]);
        $align = [];
        foreach ($cells($lines[$i + 1]) as $spec) {
            $l = str_starts_with($spec, ':');
            $r = str_ends_with($spec, ':');
            $align[] = $l && $r ? 'center' : ($r ? 'right' : ($l ? 'left' : ''));
        }
        $i += 2;

        $html = ['<table>', '<thead>', '<tr>'];
        foreach ($head as $k => $c) {
            $a = ($align[$k] ?? '') !== '' ? ' style="text-align:' . $align[$k] . '"' : '';
            $html[] = "<th$a>" . self::inline($c) . '</th>';
        }
        $html[] = '</tr>';
        $html[] = '</thead>';
        $html[] = '<tbody>';
        while ($i < $n && trim($lines[$i]) !== '' && strpos($lines[$i], '|') !== false) {
            $html[] = '<tr>';
            foreach ($cells($lines[$i]) as $k => $c) {
                $a = ($align[$k] ?? '') !== '' ? ' style="text-align:' . $align[$k] . '"' : '';
                $html[] = "<td$a>" . self::inline($c) . '</td>';
            }
            $html[] = '</tr>';
            $i++;
        }
        $html[] = '</tbody>';
        $html[] = '</table>';
        return implode('', $html);
    }

    /**
     * 清單。縮排每深 2 格算一層，巢狀就遞迴進去。
     * 一個項目後面接的縮排文字（延續行）併回同一項，這樣項目裡才寫得下整段話。
     *
     * @param string[] $lines
     */
    private static function list_(array $lines, int &$i, int $n, int $depth, bool $soft = true): string
    {
        $items   = [];
        $ordered = null;

        while ($i < $n) {
            if (!preg_match('/^(\s*)([-*+]|\d+[.)])\s+(.*)$/', $lines[$i], $m)) {
                break;
            }
            $indent = strlen($m[1]);
            $lv     = intdiv($indent, 2);
            if ($lv < $depth) {
                break;                       // 回到外層，交給呼叫者
            }
            if ($lv > $depth) {              // 更深一層：整包遞迴，塞進上一項的 <li> 裡面
                $sub = self::list_($lines, $i, $n, $lv, $soft);
                if ($items === []) {
                    $items[] = '<li>' . $sub . '</li>';     // 沒有上一項可掛（縮排開頭），自成一項
                } else {
                    // 巢狀清單要在 </li> 之前，當兄弟節點是不合法的 HTML
                    $last = array_pop($items);
                    $items[] = preg_replace('#</li>$#', $sub . '</li>', $last, 1);
                }
                continue;
            }

            $isOrdered = (bool)preg_match('/^\d/', $m[2]);
            $ordered ??= $isOrdered;
            $body = [$m[3]];
            $i++;

            // 延續行：比項目本身更縮排、又不是新項目
            while ($i < $n && trim($lines[$i]) !== ''
                && !preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $lines[$i])
                && strlen($lines[$i]) - strlen(ltrim($lines[$i])) > $indent) {
                $body[] = trim($lines[$i]);
                $i++;
            }
            $items[] = '<li>' . self::join($body, $soft) . '</li>';
        }

        $tag = $ordered ? 'ol' : 'ul';
        return "<$tag>" . implode('', $items) . "</$tag>";
    }

    /** 多行併成一段：$soft 開著就逐行算繪、用 <br> 接起來；關著就整段接起來當一行處理。 */
    private static function join(array $lines, bool $soft): string
    {
        return $soft
            ? implode('<br>' . "\n", array_map([self::class, 'inline'], $lines))
            : self::inline(implode("\n", $lines));
    }

    /**
     * 行內語法。順序有意義：先把行內程式碼切出來，剩下的才套強調與連結，
     * 否則 `**` 寫在反引號裡會被當成粗體。
     */
    private static function inline(string $s): string
    {
        $parts = preg_split('/(`+)/', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out   = '';
        $k     = 0;
        $cnt   = count($parts);

        while ($k < $cnt) {
            $chunk = $parts[$k];
            if ($k % 2 === 0) {                      // 一般文字
                $out .= self::text($chunk);
                $k++;
                continue;
            }
            // 反引號：找同長度的收尾，找不到就當普通字元
            $fence = $chunk;
            $end   = -1;
            for ($j = $k + 2; $j < $cnt; $j += 2) {
                if ($parts[$j] === $fence) {
                    $end = $j;
                    break;
                }
            }
            if ($end === -1) {
                $out .= self::esc($fence);
                $k++;
                continue;
            }
            $code = '';
            for ($j = $k + 1; $j < $end; $j++) {
                $code .= $parts[$j];
            }
            $out .= '<code>' . self::esc($code) . '</code>';
            $k = $end + 1;
        }
        return $out;
    }

    /** 非程式碼的行內文字：逸出之後才套標記，所以標記本身組得出標籤、內容組不出。 */
    private static function text(string $s): string
    {
        $s = self::esc($s);

        // 連結：[文字](網址)。網址過不了白名單就整段退回純文字。
        $s = preg_replace_callback(
            '/\[([^\]]*)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/',
            static function (array $m): string {
                $href = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
                if (!self::safeUrl($href)) {
                    return $m[0];
                }
                $title = ($m[3] ?? '') !== '' ? ' title="' . self::esc($m[3]) . '"' : '';
                $ext   = preg_match('#^[a-z][a-z0-9+.-]*:#i', $href) ? ' rel="noopener noreferrer"' : '';
                return '<a href="' . self::esc($href) . '"' . $title . $ext . '>' . $m[1] . '</a>';
            },
            $s
        ) ?? $s;

        $s = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '<strong>$1</strong>', $s) ?? $s;
        $s = preg_replace('/(?<![\w*])\*(?=\S)([^*]+?)(?<=\S)\*(?![\w*])/s', '<em>$1</em>', $s) ?? $s;
        $s = preg_replace('/(?<!\S)~~(?=\S)(.+?)(?<=\S)~~(?!\S)/s', '<del>$1</del>', $s) ?? $s;

        return $s;
    }

    /** 只放行 http／https／mailto 與站內相對路徑；`javascript:`、`data:` 一律擋。 */
    private static function safeUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        if ($url[0] === '#' || $url[0] === '/' || $url[0] === '?') {
            return true;                                    // 站內
        }
        if (preg_match('#^([a-z][a-z0-9+.-]*):#i', $url, $m)) {
            return in_array(strtolower($m[1]), self::SAFE_SCHEMES, true);
        }
        return !str_contains($url, ':');                     // 相對路徑；含冒號的可疑，擋掉
    }

    /** 標題 id。中文標題保留原字（URL 裡是合法的），只把空白與標點換成連字號。 */
    private static function slug(string $text): string
    {
        $t = preg_replace('/`([^`]*)`/u', '$1', $text) ?? $text;      // 去掉行內程式碼記號
        $t = html_entity_decode($t, ENT_QUOTES, 'UTF-8');             // 作者寫的 &amp; 不該留在 id 裡
        $t = preg_replace('/[*_~\[\]()#&]/u', '', $t) ?? $t;
        $t = preg_replace('/[\s\/\\\\.,:;!?"\'。，、：；！？「」（）·]+/u', '-', trim($t)) ?? $t;
        return trim(mb_strtolower($t, 'UTF-8'), '-');
    }

    /** 已經是實體的 `&amp;` 不再逸出一次，否則作者寫 `&amp;` 會看到 `&amp;amp;`。 */
    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8', false);
    }
}
