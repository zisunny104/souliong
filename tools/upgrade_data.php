<?php
// 一次性升級工具（CLI only，冪等）：把舊版專案目錄（只有 data.jsonl）補成 spots.jsonl＋entries.jsonl。
// 由 tools/vps_upgrade.sh 依序呼叫，不必手動執行。升級完成並確認後可連同 vps_upgrade.sh 一起刪除。
//
//   php upgrade_data.php split    <project_dir> [--apply]   data.jsonl 依 kind 拆進兩個檔案
//   php upgrade_data.php features <project_dir> [--apply]   primaryKind 專案：把每個點位目前顯示中的主要投稿回填成 feature
//
// split：kind point／newpoint 改寫成 spot（point 的 edit_of 指向同 num 的既有起點，找不到留空）；其餘進 entries。
// 已存在於兩檔的 id 一律跳過；data.jsonl 本身不改、不刪。不加 --apply 為預覽。
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
$mode  = $argv[1] ?? '';
$dir   = rtrim(str_replace('\\', '/',$argv[2] ?? ''), '/');
$apply = in_array('--apply', $argv, true);
if (!in_array($mode, ['split', 'features'], true) || !is_dir($dir)) {
    fwrite(STDERR, "usage: php upgrade_data.php split|features <project_dir> [--apply]\n");
    exit(1);
}
$project = basename($dir);

function ug_read(string $f): array {
    $o = [];
    foreach (is_file($f) ? (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $l) {
        $r = json_decode($l, true);
        if (is_array($r)) $o[] = $r;
    }
    return $o;
}
function ug_append(string $f, array $recs): void {
    if (!$recs) return;
    $fp = fopen($f, 'ab');
    if (!$fp) throw new RuntimeException("cannot open $f");
    flock($fp, LOCK_EX);
    foreach ($recs as $r) fwrite($fp, json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}
function ug_origins(array $spots): array {   // num => 起點 id
    $o = [];
    foreach ($spots as $r) {
        if (($r['kind'] ?? '') === 'spot' && empty($r['edit_of']) && isset($r['num']) && isset($r['id'])) $o[(int)$r['num']] = (string)$r['id'];
    }
    return $o;
}

$spotsF = "$dir/spots.jsonl";
$entriesF = "$dir/entries.jsonl";
$spots = ug_read($spotsF);
$entries = ug_read($entriesF);

if ($mode === 'split') {
    $data = ug_read("$dir/data.jsonl");
    if (!$data) { echo "$project: 沒有 data.jsonl 或是空的，略過。\n"; exit(0); }
    $have = [];
    foreach (array_merge($spots, $entries) as $r) if (isset($r['id'])) $have[(string)$r['id']] = true;

    $toSpots = [];
    $toEntries = [];
    $legacy = [];
    foreach ($data as $r) {
        if (!isset($r['id']) || isset($have[(string)$r['id']])) continue;
        $k = (string)($r['kind'] ?? '');
        if ($k === 'newpoint') { $r['kind'] = 'spot'; $r['edit_of'] = ''; $toSpots[] = $r; }
        elseif ($k === 'point') { $r['kind'] = 'spot'; $legacy[] = $r; }
        else $toEntries[] = $r;
    }
    $origins = ug_origins(array_merge($spots, $toSpots));
    foreach ($legacy as $r) {
        $r['edit_of'] = $origins[(int)($r['item_num'] ?? 0)] ?? '';
        $toSpots[] = $r;
    }
    echo "$project: data.jsonl " . count($data) . " 筆；待補 spots " . count($toSpots) . " 筆、entries " . count($toEntries) . " 筆（已存在者略過）\n";
    if (!$apply) { echo "預覽模式，未寫入。\n"; exit(0); }
    foreach ([$spotsF, $entriesF] as $f) if (is_file($f) && !is_file("$f.pre-split")) copy($f, "$f.pre-split");
    ug_append($spotsF, $toSpots);
    ug_append($entriesF, $toEntries);
    echo "已寫入。\n";
    exit(0);
}

// features
$meta = json_decode((string)@file_get_contents("$dir/meta.json"), true) ?: [];
$pk = (string)($meta['contrib']['primaryKind'] ?? '');
if ($pk === '') { echo "$project: 沒有 primaryKind，略過 feature 回填。\n"; exit(0); }
$origins = ug_origins($spots);
$latest = [];   // num => 該型別最新一筆起點投稿
foreach ($entries as $e) {
    if (($e['kind'] ?? '') !== $pk || !empty($e['edit_of']) || !isset($e['item_num'])) continue;
    $n = (int)$e['item_num'];
    if (!isset($latest[$n]) || strcmp((string)($e['created_at'] ?? ''), (string)($latest[$n]['created_at'] ?? '')) >= 0) $latest[$n] = $e;
}
$hasFeature = [];
foreach ($spots as $r) if (!empty($r['feature'])) $hasFeature[(int)($r['item_num'] ?? $r['num'] ?? 0)] = true;
$out = [];
foreach ($latest as $n => $e) {
    if (!isset($origins[$n]) || isset($hasFeature[$n])) continue;
    $out[] = ['id' => bin2hex(random_bytes(8)), 'project' => $project, 'kind' => 'spot', 'item_num' => $n,
              'edit_of' => $origins[$n], 'feature' => (string)$e['id'], 'name' => '升級工具', 'created_at' => gmdate('c')];
}
echo "$project: primaryKind=$pk；待回填 feature " . count($out) . " 個點位\n";
if (!$apply) { echo "預覽模式，未寫入。\n"; exit(0); }
ug_append($spotsF, $out);
echo "已寫入。\n";
