<?php
/**
 * 一次跑完全部檢查（CLI）：php -l（全專案 php）、authlint、authcheck、contentcheck，
 * 環境裡有 node 時再加 node --check（assets/js，不含 vendor）。
 *
 * 用法：php tools/checkall.php
 * 全部通過結束碼 0；任何一項失敗結束碼 1，最後列出每一項的結果與失敗摘要。
 * 不碰 projects/ 與 state/ 的內容：php -l 只掃程式碼，authcheck／contentcheck 各自在臨時沙盒跑。
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}
$root = dirname(__DIR__);
$php = escapeshellarg(PHP_BINARY);
// 這些目錄放的是資料或第三方檔案，不是本專案的程式碼
const CHECKALL_SKIP_DIRS = ['.git', 'node_modules', 'vendor', 'projects', 'state'];

/** @return string[] 相對於 $root 的檔案路徑 */
function checkall_files(string $root, string $ext): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        fn(SplFileInfo $f) => !($f->isDir() && in_array($f->getFilename(), CHECKALL_SKIP_DIRS, true))
    ));
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === $ext) {
            $out[] = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($root))), '/');
        }
    }
    sort($out);
    return $out;
}

/** 執行一條指令，回傳 [結束碼, 輸出] */
function checkall_run(string $cmd, string $cwd): array
{
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd);
    if (!is_resource($p)) return [1, "無法執行：$cmd"];
    $o = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [proc_close($p), trim((string)$o)];
}

/** 逐檔跑語法檢查；$cmdFor 依檔名組指令 */
function checkall_each(string $root, array $files, callable $cmdFor): array
{
    $bad = [];
    foreach ($files as $f) {
        [$code, $out] = checkall_run($cmdFor($f), $root);
        if ($code !== 0) $bad[] = $f . "\n" . $out;
    }
    return [$bad ? 1 : 0, $bad ? implode("\n", $bad) : '', count($files)];
}

$results = [];   // [名稱, 結束碼, 細節, 說明]

$phpFiles = checkall_files($root, 'php');
[$c, $o, $n] = checkall_each($root, $phpFiles, fn($f) => "$php -l " . escapeshellarg($f));
$results[] = ["php -l（$n 個檔案）", $c, $o];

foreach (['authlint', 'authcheck', 'contentcheck'] as $tool) {
    [$c, $o] = checkall_run("$php " . escapeshellarg("tools/$tool.php"), $root);
    $results[] = [$tool, $c, $c === 0 ? '' : $o];
}

[$nodeCode, $nodeVer] = checkall_run('node --version', $root);
if ($nodeCode === 0) {
    $jsFiles = checkall_files($root . '/assets/js', 'js');
    $jsFiles = array_map(fn($f) => 'assets/js/' . $f, $jsFiles);
    [$c, $o, $n] = checkall_each($root, $jsFiles, fn($f) => 'node --check ' . escapeshellarg($f));
    $results[] = ["node --check（$n 個檔案）", $c, $o];
} else {
    echo "（略過 node --check：找不到 node）\n";
}

$failed = 0;
echo "\n===== checkall 摘要 =====\n";
foreach ($results as [$name, $code, $detail]) {
    echo ($code === 0 ? '通過  ' : '失敗  ') . $name . "\n";
    if ($code !== 0) {
        $failed++;
        // 失敗細節只留尾段，完整輸出請單獨執行該工具
        $lines = explode("\n", $detail);
        $tail = array_slice($lines, -15);
        echo '      ' . implode("\n      ", $tail) . "\n";
    }
}
echo $failed ? "\n$failed 項失敗\n" : "\n全部通過\n";
exit($failed ? 1 : 0);
