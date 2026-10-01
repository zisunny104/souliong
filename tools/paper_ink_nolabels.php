<?php
// 維護工具（CLI only）：由 layers/paper-ink 的樣式產生 layers/paper-ink-nolabels 的無標籤版本。
// 拿掉所有 symbol 圖層（地名、路名、水域名、門牌、POI 圖示），連用不到的 sprite／glyphs 一併移除。
// 紙墨更新樣式之後重跑一次即可：php tools/paper_ink_nolabels.php
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}
$src = dirname(__DIR__) . '/layers/paper-ink';
$dst = dirname(__DIR__) . '/layers/paper-ink-nolabels';
if (!is_dir($dst) && !mkdir($dst, 0775, true)) {
    fwrite(STDERR, "無法建立 $dst\n");
    exit(1);
}
foreach (['light', 'dark'] as $mode) {
    $st = json_decode((string)file_get_contents("$src/style-$mode.json"), true);
    if (!is_array($st) || !isset($st['layers'])) {
        fwrite(STDERR, "style-$mode.json 讀不出來\n");
        exit(1);
    }
    $before = count($st['layers']);
    $st['layers'] = array_values(array_filter($st['layers'], fn($l) => ($l['type'] ?? '') !== 'symbol'));
    unset($st['sprite'], $st['glyphs']);
    $st['name'] = ($st['name'] ?? 'Paper') . ' (no labels)';
    file_put_contents("$dst/style-$mode.json", json_encode($st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    printf("style-%s.json：%d → %d 層\n", $mode, $before, count($st['layers']));
}
