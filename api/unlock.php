<?php
// 驗證投稿代碼（限特定人上傳）。POST project, code → ok / 403。
require __DIR__ . '/store.php';
require __DIR__ . '/security.php';
$cfg = require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_out(['error' => 'POST only'], 405); }
rate_limit($cfg, 'unlock');

$project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
if ($project === '' || !is_dir($cfg['projects_dir'] . '/' . $project)) { json_out(['error' => 'bad request'], 400); }

// 沒有任何有效碼＝這張地圖現在沒開放投稿，連解鎖都不該成功（前端也不會顯示解鎖鈕）
if (!contrib_open($cfg, $project)) { json_out(['error' => '這張地圖目前未開放投稿'], 403); }
$given = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));   // 純數字碼：容忍空白/貼上
// 只驗證不計次（次數在實際上傳時才扣，見 upload.php）
if (!code_check($cfg, $project, $given, false)) {
    json_out(['error' => '投稿代碼不正確（或已到期、用完次數）'], 403);
}

// 可選：以投稿者 PIN 建立跨裝置身分（cpin），並可帶暱稱（cname）。匿名則不帶。
$cpin = trim((string)($_POST['cpin'] ?? ''));
if ($cpin !== '') {
    if (strlen($cpin) < 4 || strlen($cpin) > 64) { json_out(['error' => '身分 PIN 至少 4 位'], 400); }
    $token = contrib_token($cfg, $project, $cpin);
    [$cid, $label] = contrib_register($cfg, $project, $token, $_POST['cname'] ?? null);
    json_out(['ok' => true, 'contrib' => ['token' => $token, 'id' => $cid, 'label' => $label]]);
}
json_out(['ok' => true]);
