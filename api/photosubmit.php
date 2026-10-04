<?php
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/routes.php';
require_once __DIR__ . '/publicphoto.php';
require_once __DIR__ . '/embedorigins.php';
require_once __DIR__ . '/uploadlib.php';
$cfg = require __DIR__ . '/config.php';
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) json_out(['error' => 'method not allowed'], 405);
$project = (string)($_GET['project'] ?? '');
if (!preg_match('/^[a-z0-9_-]{1,40}$/D', $project)) json_out(['error' => 'invalid project'], 400);
$path = project_dir($cfg, $project) . '/meta.json';
$meta = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
if (!is_array($meta)) json_out(['error' => 'project not found'], 404);
header('Cache-Control: no-store');
$status = public_photo_state($meta);
$origins = embed_origins_allowed($cfg, $meta);
if (($action ?? $_GET['api'] ?? '') === 'photostatus') {
    embed_send_cors($origins);
    json_out($status);
}
$embed = ($_GET['embed'] ?? '') === '1';
if (!$embed || !embed_send_frame_headers($origins)) {
    header("Content-Security-Policy: frame-ancestors 'none'");
    header('X-Frame-Options: DENY');
}
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
$esc = fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$asset = fn($file) => $esc(Route::api('appasset', ['f' => $file, 'v' => (string)filemtime(__DIR__ . '/../' . $file)]));
$boot = ['project' => $project, 'status' => $status, 'origins' => $origins,
    'uploadUrl' => Route::api('upload'), 'statusUrl' => Route::api('photostatus', ['project' => $project]),
    'maxBytes' => (int)(uploadlib_limits($cfg)['kinds']['photo'] ?? $cfg['max_bytes'])];
?>
<!doctype html>
<html lang="zh-Hant"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>留下照片 · <?= $esc($meta['title'] ?? $project) ?></title>
<link rel="stylesheet" href="<?= $asset('assets/css/photo-submit.css') ?>"></head>
<body><main>
<p id="availability" role="status"></p>
<form id="photo-form">
  <div class="actions">
    <button type="button" id="pick-photo">選擇照片</button>
    <button type="button" id="take-photo">拍攝照片</button>
  </div>
  <input id="photo-file" type="file" accept="image/*,.heic,.heif" hidden>
  <input id="photo-camera" type="file" accept="image/*" capture="environment" hidden>
  <img id="preview" alt="準備投稿的照片" hidden>
  <?php if (!$embed): ?><label>暱稱<input id="photo-name" maxlength="<?= (int)$cfg['name_max'] ?>" autocomplete="nickname"></label><?php endif; ?>
  <label for="photo-comment">照片說明</label>
  <textarea id="photo-comment" rows="3" maxlength="<?= (int)$cfg['comment_max'] ?>" placeholder="寫下你拍到的地方或想分享的觀察"></textarea>
  <label class="consent"><input id="photo-consent" type="checkbox" required>我同意照片與說明依 CC0 公開分享</label>
  <button id="photo-submit" type="submit" disabled>送出照片</button>
  <progress id="photo-progress" max="100" value="0" hidden aria-label="上傳進度"></progress>
  <p id="photo-message" role="status" aria-live="polite"></p>
</form>
</main>
<script>window.PHOTO_SUBMIT = <?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="<?= $asset('assets/js/contrib/kind-photo.js') ?>"></script>
<script src="<?= $asset('assets/js/photo-submit.js') ?>"></script>
</body></html>
