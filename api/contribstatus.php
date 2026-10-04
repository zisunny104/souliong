<?php
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/contribgate.php';
require_once __DIR__ . '/embedorigins.php';
$cfg = require __DIR__ . '/config.php';
$project = (string)($_POST['project'] ?? $_GET['project'] ?? '');
if (!preg_match('/^[a-z0-9_-]{1,40}$/D', $project)) json_out(['error' => 'invalid project'], 400);
$path = project_dir($cfg, $project) . '/meta.json';
$meta = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
if (!is_array($meta)) json_out(['error' => 'project not found'], 404);
header('Cache-Control: no-store');
embed_send_cors(embed_origins_allowed($cfg, $meta));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); exit; }
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) json_out(['error' => 'method not allowed'], 405);
$cfg['rate_limits']['contribstatus'] = $cfg['rate_limits']['contribstatus'] ?? ['max' => 120, 'window' => 60];
rate_limit($cfg, 'contribstatus');
$status = contrib_access_state($cfg, $project, $meta);
if ($method === 'POST') {
    $who = Contributor::fromRequest();
    $status['blocked'] = is_blocked($cfg, $project, $who->ownerHash(), $who->contribId());
    $given = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
    $status['codeValid'] = !$status['blocked'] && $status['codesAvailable'] && code_check($cfg, $project, $given, false);
    if ($status['blocked']) { $status['open'] = false; $status['codesAvailable'] = false; }
}
json_out($status);
