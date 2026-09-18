<?php
// POST spotcontent.php：把內容直接寫入點位自己的 content 欄位（spots.jsonl 的 edit_of 覆寫鏈），
// 取代「投稿到 entries.jsonl 再由管理者用 feature 手動精選」的舊流程（見 docs/part4-coordination.md
// 的概念模型：投稿與點位是兩個平行的域，content 是點位自己的原生內容，跟 feature 這座橋是兩回事）。
// content 是通用欄位，目前唯一型別是音訊，但資料形狀（型別標記物件陣列）刻意設計成可擴充。
// 把關比照 editspot.php 逐字同款（perm_check('edit_spots') + CSRF）——這是點位權限，跟投稿軸的
// 投稿代碼／contrib_open() 無關，也跟這張地圖有沒有開 soundEdit／upload 模組無關（模組開關只影響
// 前端要不要顯示錄音入口，不是後端的把關條件）。本次只做管理者可寫，訪客投稿之後再單獨討論。
// 伺服器端一律用 spot_effective() 算出目前有效的 lat/lon/feature 重新寫回去，不信任前端送來的值，
// 只覆寫 content——避免竄改位置或清空 feature。item_num 對不到起點直接 404，不產生孤兒紀錄。
// POST project, item_num（必填，對應起點的 num）, media(檔案), duration(秒，選填), comment(選填),
// name(選填), source_url(選填), source_license(選填), license(選填，cc0/cc-by)。
require __DIR__ . '/store.php';
require __DIR__ . '/security.php';
require __DIR__ . '/spotlib.php';
require __DIR__ . '/uploadlib.php';
require __DIR__ . '/features.php';
$cfg = require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST only'], 405);
}
rate_limit($cfg, 'write');

$project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
if ($project === '' || !is_dir($cfg['projects_dir'] . '/' . $project)) {
    json_out(['error' => 'bad request'], 400);
}

if (!perm_check($cfg, $project, 'edit_spots')) {
    json_out(['error' => '沒有權限編輯點位內容（僅限主要管理者，或已被授權的專案管理者）'], 403);
}

// CSRF：值＝同一支登入身分在 view.php 才拿得到的衍生值（見 $APP.csrf），跨站請求讀不到頁面內容故無法偽造
$csrfExpected = primary_authed($cfg) ? primary_derived($cfg) : pin_derived($cfg, $project, (string)pin_current_id($cfg, $project));
if (!hash_equals($csrfExpected, (string)($_POST['csrf'] ?? ''))) {
    json_out(['error' => '憑證失效，請重新整理頁面後再操作一次'], 403);
}

function clean_str_sc(?string $s, int $max): ?string {
    if ($s === null) return null;
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s);
    $s = trim($s);
    if ($s === '') return null;
    if (preg_match('/^.{0,' . $max . '}/us', $s, $m)) $s = $m[0];
    return $s;
}

$item_num = (isset($_POST['item_num']) && $_POST['item_num'] !== '') ? (int)$_POST['item_num'] : null;
if ($item_num === null) {
    json_out(['error' => 'bad request'], 400);
}

$eff = spot_effective($cfg, $project, $item_num);
if ($eff === null) {
    json_out(['error' => '找不到這個點位'], 404);
}

// 目前唯一支援的內容型別；souliong_kind_spot_content_postable() 是給以後擴充第二種型別用的
// 白名單防呆，現階段永遠是 audio 也一併過這關，避免這支函式變成沒人呼叫的死程式碼。
$kind = 'audio';
if (!souliong_kind_spot_content_postable($kind)) {
    json_out(['error' => 'bad kind'], 400);
}
$kindDef = souliong_kinds()[$kind];

if (!isset($_FILES['media']) || $_FILES['media']['error'] !== UPLOAD_ERR_OK) {
    json_out(['error' => 'need media file'], 400);
}
$maxBytes = (int)($cfg['max_bytes_' . $kind] ?? $kindDef['max_bytes'] ?? $cfg['max_bytes']);
$saved = uploadlib_store_file($cfg, $project, $_FILES['media'], 'media', $kindDef['mimes'] ?? [], $maxBytes);

$comment = clean_str_sc($_POST['comment'] ?? null, $cfg['comment_max']);
$name    = clean_str_sc($_POST['name'] ?? null, $cfg['name_max']) ?? '管理者';

$duration = is_numeric($_POST['duration'] ?? null) ? round((float)$_POST['duration'], 2) : null;
if ($duration !== null && ($duration <= 0 || $duration > 86400)) $duration = null;

$source_url = clean_str_sc($_POST['source_url'] ?? null, 500);
if ($source_url !== null && (!preg_match('#^https?://#i', $source_url) || filter_var($source_url, FILTER_VALIDATE_URL) === false)) {
    $source_url = null;
}
$source_license = in_array($_POST['source_license'] ?? '', ['cc0', 'cc-by'], true) ? $_POST['source_license'] : null;
// 授權：CC BY（姓名標示）只對已建立身分（有 ctoken）的投稿者開放，比照 upload.php 同一條規則；
// 這裡雖然目前只有管理者能寫，仍沿用同一個判斷式，不另外開特例。
$hasIdentity = !empty($_POST['ctoken']);
$license     = ($hasIdentity && ($_POST['license'] ?? '') === 'cc-by') ? 'cc-by' : 'cc0';

try {
    $item = [
        'kind'           => $kind,
        'media'          => $saved['rel'],
        'media_mime'     => $saved['mime'],
        'duration'       => $duration,
        'comment'        => $comment,
        'source_url'     => $source_url,
        'source_license' => $source_license,
        'license'        => $license,
        'name'           => $name,
        'created_at'     => gmdate('c'),
    ];
    $fields = spot_merge_forward($eff, ['content' => [$item]]);

    $record = [
        'id'         => bin2hex(random_bytes(8)),
        'project'    => $project,
        'kind'       => 'spot',
        'item_num'   => $item_num,
        'edit_of'    => (string)$eff['id'],
        'name'       => $name,
        'lat'        => $fields['lat'],
        'lon'        => $fields['lon'],
        'feature'    => $fields['feature'],
        'content'    => $fields['content'],
        'created_at' => gmdate('c'),
    ];
    store_append($cfg, $project, $record);

    $out = $record;
    $out['media_url'] = 'media/' . $saved['rel'];
    json_out(['ok' => true, 'item' => $out]);
} catch (Throwable $e) {
    error_log('souliong spotcontent: ' . $e->getMessage());
    json_out(['error' => 'server'] + (!empty($cfg['debug']) ? ['detail' => $e->getMessage()] : []), 500);
}
