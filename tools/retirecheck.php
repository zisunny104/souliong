<?php
// 維護工具（常駐、可重複使用，CLI only，純唯讀）：核對某專案的 spots.jsonl＋entries.jsonl
// 是否已經完整涵蓋遷移前 data.jsonl 的每一筆記錄，做為「這份 data.jsonl 存底可以安全刪除」
// 的判準依據。見 souliong/docs/EXTENDING.md 的「舊機制淘汰與退場」一節。
//
// 用法：php retirecheck.php <project_dir>
//
// 只讀三個檔案、不寫入也不刪除任何東西——要不要真的刪 data.jsonl，看完報告後由人工執行。
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}
$dir = $argv[1] ?? null;
if (!$dir || !is_dir($dir)) {
    fwrite(STDERR, "usage: php retirecheck.php <project_dir>\n");
    exit(1);
}

$dataPath = $dir . '/data.jsonl';
$spotsPath = $dir . '/spots.jsonl';
$entriesPath = $dir . '/entries.jsonl';

if (!is_file($dataPath)) {
    echo "沒有 data.jsonl（已經刪過，或這個專案從一開始就是新架構）：沒有東西要核對。\n";
    exit(0);
}
if (!is_file($spotsPath) || !is_file($entriesPath)) {
    echo "缺少 spots.jsonl 或 entries.jsonl：這個專案還沒遷移過，data.jsonl 現在還是唯一正本，不能刪。\n";
    exit(1);
}

function read_jsonl(string $path): array {
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $byId = [];
    foreach ($lines as $ln) {
        $rec = json_decode($ln, true);
        if (is_array($rec) && isset($rec['id'])) $byId[(string)$rec['id']] = $rec;
    }
    return $byId;
}

$dataById = read_jsonl($dataPath);
$newById = read_jsonl($spotsPath) + read_jsonl($entriesPath);

$missing = [];   // 在 data.jsonl 有、新檔案裡找不到 —— 遷移漏掉了，絕對不能刪
$mismatched = []; // 兩邊都有，但除了 kind 以外的欄位對不起來 —— 遷移過程資料漂移了
foreach ($dataById as $id => $old) {
    if (!isset($newById[$id])) {
        $missing[] = $id;
        continue;
    }
    $new = $newById[$id];
    $oldCmp = $old; unset($oldCmp['kind']);
    $newCmp = $new; unset($newCmp['kind']);
    // 遷移腳本對舊 kind 為 point／newpoint 的記錄會多補 edit_of（原本沒有這個欄位）、
    // 部分專案還會順手補上 feature（例如 soundspace，指向遷移當下實際顯示中的投稿）——
    // 這兩項都是遷移設計上刻意的改動（見 EXTENDING.md「舊機制淘汰與退場」一節），
    // 不是資料漂移，比對時要放行。
    if (in_array($old['kind'] ?? null, ['point', 'newpoint'], true)) {
        unset($oldCmp['edit_of'], $newCmp['edit_of'], $oldCmp['feature'], $newCmp['feature']);
    }
    ksort($oldCmp); ksort($newCmp);
    if ($oldCmp !== $newCmp) {
        $mismatched[] = $id;
    }
}
$extra = array_diff(array_keys($newById), array_keys($dataById)); // 新檔案裡有、data.jsonl 沒有 —— 正常（遷移後的新投稿），僅供參考

echo "data.jsonl 共 " . count($dataById) . " 筆；spots.jsonl+entries.jsonl 共 " . count($newById) . " 筆。\n";
echo "遷移後新增的記錄（正常現象）：" . count($extra) . " 筆\n";

if ($missing) {
    echo "\n[缺漏] 以下 " . count($missing) . " 筆 id 在 data.jsonl 有、但新檔案找不到，遷移不完整，不能刪 data.jsonl：\n";
    foreach ($missing as $id) echo "  - $id\n";
}
if ($mismatched) {
    echo "\n[欄位不符] 以下 " . count($mismatched) . " 筆 id 兩邊都有，但除了 kind 之外的欄位不一致，遷移過程資料有漂移，不能刪 data.jsonl：\n";
    foreach ($mismatched as $id) echo "  - $id\n";
}

if (!$missing && !$mismatched) {
    echo "\n通過：spots.jsonl＋entries.jsonl 完整涵蓋 data.jsonl 的每一筆記錄，欄位（除 kind 外）逐一相符。\n";
    echo "可以安全刪除 {$dataPath}（刪除動作請自行手動執行，這支工具不會幫你刪）。\n";
    exit(0);
}
echo "\n未通過，data.jsonl 暫時不能刪。\n";
exit(1);
