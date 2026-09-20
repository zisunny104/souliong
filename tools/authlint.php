<?php
/**
 * 權限單一化的靜態檢查（CLI）：端點檔案不得繞過 api/auth.php 自己解析身分、比對 CSRF 或混用模組旗標。
 *
 * 用法：php tools/authlint.php              掃描全部端點（api/*.php、pages/*.php，不含 auth／security／accounts）
 *       php tools/authlint.php <檔案>...    只掃指定檔案
 * 有任何違規就列出「檔案:行號」並以非零狀態結束。用 PHP tokenizer 掃描，註解與字串裡的字樣不算。
 *
 * 規則：
 *   R1 不得直接呼叫身分／簽章函式（perm_can、perm_check、site_perm、primary_authed、pin_current_id、
 *      account_current、primary_derived、pin_derived、account_derived）——一律用 Auth::require／can／actor。
 *   R2 不得自己 hash_equals csrf——CSRF 只在 Auth::require() 比對。
 *   R3 不得直接讀身分 cookie（souliong_primary／souliong_acct／souliong_pin_*）。
 *   R4 模組旗標（souliong_module_on、$mod()）不得出現在同一個敘述的 Auth 權限判斷裡。
 */
$root = dirname(__DIR__);
const AUTHLINT_EXEMPT = ['api/auth.php', 'api/security.php', 'api/accounts.php'];
const AUTHLINT_FORBIDDEN_CALLS = ['perm_can', 'perm_check', 'site_perm', 'primary_authed', 'pin_current_id',
    'account_current', 'primary_derived', 'pin_derived', 'account_derived'];
const AUTHLINT_COOKIE_WORDS = ['souliong_primary', 'souliong_acct', 'souliong_pin_', 'PRIMARY_COOKIE', 'ACCOUNT_COOKIE'];

$files = [];
if (count($argv) > 1) {
    foreach (array_slice($argv, 1) as $f) { $files[] = str_replace('\\', '/', $f); }
} else {
    foreach (['api', 'pages'] as $d) {
        foreach (scandir($root . '/' . $d) ?: [] as $f) {
            if (str_ends_with($f, '.php')) $files[] = $d . '/' . $f;
        }
    }
}

$hits = [];
foreach ($files as $rel) {
    if (in_array($rel, AUTHLINT_EXEMPT, true)) continue;
    $abs = is_file($rel) ? $rel : $root . '/' . $rel;
    if (!is_file($abs)) { fwrite(STDERR, "找不到檔案：$rel\n"); exit(2); }
    $toks = array_values(array_filter(token_get_all((string)file_get_contents($abs)), fn($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    $n = count($toks);
    $text = fn($t) => is_array($t) ? $t[1] : $t;
    $line = fn($t) => is_array($t) ? $t[2] : 0;
    $stmt = ['auth' => false, 'mod' => false, 'line' => 0];
    for ($i = 0; $i < $n; $i++) {
        $t = $toks[$i];
        $s = $text($t);
        // 敘述邊界：重置 R4 的累積狀態
        if ($s === ';' || $s === '{' || $s === '}') { $stmt = ['auth' => false, 'mod' => false, 'line' => 0]; continue; }
        if (is_array($t) && $t[0] === T_STRING) {
            $next = $toks[$i + 1] ?? null;
            $prev = $toks[$i - 1] ?? null;
            $isCall = $next !== null && $text($next) === '(' && !($prev !== null && in_array($text($prev), ['function', '::', '->', '?->'], true));
            $isMethod = $prev !== null && in_array($text($prev), ['->', '?->'], true);
            if ($isCall && in_array($s, AUTHLINT_FORBIDDEN_CALLS, true)) {
                $hits[] = [$rel, $t[2], "R1 直接呼叫 $s()，改用 Auth::require／Auth::can／Auth::actor"];
            }
            if ($isCall && $s === 'hash_equals') {
                $depth = 0; $found = false;
                for ($j = $i + 1; $j < $n; $j++) {
                    $u = $text($toks[$j]);
                    if ($u === '(') $depth++;
                    elseif ($u === ')') { $depth--; if ($depth === 0) break; }
                    elseif (is_array($toks[$j]) && stripos($u, 'csrf') !== false) $found = true;
                }
                if ($found) $hits[] = [$rel, $t[2], 'R2 自己 hash_equals csrf，CSRF 只能由 Auth::require() 比對'];
            }
            if ($next !== null && $text($next) === '::' && $s === 'Auth') { $stmt['auth'] = true; $stmt['line'] = $stmt['line'] ?: $t[2]; }
            if ($isCall && $s === 'souliong_module_on') { $stmt['mod'] = true; $stmt['line'] = $stmt['line'] ?: $t[2]; }
        }
        if (is_array($t) && $t[0] === T_VARIABLE && $s === '$mod') {
            $next = $toks[$i + 1] ?? null;
            if ($next !== null && $text($next) === '(') { $stmt['mod'] = true; $stmt['line'] = $stmt['line'] ?: $t[2]; }
        }
        if ($stmt['auth'] && $stmt['mod']) {
            $hits[] = [$rel, $stmt['line'], 'R4 模組旗標出現在 Auth 權限判斷的同一個敘述裡（模組開關只管顯示，不進權限鏈）'];
            $stmt = ['auth' => false, 'mod' => false, 'line' => 0];
        }
        // R3：身分 cookie
        if (is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            foreach (AUTHLINT_COOKIE_WORDS as $w) {
                if (strpos($s, $w) !== false) {
                    $prev = $toks[$i - 1] ?? null;
                    if ($t[0] === T_STRING && $prev !== null && $text($prev) === 'define') continue;
                    $hits[] = [$rel, $t[2], "R3 直接使用身分 cookie 名稱 $w"];
                    break;
                }
            }
        }
    }
}

usort($hits, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
foreach ($hits as [$f, $l, $m]) echo "$f:$l  $m\n";
$scanned = count(array_filter($files, fn($f) => !in_array($f, AUTHLINT_EXEMPT, true)));
if ($scanned === 0) { fwrite(STDERR, "authlint：沒有掃描到任何檔案\n"); exit(2); }
echo ($hits ? 'authlint：有 ' . count($hits) . ' 處違規' : 'authlint：通過') . "（掃描 $scanned 個檔案）\n";
exit($hits ? 1 : 0);
