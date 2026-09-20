<?php
// 一次性維護工具（CLI only）：把一個點位散在多個來源的「說明與內容」合併成一個內容區（spots.jsonl 的
// content 陣列），操作者記為「系統合併」。之後系統不再有 story 欄位與 kind:'desc' 投稿。做四件事：
//   1. 合併：起點紀錄的 story、entries.jsonl 裡訪客送出的 kind:'desc' 版本、現有 content 裡的聲音區塊，
//      合成同一個內容區——文字取最新的一版排在最前面，後面接原有的區塊；整個內容區寫成一筆版本紀錄，
//      name 與 actor 都是「系統合併」。
//   2. 舊說明的歷史保留：story 與較舊的 desc 版本各自寫成一筆只含那段文字的歷史版本（保留原作者名稱），
//      排在合併版本之前，內容歷史裡查得到；成功後從 entries.jsonl 移除已轉的 desc 列。對不到起點的
//      desc 列列出來、不動。
//   3. 區塊上舊的署名欄位（name、created_at、actor）拿掉，沒有 id 的補上穩定 id。署名只看版本紀錄。
//   4. meta.json 的 features.story／features.soundEdit 合併成 features.contentEdit（任一為 true 即 true）。
// 起點紀錄上舊的 story 鍵不會被改寫，之後也不會被任何程式讀取。
//
// 用法：php content_migrate.php <project_dir> [--apply]
// 不加 --apply 是預覽模式，只列出會處理什麼，不寫入也不備份；加 --apply 才會先把整個專案備份成 ZIP
// （見 spotmigrate_backup_project()）再寫入。
//
// 冪等：點位版本鏈上已有 actor 為 system:merge 的紀錄就跳過該點位（之後被人刪掉的內容不會被加回來）；
// meta.json 沒有 story／soundEdit 鍵就不動。所有環境都遷移完之後可以刪除這支工具。
require_once __DIR__ . '/../api/store.php';
require_once __DIR__ . '/../api/spotlib.php';

const CONTENT_MERGE_ACTOR = 'system:merge';
const CONTENT_MERGE_NAME = '系統合併';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}
$dir = $argv[1] ?? null;
$apply = in_array('--apply', $argv, true);
if (!$dir || !is_dir($dir)) {
    fwrite(STDERR, "usage: php content_migrate.php <project_dir> [--apply]\n");
    exit(1);
}
$dir = rtrim(str_replace('\\', '/', $dir), '/');
$project = basename($dir);
$cfg = ['projects_dir' => dirname($dir)];

$meta = json_decode((string)@file_get_contents($dir . '/meta.json'), true);
if (!is_array($meta)) $meta = [];
$sourceLabel = (string)($meta['source'] ?? $meta['credit'] ?? '');

$spotRecords = _store_read_lines(store_file($cfg, $project, 'spot'));
$entryRecords = _store_read_lines(store_file($cfg, $project));

$nums = [];
$originIdToNum = [];
$mergedNums = [];
foreach ($spotRecords as $r) {
    if (($r['kind'] ?? '') !== 'spot') continue;
    if (empty($r['edit_of']) && isset($r['num'])) {
        $nums[] = (int)$r['num'];
        $originIdToNum[(string)$r['id']] = (int)$r['num'];
    }
}
foreach ($spotRecords as $r) {
    if (($r['kind'] ?? '') === 'spot' && !empty($r['edit_of']) && ($r['actor'] ?? '') === CONTENT_MERGE_ACTOR) {
        $n = $originIdToNum[(string)$r['edit_of']] ?? null;
        if ($n !== null) $mergedNums[$n] = true;
    }
}
sort($nums);

$descByNum = [];
$orphanDesc = [];
foreach ($entryRecords as $r) {
    if (($r['kind'] ?? '') !== 'desc' || empty($r['id'])) continue;
    $n = isset($r['item_num']) ? (int)$r['item_num'] : null;
    if ($n === null || !in_array($n, $nums, true)) { $orphanDesc[] = $r; continue; }
    if (!empty($r['edit_of']) || trim((string)($r['comment'] ?? '')) === '') continue;
    $descByNum[$n][] = $r;
}
foreach ($descByNum as &$list) usort($list, fn($a, $b) => strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? '')));
unset($list);

/** 區塊只留內容欄位：署名（name、created_at、actor）改看版本紀錄；沒有 id 的補上。 */
function content_merge_clean_block(array $b): array
{
    unset($b['name'], $b['created_at'], $b['actor']);
    if (empty($b['id'])) $b['id'] = spot_new_block_id();
    return $b;
}

function content_merge_text_block(string $comment, ?string $sourceUrl = null, ?string $sourceLicense = null): array
{
    $b = ['id' => spot_new_block_id(), 'kind' => 'text', 'comment' => $comment];
    if ($sourceUrl) $b['source_url'] = $sourceUrl;
    if ($sourceLicense) $b['source_license'] = $sourceLicense;
    return $b;
}

/**
 * 產出這個點位要依序寫入的版本：每個元素是 ['content' => 完整區塊陣列, 'name' => 名稱, 'actor' => 稽核字串]。
 * 較舊的文字各一筆歷史版本（只含那段文字，保留原作者名稱），最後一筆是系統合併的完整內容區。
 */
