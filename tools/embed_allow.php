<?php
// 維護工具（CLI only）：管理全站嵌入允許清單 state/embed_origins.json（不進版控，與 config 的
// embed_allowed_origins 取聯集生效）。
//   php tools/embed_allow.php list
//   php tools/embed_allow.php add    https://example.com
//   php tools/embed_allow.php remove https://example.com
// 格式 https://host[:port]（開發可用 http://localhost[:port]），不接受萬用字元與路徑。
// 只動這個 JSON 檔，不改寫 api/config.php；就地寫入，擁有者與權限不變。
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
require_once __DIR__ . '/../api/embedorigins.php';

$cmd = $argv[1] ?? '';
$cfgFile = __DIR__ . '/../api/config.php';
$cfg = is_file($cfgFile) ? (require $cfgFile) : [];
if (empty($cfg['state_dir'])) $cfg['state_dir'] = __DIR__ . '/../state';
$file = embed_origins_file($cfg);

$read = function () use ($file): array {
    $d = json_decode((string)@file_get_contents($file), true);
    return is_array($d) ? array_values(array_filter($d, 'is_string')) : [];
};

if ($cmd === 'list') {
    $fileList = $read();
    $all = embed_origins_site($cfg);
    echo "檔案 {$file}：\n  " . ($fileList ? implode("\n  ", $fileList) : '（空）') . "\n";
    echo "實際生效（含 config 的 embed_allowed_origins）：\n  " . ($all ? implode("\n  ", $all) : '（空：不開放任何來源）') . "\n";
    exit(0);
}
if (!in_array($cmd, ['add', 'remove'], true)) {
    fwrite(STDERR, "usage: php embed_allow.php list | add <origin> | remove <origin>\n");
    exit(1);
}
$origin = embed_origin_normalize(trim($argv[2] ?? ''));
if ($origin === null) {
    fwrite(STDERR, "來源格式不正確：" . ($argv[2] ?? '') . "\n格式必須是 https://網域[:埠]，不能有路徑或萬用字元。\n");
    exit(1);
}

$cur = $read();
if ($cmd === 'add') {
    if (in_array($origin, $cur, true)) { echo "已在清單內：$origin\n"; exit(0); }
    $new = array_merge($cur, [$origin]);
} else {
    if (!in_array($origin, $cur, true)) { echo "檔案清單內沒有：$origin（若是寫在 config 的 embed_allowed_origins，要到 config.php 移除）\n"; exit(0); }
    $new = array_values(array_diff($cur, [$origin]));
}
if (!is_dir(dirname($file))) { fwrite(STDERR, "找不到 state 目錄：" . dirname($file) . "\n"); exit(1); }
if (file_put_contents($file, json_encode($new, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n", LOCK_EX) === false) {
    fwrite(STDERR, "寫入失敗：$file（權限？）\n");
    exit(1);
}
echo ($cmd === 'add' ? '已加入' : '已移除') . "：$origin\n目前檔案清單：\n  " . ($new ? implode("\n  ", $new) : '（空）') . "\n";
