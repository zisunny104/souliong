<?php
/**
 * 專案封面／地圖快照圖片。
 * GET  <base>?api=cover&project=<id>  輸出圖檔，供 landing.php 卡片與後台預覽用 <img src>。
 * POST 僅限該專案管理者，action=
 *   upload  自訂上傳一張，之後 auto 永遠不再覆寫（要換回自動快照見 reset）
 *   auto    前端擷取地圖目前畫面自動存檔（機會性呼叫，未到最短間距或已是自訂封面時原樣回傳、不動檔案）；
 *           force=1 時略過最短間距（後台「強制刷新」按鈕）
 *   reset   清空封面，卡片退回預設圖示
 * 封面固定存一份 projects/<id>/cover.webp（或 .jpg，視伺服器 GD 支援），mode/updatedAt 記在
 * meta.json 的 cover 區塊。實際的存檔／meta 寫入邏輯在 api/coverlib.php，後台「封面圖片」
 * 對話框的手動上傳／重設（api/manager.php）走同一套。
 */
require __DIR__ . '/store.php';
require __DIR__ . '/security.php';
require __DIR__ . '/coverlib.php';
$cfg = require __DIR__ . '/config.php';

$project = preg_replace('/[^a-z0-9_-]/', '', $_GET['project'] ?? $_POST['project'] ?? '');
if ($project === '' || !is_dir($cfg['projects_dir'] . '/' . $project)) {
    json_out(['error' => 'unknown project'], 400);
}
$mf = $cfg['projects_dir'] . '/' . $project . '/meta.json';
$coverBase = project_dir($cfg, $project) . '/cover';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $path = cover_file_of($coverBase);
    if ($path === null) {
        http_response_code(404);
        exit;
    }
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    header('Content-Type: ' . ($ext === 'webp' ? 'image/webp' : 'image/jpeg'));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: public, max-age=300');   // 短快取：上傳/強制刷新後要能很快看到新圖，不比照 photo.php 的 immutable
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'method not allowed'], 405);
}
rate_limit($cfg, 'manage');

if (!(primary_authed($cfg) || perm_check($cfg, $project, 'edit_meta'))) {
    json_out(['error' => '沒有權限管理這張地圖的封面圖片'], 403);
}

// CSRF：三種登入身分（主 PIN／帳號／專案 PIN）各自對應的衍生值，跟 pages/view.php 輸出給前端的
// APP.csrf 用同一套算法，否則其中一種身分送出的請求會被誤判失效（見該檔開頭的三選一說明）。
$isPrimary = primary_authed($cfg);
$acctCsrf = $isPrimary ? null : account_current($cfg);
$csrfExpected = $isPrimary
    ? primary_derived($cfg)
    : ($acctCsrf !== null ? account_derived($cfg, (string)$acctCsrf['id']) : pin_derived($cfg, $project, (string)pin_current_id($cfg, $project)));
if (!hash_equals($csrfExpected, (string)($_POST['csrf'] ?? ''))) {
    json_out(['error' => '憑證失效，請重新整理頁面後再操作一次'], 403);
}

$meta = is_file($mf) ? json_decode((string)@file_get_contents($mf), true) : [];
if (!is_array($meta)) $meta = [];
$action = (string)($_POST['action'] ?? '');

if ($action === 'reset') {
    json_out(cover_apply_reset($coverBase, $mf, $meta));
}

if ($action === 'upload') {
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        json_out(['error' => 'need file'], 400);
    }
    $f = $_FILES['file'];
    if ($f['size'] > (int)($cfg['max_bytes'] ?? 12 * 1024 * 1024)) {
        json_out(['error' => 'file too large'], 413);
    }
    $bytes = @file_get_contents($f['tmp_name']);
    if ($bytes === false) json_out(['error' => 'unsupported image'], 415);
    $r = cover_apply_bytes($cfg, $coverBase, $mf, $meta, $bytes, 'custom');
    json_out($r, $r['ok'] ? 200 : 415);
}

if ($action === 'auto') {
    $existing = is_array($meta['cover'] ?? null) ? $meta['cover'] : null;
    $force = !empty($_POST['force']);
    if (!$force && ($existing['mode'] ?? '') === 'custom') {
        // 已有自訂封面：auto 是機會性呼叫，永遠不覆寫，原樣回傳當作成功，前端不必為此另開分支
        json_out(['ok' => true, 'cover' => $existing, 'skipped' => 'custom']);
    }
    $minInterval = (int)($cfg['cover_min_interval'] ?? 3600);
    if (!$force && !empty($existing['updatedAt']) && (time() - (int)strtotime((string)$existing['updatedAt'])) < $minInterval) {
        json_out(['ok' => true, 'cover' => $existing, 'skipped' => 'interval']);
    }
    $dataUrl = (string)($_POST['image'] ?? '');
    if (strlen($dataUrl) > 12_000_000) json_out(['error' => 'image too large'], 413);
    if (!preg_match('#^data:image/(?:png|jpeg|webp);base64,([a-zA-Z0-9+/=]+)$#', $dataUrl, $m)) {
        json_out(['error' => 'bad image data'], 400);
    }
    $bytes = base64_decode($m[1], true);
    if ($bytes === false) json_out(['error' => 'bad image data'], 400);
    $r = cover_apply_bytes($cfg, $coverBase, $mf, $meta, $bytes, 'auto');
    json_out($r, $r['ok'] ? 200 : 415);
}

json_out(['error' => 'unknown action'], 400);
