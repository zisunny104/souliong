<?php
require_once __DIR__ . '/socialcardlib.php';
require_once __DIR__ . '/security.php';
$cfg = require __DIR__ . '/config.php';

function social_preview_fail(int $code): void
{
    http_response_code($code); header('Cache-Control: no-store'); exit;
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) { header('Allow: GET, HEAD'); social_preview_fail(405); }
$project = $_GET['project'] ?? '';
$entryId = $_GET['entry'] ?? '';
$spotRef = $_GET['spot'] ?? '';
if (!is_string($project) || !preg_match('/^[a-z0-9_-]{1,40}$/D', $project)
    || !is_string($entryId) || ($entryId !== '' && !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $entryId))
    || !is_string($spotRef) || ($spotRef !== '' && !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $spotRef))) social_preview_fail(400);
$metaPath = project_dir($cfg, $project) . '/meta.json';
$meta = is_file($metaPath) ? json_decode((string)file_get_contents($metaPath), true) : null;
if (!is_array($meta)) social_preview_fail(404);
$data = souliong_social_data($cfg, $project, $meta, $entryId, $spotRef);
if ($data === null) social_preview_fail(404);
if (!souliong_social_ready($cfg)) social_preview_fail(503);
$revision = souliong_social_revision($cfg, $project, $meta, $data);
header('Content-Type: image/jpeg');
header('X-Content-Type-Options: nosniff');
$dir = rtrim($cfg['state_dir'], '/\\') . '/social-previews/' . $project;
if (!is_dir($dir)) @mkdir($dir, 0775, true);
$key = hash('sha256', ($entryId !== '' ? 'entry:' . $entryId : ($spotRef !== '' ? 'spot:' . ($data['spotId'] ?? $spotRef) : 'project')));
$path = $dir . '/' . $key . '.jpg'; $infoPath = $dir . '/' . $key . '.json';
$cache = is_file($infoPath) ? json_decode((string)@file_get_contents($infoPath), true) : null;
$bytes = null;
$maxAge = 300;
if (is_array($cache) && ($cache['revision'] ?? '') === $revision && is_file($path)
    && (int)($cache['expires'] ?? 0) > time() && filesize($path) <= 4 * 1024 * 1024) {
    $bytes = (string)file_get_contents($path);
    $maxAge = !empty($cache['retry']) ? 60 : 300;
}
if ($bytes === null) {
    $cfg['rate_limits']['socialpreview'] ??= ['max' => 30, 'window' => 60];
    rate_limit($cfg, 'socialpreview');
    $mapBytes = souliong_social_map($cfg, $project, $data['spotId'], (int)($meta['zoom'] ?? 16));
    $data['mapBytes'] = $mapBytes;
    if ($mapBytes !== null) $data['attribution'] = souliong_social_attribution($cfg, $project, $meta);
    else { $data['map'] = null; unset($data['pin']); }
    $preserve = $mapBytes === null && is_array($cache) && ($cache['revision'] ?? '') === $revision
        && !empty($cache['map']) && is_file($path) && filesize($path) <= 4 * 1024 * 1024;
    $bytes = $preserve ? (string)file_get_contents($path) : souliong_social_render($data, souliong_social_font($cfg));
    if ($bytes === null) social_preview_fail(503);
    $temp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($temp, $bytes, LOCK_EX) !== false) {
        if (@rename($temp, $path)) @file_put_contents($infoPath, json_encode(['revision' => $revision, 'expires' => time() + ($mapBytes !== null ? 86400 : 60), 'map' => $mapBytes !== null || $preserve, 'retry' => $mapBytes === null]), LOCK_EX);
        else @unlink($temp);
    }
    if ($mapBytes === null) $maxAge = 60;
}
header('Cache-Control: public, max-age=' . $maxAge);
$etag = '"' . hash('sha256', $bytes) . '"';
header('ETag: ' . $etag);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
header('Content-Length: ' . strlen($bytes));
if ($method !== 'HEAD') echo $bytes;
