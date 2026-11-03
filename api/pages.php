<?php
// 專案說明頁（選用模組 pages）：左上角按鈕開啟的面板，多個分頁，每頁一份 Markdown，可貼上圖片。
// 內容存在 projects/<project>/pages.json：{rev, updated_at, by, pages:[{id, title, md}]}，整份一起存（跟點位內容同精神：
// 一次儲存＝一個版本；base_rev 與現況不同回 409，草稿由前端保留）。
//
// GET  ?api=pages&project=…            公開讀：{rev, pages:[{id,title,html}], can_edit}；模組沒開回 404。
//      &edit=1                         具 edit_pages 的身分另外拿到每頁的 md 原文。
// POST op=save    project, csrf, base_rev, pages（JSON 陣列 [{id?, title, md}]）  權限：edit_pages
// POST op=preview project, csrf, md                                                權限：edit_pages，回 {html}
// POST op=upload  project, csrf, image（檔案）                                    權限：edit_pages，回 {src, url}
//
// 圖片：編輯器貼上或選檔的圖，存到該專案的 photos/，Markdown 裡寫 `souliong-photo:<project>/<檔名>`，
// 算繪時才換成目前部署的 photo.php 網址，網站搬家不會壞。外部圖片（http／https）照樣放行，這是專案說明頁的決定；
// 點位內容與其他地方的 Markdown 仍然不開圖片（見 markdown.php 的 images 選項）。
// 權限與前端開關分開：模組旗標只決定要不要顯示入口，真正擋寫入的是這裡的 Auth::require(edit_pages)。
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/features.php';
require_once __DIR__ . '/markdown.php';
require_once __DIR__ . '/routes.php';
$cfg = require __DIR__ . '/config.php';

const PAGES_MAX_PAGES = 12;
const PAGES_MAX_TITLE = 40;
const PAGES_MAX_MD    = 60000;

$project = preg_replace('/[^a-z0-9_-]/', '', $_REQUEST['project'] ?? '');
if ($project === '' || !is_dir($cfg['projects_dir'] . '/' . $project)) {
    json_out(['error' => 'bad request'], 400);
}
$meta = json_decode((string)@file_get_contents(project_dir($cfg, $project) . '/meta.json'), true);
if (!souliong_module_on(is_array($meta) ? $meta : null, 'pages')) {
    json_out(['error' => 'not found'], 404);
}

function pages_file(array $cfg, string $project): string { return project_dir($cfg, $project) . '/pages.json'; }

/** @return array{rev:string,updated_at:?string,by:?string,pages:array<int,array{id:string,title:string,md:string}>} */
function pages_load(array $cfg, string $project): array {
    $raw = @file_get_contents(pages_file($cfg, $project));
    $d = is_string($raw) ? json_decode($raw, true) : null;
    $pages = [];
    foreach ((array)($d['pages'] ?? []) as $p) {
        if (is_array($p) && isset($p['id'], $p['title'], $p['md'])) {
            $pages[] = ['id' => (string)$p['id'], 'title' => (string)$p['title'], 'md' => (string)$p['md']];
        }
    }
    return ['rev' => (string)($d['rev'] ?? ''), 'updated_at' => $d['updated_at'] ?? null, 'by' => $d['by'] ?? null, 'pages' => $pages];
}

/** Markdown → HTML。souliong-photo: 先換成實際網址，再交給 Markdown（外部圖片也放行）。 */
function pages_render(string $md): string {
    $md = preg_replace_callback('~souliong-photo:([a-z0-9_-]+/[A-Za-z0-9_.-]+)~', static fn(array $m): string => Route::api('photo', ['f' => $m[1]]), $md) ?? $md;
    return Markdown::toHtml($md, ['heading_ids' => false, 'heading_offset' => 1, 'images' => true]);
}

