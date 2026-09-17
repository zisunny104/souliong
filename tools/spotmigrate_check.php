<?php
// 維護工具（常駐、可重複使用，CLI only，純唯讀）：核對 api/spotmigrate.php 的遷移結果。
// 1) 靜態底稿（meta.json 的 points 檔名）裡每一個 num，現在是否剛好對到一筆 spots.jsonl
//    起點紀錄（有 num、無 edit_of），且除了遷移新增的 id/project/kind/item_num/created_at
//    之外欄位逐一相等。
// 2) 遷移前的孤兒編輯紀錄（從 spotmigrate.php 寫下的 spots.jsonl.bak 讀出：edit_of 空、
//    無 num、有 item_num）現在是否都已經指到一個存在的起點 id。
//
// 用法：php spotmigrate_check.php <project_dir>
//
// 只讀，不寫入也不刪除任何東西。
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}
$dir = $argv[1] ?? null;
if (!$dir || !is_dir($dir)) {
    fwrite(STDERR, "usage: php spotmigrate_check.php <project_dir>\n");
    exit(1);
}

function read_jsonl_list(string $path): array {
    if (!is_file($path)) return [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $out = [];
    foreach ($lines as $ln) {
        $rec = json_decode($ln, true);
        if (is_array($rec)) $out[] = $rec;
    }
    return $out;
}

$metaPath = $dir . '/meta.json';
$meta = is_file($metaPath) ? json_decode((string)file_get_contents($metaPath), true) : null;
if (!is_array($meta)) $meta = [];
$staticName = (string)($meta['points'] ?? 'points.json');
$staticPath = $dir . '/' . $staticName;
$staticPoints = is_file($staticPath) ? json_decode((string)file_get_contents($staticPath), true) : null;

if (!is_array($staticPoints) || !$staticPoints) {
    echo "這個專案沒有靜態底稿（{$staticName}），沒有東西要核對。\n";
    exit(0);
}

$spotsPath = $dir . '/spots.jsonl';
$records = read_jsonl_list($spotsPath);

$originByNum = [];
$byId = [];
foreach ($records as $r) {
    if (isset($r['id'])) $byId[(string)$r['id']] = $r;
    if (empty($r['edit_of']) && isset($r['num'])) $originByNum[(int)$r['num']] = $r;
}

// ── 核對一：靜態底稿的每個 num ──
$missing = [];    // 靜態底稿有這個 num，但 spots.jsonl 找不到對應的起點紀錄
$mismatched = []; // 兩邊都有，但扣掉遷移新增欄位後內容不相符
foreach ($staticPoints as $p) {
    if (!is_array($p) || !isset($p['num'])) continue;
    $num = (int)$p['num'];
    if (!isset($originByNum[$num])) {
        $missing[] = $num;
        continue;
    }
    $new = $originByNum[$num];
    $oldCmp = $p;
    $newCmp = $new;
    foreach (['id', 'project', 'kind', 'item_num', 'created_at'] as $k) unset($newCmp[$k]);
    ksort($oldCmp); ksort($newCmp);
    if ($oldCmp !== $newCmp) $mismatched[] = $num;
}

// ── 核對二：遷移前的孤兒編輯，是否都指到有效起點 ──
$bakRecords = read_jsonl_list($spotsPath . '.bak');
$orphansBefore = array_filter($bakRecords, fn($r) => empty($r['edit_of']) && !isset($r['num']) && isset($r['item_num']));
$stillOrphan = [];   // 現在還是空 edit_of
$danglingRef = [];   // edit_of 指到一個不存在的 id
foreach ($orphansBefore as $o) {
    $id = (string)($o['id'] ?? '');
    if ($id === '' || !isset($byId[$id])) continue;   // 這筆本來就不在現有 spots.jsonl 裡，不是這次要核對的範圍
    $cur = $byId[$id];
    $editOf = (string)($cur['edit_of'] ?? '');
    if ($editOf === '') { $stillOrphan[] = $id; continue; }
    if (!isset($byId[$editOf])) { $danglingRef[] = $id; }
}

echo "靜態底稿：{$staticName}，共 " . count($staticPoints) . " 筆。spots.jsonl 起點：" . count($originByNum) . " 筆。\n";
echo "遷移前孤兒編輯（自 spots.jsonl.bak 讀出）：" . count($orphansBefore) . " 筆。\n";

$ok = true;
if ($missing) {
    $ok = false;
    echo "\n[缺漏] 以下 " . count($missing) . " 個 num 在靜態底稿有、但 spots.jsonl 找不到對應起點，尚未遷移：\n";
    foreach ($missing as $n) echo "  - #{$n}\n";
}
if ($mismatched) {
    $ok = false;
    echo "\n[欄位不符] 以下 " . count($mismatched) . " 個 num 兩邊都有，但扣掉遷移新增欄位後內容不一致：\n";
    foreach ($mismatched as $n) echo "  - #{$n}\n";
}
if ($stillOrphan) {
    $ok = false;
    echo "\n[仍是孤兒] 以下 " . count($stillOrphan) . " 筆編輯紀錄的 edit_of 仍是空的，沒有被這次遷移接上：\n";
    foreach ($stillOrphan as $id) echo "  - {$id}\n";
}
if ($danglingRef) {
    $ok = false;
    echo "\n[指向無效] 以下 " . count($danglingRef) . " 筆編輯紀錄的 edit_of 指到一個不存在的 id：\n";
    foreach ($danglingRef as $id) echo "  - {$id}\n";
}

if ($ok) {
    echo "\n通過：靜態底稿每個 num 都有對應起點且欄位相符，遷移前的孤兒編輯全部指到有效起點。\n";
    exit(0);
}
echo "\n未通過。\n";
exit(1);
