<?php
// 維護工具（CLI only）：管理 api/config.php 的 embed_allowed_origins（全站嵌入允許清單）。
//   php tools/embed_allow.php list
//   php tools/embed_allow.php add    https://example.com
//   php tools/embed_allow.php remove https://example.com
// 格式 https://host[:port]（開發可用 http://localhost[:port]），不接受萬用字元與路徑。
// 改寫前先留一份 config.php.bak-<時間>；就地寫入，擁有者與權限不變。
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
$cmd = $argv[1] ?? '';
$origin = rtrim(trim($argv[2] ?? ''), '/');
$file = (getenv('SOULIONG_CONFIG') ?: __DIR__ . '/../api/config.php');
if (!is_file($file)) { fwrite(STDERR, "找不到 $file\n"); exit(1); }
$cfg = require $file;
$cur = array_values(array_filter((array)($cfg['embed_allowed_origins'] ?? []), 'is_string'));

if ($cmd === 'list') {
    echo $cur ? implode("\n", $cur) . "\n" : "（空：不開放任何來源）\n";
    exit(0);
}
if (!in_array($cmd, ['add', 'remove'], true)) {
    fwrite(STDERR, "usage: php embed_allow.php list | add <origin> | remove <origin>\n");
    exit(1);
}
if (!preg_match('#^(https://[a-z0-9]([a-z0-9.-]*[a-z0-9])?|http://localhost)(:\d{1,5})?$#i', $origin)) {
    fwrite(STDERR, "來源格式不正確：$origin\n格式必須是 https://網域[:埠]，不能有路徑或萬用字元。\n");
    exit(1);
}
$origin = strtolower($origin);
$new = $cur;
if ($cmd === 'add') {
    if (in_array($origin, $cur, true)) { echo "已在清單內：$origin\n"; exit(0); }
    $new[] = $origin;
} else {
    if (!in_array($origin, $cur, true)) { echo "清單內沒有：$origin\n"; exit(0); }
    $new = array_values(array_diff($cur, [$origin]));
}
$literal = $new ? "[\n        " . implode(",\n        ", array_map(fn($o) => var_export($o, true), $new)) . ",\n    ]" : '[]';

$src = file_get_contents($file);
$key = "'embed_allowed_origins'";
if (preg_match("/'embed_allowed_origins'\s*=>\s*\[[^\]]*\]/s", $src, $m)) {
    $out = str_replace($m[0], "$key => $literal", $src);
} else {
    $pos = strrpos($src, '];');
    if ($pos === false) { fwrite(STDERR, "config.php 結尾格式不認得，請手動加入。\n"); exit(1); }
    $out = substr($src, 0, $pos) . "    $key => $literal,\n" . substr($src, $pos);
}
$bak = $file . '.bak-' . date('Ymd-His');
if (!copy($file, $bak)) { fwrite(STDERR, "備份失敗，中止。\n"); exit(1); }
file_put_contents($file, $out, LOCK_EX);
$check = [];
exec('php -l ' . escapeshellarg($file) . ' 2>&1', $check, $rc);
if ($rc !== 0) {
    copy($bak, $file);
    fwrite(STDERR, "改寫後語法檢查失敗，已還原。\n" . implode("\n", $check) . "\n");
    exit(1);
}
echo ($cmd === 'add' ? '已加入' : '已移除') . "：$origin\n目前清單：\n  " . ($new ? implode("\n  ", $new) : '（空）') . "\n備份：$bak\n";