/** 內文清理：去掉控制字元（保留換行與 tab）、統一換行、限制長度。 */
function pages_clean_md($s): string {
    $s = str_replace(["\r\n", "\r"], "\n", (string)$s);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';
    if (preg_match('/^.{0,' . PAGES_MAX_MD . '}/us', $s, $m)) $s = $m[0];
    return rtrim($s);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $data = pages_load($cfg, $project);
    $actor = Auth::actor($cfg, $project);
    $canEdit = $actor->can($project, 'edit_pages');
    $wantMd = $canEdit && !empty($_GET['edit']);
    $out = [];
    foreach ($data['pages'] as $p) {
        $row = ['id' => $p['id'], 'title' => $p['title'], 'html' => pages_render($p['md'])];
        if ($wantMd) $row['md'] = $p['md'];
        $out[] = $row;
    }
    header('Cache-Control: no-store');
    json_out(['ok' => true, 'rev' => $data['rev'], 'updated_at' => $data['updated_at'], 'pages' => $out, 'can_edit' => $canEdit]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'bad request'], 405);
require_once __DIR__ . '/uploadlib.php';
uploadlib_reject_oversized_request();
rate_limit($cfg, 'write');
$actor = Auth::require($cfg, $project, 'edit_pages', true, auth_msg('deny_edit_pages'));
$op = (string)($_POST['op'] ?? '');

if ($op === 'preview') {
    json_out(['ok' => true, 'html' => pages_render(pages_clean_md($_POST['md'] ?? ''))]);
}

if ($op === 'upload') {
    $f = $_FILES['image'] ?? null;
    if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) json_out(['error' => 'need image'], 400);
    $mimes = !empty($cfg['allowed_mime']) ? $cfg['allowed_mime'] : ['image/webp' => 'webp', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
    $saved = uploadlib_store_file($cfg, $project, $f, 'photos', $mimes, (int)($cfg['max_bytes'] ?? 12 * 1024 * 1024), null, 'photo');
    json_out(['ok' => true, 'src' => 'souliong-photo:' . $saved['rel'], 'url' => Route::api('photo', ['f' => $saved['rel']])]);
}

if ($op === 'save') {
    $in = json_decode((string)($_POST['pages'] ?? ''), true);
    if (!is_array($in) || count($in) > PAGES_MAX_PAGES || ($in && array_keys($in) !== range(0, count($in) - 1))) json_out(['error' => 'bad request'], 400);
    $cur = pages_load($cfg, $project);
    if ((string)($_POST['base_rev'] ?? '') !== $cur['rev']) json_out(['error' => '說明頁已被別人更新，請重新載入後再編輯'], 409);
    $pages = []; $ids = [];
    foreach ($in as $p) {
        if (!is_array($p)) json_out(['error' => 'bad request'], 400);
        $title = clean_str(isset($p['title']) ? (string)$p['title'] : null, PAGES_MAX_TITLE);
        if ($title === null) json_out(['error' => 'need title'], 400);
        $id = preg_match('/^[a-f0-9]{8}$/', (string)($p['id'] ?? '')) ? (string)$p['id'] : bin2hex(random_bytes(4));
        if (isset($ids[$id])) $id = bin2hex(random_bytes(4));
        $ids[$id] = true;
        $pages[] = ['id' => $id, 'title' => $title, 'md' => pages_clean_md($p['md'] ?? '')];
    }
    $rev = bin2hex(random_bytes(6));
    $doc = ['rev' => $rev, 'updated_at' => gmdate('c'), 'by' => clean_str((string)($_POST['name'] ?? ''), 60), 'pages' => $pages];
    $path = pages_file($cfg, $project);
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $lock = @fopen($path . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) json_out(['error' => 'busy'], 503);
    // 拿到鎖之後再核對一次版本，兩個人同時存檔時後到的那個得到 409
    if ((string)pages_load($cfg, $project)['rev'] !== $cur['rev']) { flock($lock, LOCK_UN); json_out(['error' => '說明頁已被別人更新，請重新載入後再編輯'], 409); }
    $ok = @file_put_contents($tmp, json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false && @rename($tmp, $path);
    flock($lock, LOCK_UN); fclose($lock);
    if (!$ok) { @unlink($tmp); json_out(['error' => 'save failed'], 500); }
    $out = array_map(static fn(array $p): array => ['id' => $p['id'], 'title' => $p['title'], 'md' => $p['md'], 'html' => pages_render($p['md'])], $pages);
    json_out(['ok' => true, 'rev' => $rev, 'updated_at' => $doc['updated_at'], 'pages' => $out]);
}

json_out(['error' => 'bad request'], 400);
