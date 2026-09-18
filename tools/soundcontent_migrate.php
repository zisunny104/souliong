<?php
// 一次性維護工具（CLI only，可重複執行、冪等）：把專案裡透過 feature 精選到某筆音訊投稿的點位，
// 搬成點位自己的原生 content（依 docs/part4-coordination.md 的「音訊正名為點位原生內容」決策）。
// 只對 spots.jsonl 新增一筆 edit_of 覆寫紀錄（lat/lon 由 spot_merge_forward() 自動帶下去，feature
// 依設計清空——renderEntries() 改版後不再讀它），entries.jsonl 裡原始的音訊投稿完全不動，仍是
// 投稿牆上合法的一般投稿。
//
// 用法：php soundcontent_migrate.php <project_dir> [--apply]
// 不加 --apply 是預覽模式（dry run），只列出會處理哪些點位，不寫入也不備份；
// 加 --apply 才會先把整個專案目錄備份成 ZIP（見 spotmigrate_backup_project()），再實際寫入。
//
// 冪等：目前已有非空 content 的點位一律跳過（不論是這支工具寫的還是後來用 spotcontent.php 寫的）。
require_once __DIR__ . '/../api/store.php';
require_once __DIR__ . '/../api/spotlib.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}
$dir = $argv[1] ?? null;
$apply = in_array('--apply', $argv, true);
if (!$dir || !is_dir($dir)) {
    fwrite(STDERR, "usage: php soundcontent_migrate.php <project_dir> [--apply]\n");
    exit(1);
}
$dir = rtrim(str_replace('\\', '/', $dir), '/');
$project = basename($dir);
$cfg = ['projects_dir' => dirname($dir)];

$spotRecords = [];
foreach (file($dir . '/spots.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $rec = json_decode($line, true);
    if (is_array($rec)) $spotRecords[] = $rec;
}
$nums = [];
foreach ($spotRecords as $r) {
    if (($r['kind'] ?? '') === 'spot' && empty($r['edit_of']) && isset($r['num'])) $nums[] = (int)$r['num'];
}
sort($nums);

$audioById = [];
foreach (file($dir . '/entries.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $rec = json_decode($line, true);
    if (is_array($rec) && ($rec['kind'] ?? '') === 'audio' && isset($rec['id'])) $audioById[(string)$rec['id']] = $rec;
}

$toWrite = [];   // ['num' => int, 'eff' => array, 'entry' => array]
$skipped = [];   // ['num' => int, 'reason' => string]
foreach ($nums as $num) {
    $eff = spot_effective($cfg, $project, $num);
    if ($eff === null) { $skipped[] = ['num' => $num, 'reason' => 'no origin (不應該發生)']; continue; }
    if (!empty($eff['content'])) { $skipped[] = ['num' => $num, 'reason' => '已有 content，跳過（冪等）']; continue; }
    $featureId = (string)($eff['feature'] ?? '');
    if ($featureId === '') { $skipped[] = ['num' => $num, 'reason' => '沒有精選 feature，無須遷移']; continue; }
    if (!isset($audioById[$featureId])) { $skipped[] = ['num' => $num, 'reason' => "feature={$featureId} 找不到對應的音訊投稿"]; continue; }
    $toWrite[] = ['num' => $num, 'eff' => $eff, 'entry' => $audioById[$featureId]];
}

echo "專案：{$project}　起點：" . count($nums) . " 個　待處理：" . count($toWrite) . " 個　跳過：" . count($skipped) . " 個\n";
foreach ($skipped as $s) echo "  [跳過] #{$s['num']}：{$s['reason']}\n";
foreach ($toWrite as $w) {
    $e = $w['entry'];
    echo "  [" . ($apply ? '寫入' : '預覽') . "] #{$w['num']}：{$e['media']}（{$e['name']}，feature={$e['id']}）\n";
}

if (!$apply) {
    echo "\n預覽模式，未寫入任何內容。確認無誤後加 --apply 執行。\n";
    exit(0);
}
if (!$toWrite) {
    echo "\n沒有需要寫入的點位。\n";
    exit(0);
}

$backupZip = spotmigrate_backup_project($cfg, $project);
if ($backupZip === null) {
    fwrite(STDERR, "備份失敗，中止寫入。\n");
    exit(1);
}
echo "\n已備份整包專案至：{$backupZip}\n";

foreach ($toWrite as $w) {
    $e = $w['entry'];
    $item = [
        'kind'           => 'audio',
        'media'          => $e['media'],
        'media_mime'     => $e['media_mime'] ?? null,
        'duration'       => $e['duration'] ?? null,
        'comment'        => $e['comment'] ?? null,
        'source_url'     => $e['source_url'] ?? null,
        'source_license' => $e['source_license'] ?? null,
        'license'        => $e['license'] ?? 'cc0',
        'name'           => $e['name'] ?? '管理者',
        'created_at'     => $e['created_at'] ?? gmdate('c'),
    ];
    $fields = spot_merge_forward($w['eff'], ['feature' => null, 'content' => [$item]]);
    $record = [
        'id'         => bin2hex(random_bytes(8)),
        'project'    => $project,
        'kind'       => 'spot',
        'item_num'   => $w['num'],
        'edit_of'    => (string)$w['eff']['id'],
        'name'       => '音訊內容遷移工具',
        'lat'        => $fields['lat'],
        'lon'        => $fields['lon'],
        'feature'    => $fields['feature'],
        'content'    => $fields['content'],
        'created_at' => gmdate('c'),
    ];
    store_append($cfg, $project, $record);
    echo "  已寫入 #{$w['num']} 的 content 覆寫紀錄（id={$record['id']}）\n";
}
echo "\n完成，共寫入 " . count($toWrite) . " 筆。\n";