function content_migrate_plan(array $eff, string $sourceLabel, array $descs): array
{
    $texts = [];   // 由舊到新的文字版本
    $story = trim((string)($eff['story'] ?? ''));
    if ($story !== '') $texts[] = ['comment' => $story, 'name' => $sourceLabel !== '' ? $sourceLabel : CONTENT_MERGE_NAME, 'source_url' => null, 'source_license' => null];
    foreach ($descs as $d) {
        $texts[] = [
            'comment' => trim((string)$d['comment']), 'name' => (string)($d['name'] ?? '') ?: CONTENT_MERGE_NAME,
            'source_url' => $d['source_url'] ?? null, 'source_license' => $d['source_license'] ?? null,
        ];
    }
    $latest = $texts ? array_pop($texts) : null;

    $states = [];
    foreach ($texts as $t) {
        $states[] = ['content' => [content_merge_text_block($t['comment'], $t['source_url'], $t['source_license'])], 'name' => $t['name'], 'actor' => 'tool:content_migrate'];
    }
    $merged = [];
    if ($latest !== null) $merged[] = content_merge_text_block($latest['comment'], $latest['source_url'], $latest['source_license']);
    foreach ((array)($eff['content'] ?? []) as $b) {
        if (is_array($b)) $merged[] = content_merge_clean_block($b);
    }
    $states[] = ['content' => $merged, 'name' => CONTENT_MERGE_NAME, 'actor' => CONTENT_MERGE_ACTOR];
    return $states;
}

$plans = [];   // num => ['eff' => array, 'states' => array]
$skipped = 0;
foreach ($nums as $num) {
    if (isset($mergedNums[$num])) { $skipped++; continue; }
    $eff = spot_effective($cfg, $project, $num);
    if ($eff === null) continue;
    $hasLegacy = trim((string)($eff['story'] ?? '')) !== '' || !empty($descByNum[$num]);
    $dirtyBlocks = false;
    foreach ((array)($eff['content'] ?? []) as $b) {
        if (is_array($b) && (empty($b['id']) || isset($b['name']) || isset($b['created_at']) || isset($b['actor']))) $dirtyBlocks = true;
    }
    if (!$hasLegacy && !$dirtyBlocks) { $skipped++; continue; }
    $plans[$num] = ['eff' => $eff, 'states' => content_migrate_plan($eff, $sourceLabel, $descByNum[$num] ?? [])];
}

// meta.json：story／soundEdit 合併成 contentEdit
$newMeta = null;
if (is_array($meta['features'] ?? null) && (array_key_exists('story', $meta['features']) || array_key_exists('soundEdit', $meta['features']))) {
    $f = $meta['features'];
    $on = !empty($f['story']) || !empty($f['soundEdit']);
    $rebuilt = [];
    foreach ($f as $k => $v) {
        if ($k === 'story' || $k === 'soundEdit') { $rebuilt['contentEdit'] = $on; continue; }
        $rebuilt[$k] = $v;
    }
    $newMeta = $meta;
    $newMeta['features'] = $rebuilt;
}

echo "專案：{$project}　起點：" . count($nums) . " 個　待合併：" . count($plans) . " 個　跳過：{$skipped} 個\n";
foreach ($plans as $num => $p) {
    $bits = [];
    if (trim((string)($p['eff']['story'] ?? '')) !== '') $bits[] = 'story 併入文字';
    if (!empty($descByNum[$num])) $bits[] = count($descByNum[$num]) . ' 筆 desc 版本';
    $audio = 0;
    foreach ((array)($p['eff']['content'] ?? []) as $b) if (is_array($b) && ($b['kind'] ?? '') === 'audio') $audio++;
    if ($audio) $bits[] = "{$audio} 個聲音區塊併入";
    if (!$bits) $bits[] = '整理區塊署名與 id';
    $last = end($p['states']);
    echo "  [" . ($apply ? '寫入' : '預覽') . "] #{$num}：" . implode('、', $bits) . "（歷史 " . (count($p['states']) - 1) . " 筆＋合併 1 筆，合併後 " . count($last['content']) . " 個區塊）\n";
}
foreach ($orphanDesc as $o) echo "  [略過] desc 紀錄 " . ($o['id'] ?? '?') . "：對不到起點（item_num=" . ($o['item_num'] ?? '?') . "），未動\n";
if ($newMeta !== null) echo "  [" . ($apply ? '寫入' : '預覽') . "] meta.json：features.story／soundEdit → contentEdit = " . ($newMeta['features']['contentEdit'] ? 'true' : 'false') . "\n";

if (!$apply) {
    echo "\n預覽模式，未寫入任何內容。確認無誤後加 --apply 執行。\n";
    exit(0);
}
if (!$plans && $newMeta === null) {
    echo "\n沒有需要處理的項目。\n";
    exit(0);
}

$backupZip = spotmigrate_backup_project($cfg, $project);
if ($backupZip === null) {
    fwrite(STDERR, "備份失敗，中止寫入。\n");
    exit(1);
}
echo "\n已備份整包專案至：{$backupZip}\n";

$migratedDescIds = [];
foreach ($plans as $num => $p) {
    foreach ($p['states'] as $s) {
        $rec = spot_append_version($cfg, $project, $p['eff'], ['content' => $s['content']], $s['actor'], $s['name']);
        echo "  已寫入 #{$num} 的 content 版本（id={$rec['id']}，{$s['name']}）\n";
    }
    foreach ($descByNum[$num] ?? [] as $d) $migratedDescIds[(string)$d['id']] = true;
}
if ($migratedDescIds) {
    $removed = _store_rewrite(store_file($cfg, $project), fn($r) => ($r['kind'] ?? '') === 'desc' && isset($migratedDescIds[(string)($r['id'] ?? '')]));
    echo "  已從 entries.jsonl 移除 " . count($removed) . " 筆已轉的 desc 紀錄\n";
}
if ($newMeta !== null) {
    $raw = (string)file_get_contents($dir . '/meta.json');
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | (strpos($raw, "\n") !== false ? JSON_PRETTY_PRINT : 0);
    file_put_contents($dir . '/meta.json', json_encode($newMeta, $flags) . (substr($raw, -1) === "\n" ? "\n" : ''));
    echo "  已改寫 meta.json\n";
}
echo "\n完成。\n";
