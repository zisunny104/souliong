<?php
// 建立新的定位點。POST project, name(建立者暱稱), title, cat, catLabel, color, story, lat, lon
//
// 點位資料只有 spots.jsonl 一個來源（靜態底稿已由 api/spotmigrate.php 併入）：往裡附加一筆
// kind:'spot' 起點（無 edit_of，帶 num/title），前端讀取時把它併進點位清單（見 viewer.core.js
// 的 effectiveSpots()）。建立出來的點之後一樣能被管理者用 editspot.php 搬位置——那條路徑會
// 找到這筆當 edit_of 的鏈頭。
//
// 權限跟 editspot.php 不同，是每張地圖自己決定的（meta.json 的 contrib.newPoint）：
//   off（預設）  誰都不能建，端點直接 403——舊地圖不改設定檔就完全沒有這個功能
//   admin        只有具備 edit_spots 的管理者，比照 editspot.php（Auth::require）
//   contributor  一般投稿者也能建，比照 upload.php 用 contrib_gate() 把關（停權與投稿代碼）
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/contribgate.php';
require_once __DIR__ . '/features.php';
$cfg = require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST only'], 405);
}
rate_limit($cfg, 'write');

$project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
if ($project === '' || !is_dir($cfg['projects_dir'] . '/' . $project)) {
    json_out(['error' => 'bad request'], 400);
}

$projDir = project_dir($cfg, $project);
$meta    = json_decode((string)@file_get_contents($projDir . '/meta.json'), true);
$contrib = souliong_contrib_cfg($meta);
$who     = $contrib['newPoint'];

if ($who === 'off') {
    json_out(['error' => '這張地圖沒有開放建立地點'], 403);
}
if (!souliong_module_on($meta, 'upload')) {
    // 整張地圖唯讀時，建點也一併關掉（比照 upload.php 由 view.php 不渲染投稿介面來擋，
    // 但端點自己還是要擋一次——前端沒渲染不等於別人不能直接打這支）
    json_out(['error' => '這張地圖目前唯讀'], 403);
}

if ($who === 'admin') {
    // 管理者建點是點位軸：edit_spots＋CSRF 同一道關卡（api/auth.php），身分為 Auth 解析出的 Actor。
    Auth::require($cfg, $project, 'edit_spots', true, '這張地圖只有管理者能建立地點');
    $contributor = Contributor::fromRequest();
} else {
    // contributor：訪客建點走投稿軸同一道關卡（停權名單 → bypass_code → 投稿代碼並計一次使用），
    // 跟 upload.php 共用 contrib_gate()——建點跟投稿是等價的寫入行為，限次的碼不能無限建點。
    // 點位軸（建立地點）與投稿軸本應分開計算（docs/part4-coordination.md 血淚教訓 #1），這裡把兩者
    // 接在一起只是現況尚未拆開（newPoint 全站目前皆為 off，等於沒在跑），之後處理訪客建點權限模型時再釐清。
    $contributor = contrib_gate($cfg, $project, '建立地點');
}
// 建立者身分：跟投稿記錄用同一組欄位，主辦者才能在後台認出「這個點是誰建的」，
// 停權與刪除也才有東西可以對。管理者建的點這兩欄通常是 null。
$ownerHash = $contributor->ownerHash();
$contribId = $contributor->contribId();

function clean_str_ns(?string $s, int $max): ?string {
    if ($s === null) return null;
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s);
    $s = trim($s);
    if ($s === '') return null;
    if (preg_match('/^.{0,' . $max . '}/us', $s, $m)) $s = $m[0];
    return $s;
}

$lat = is_numeric($_POST['lat'] ?? null) ? (float)$_POST['lat'] : null;
$lon = is_numeric($_POST['lon'] ?? null) ? (float)$_POST['lon'] : null;
if ($lat === null || $lon === null || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
    json_out(['error' => 'bad request'], 400);
}
$title = clean_str_ns($_POST['title'] ?? null, 80);
if ($title === null) {
    json_out(['error' => '請給這個地點一個名稱'], 400);
}
$story = clean_str_ns($_POST['story'] ?? null, $cfg['comment_max']);
$by    = clean_str_ns($_POST['name'] ?? null, $cfg['name_max']) ?? '匿名';

// 分類：優先沿用這張地圖既有的分類（連 catLabel／color 一起繼承），圖例與篩選才對得上。
// 送了不存在的分類就另外收下它的標籤與顏色，由前端的圖例自行補上這一類。
$cat = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($_POST['cat'] ?? '')));
$catLabel = null;
$color    = null;
foreach (_store_read_lines(store_file($cfg, $project, 'spot')) as $r) {
    if (empty($r['edit_of']) && isset($r['num']) && ($r['cat'] ?? null) === $cat) {
        $catLabel = $r['catLabel'] ?? null;
        $color    = $r['color'] ?? null;
        break;
    }
}
if ($catLabel === null) {
    $catLabel = clean_str_np($_POST['catLabel'] ?? null, 30);
    $c = strtoupper(trim((string)($_POST['color'] ?? '')));
    $color = preg_match('/^#[0-9A-F]{6}$/', $c) ? $c : null;
}
if ($cat === '') {
    // 沒指定分類就自成一類，這樣圖例上至少分得出「訪客新增的地點」
    $cat = 'new';
    $catLabel = $catLabel ?? '新增地點';
}
if ($color === null) $color = '#7a7f87';

try {
    // 配號要在鎖裡做：num 是從「現有 spot 起點」取 max+1，用 store_all() 讀完再
    // store_append() 是兩段各自的鎖，兩個人同時建點會撞號。
    $record = store_append_locked($cfg, $project, function (array $records) use ($project, $title, $cat, $catLabel, $color, $story, $lat, $lon, $by, $ownerHash, $contribId) {
        $max = 0;
        foreach ($records as $r) {
            // 只算起點（無 edit_of、有 num），搬移／內容那幾筆沒有自己的 num 可算
            if (empty($r['edit_of']) && isset($r['num'])) $max = max($max, (int)$r['num']);
        }
        return [
            'id'         => bin2hex(random_bytes(8)),
            'project'    => $project,
            'kind'       => 'spot',
            'num'        => $max + 1,
            // item_num 跟 num 同值：投稿與座標編輯都是照 item_num 掛到點位上的，
            // 建立點自己也填一份，之後查「這個點底下有什麼」不用分兩種寫法。
            'item_num'   => $max + 1,
            'title'      => $title,
            'cat'        => $cat,
            'catLabel'   => $catLabel,
            'color'      => $color,
            'story'      => $story,
            'lat'        => $lat,
            'lon'        => $lon,
            'name'       => $by,
            'owner_hash' => $ownerHash,
            'contrib_id' => $contribId,
            'created_at' => gmdate('c'),
        ];
    }, 'spot');

    json_out(['ok' => true, 'item' => $record]);
} catch (Throwable $e) {
    error_log('souliong newspot: ' . $e->getMessage());
    json_out(['error' => 'server'] + (!empty($cfg['debug']) ? ['detail' => $e->getMessage()] : []), 500);
}
