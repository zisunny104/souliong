<?php
// 匿名聚合統計：只累加計數，不存 IP / 個資 / 逐筆事件。
// 累加：POST project, type(view|point|session|device|feature)[, id, h, d]
// 讀取：GET  ?read=1&token=管理密碼  → 回傳統計 JSON（分析用）
require __DIR__ . '/store.php';
require __DIR__ . '/security.php';
require __DIR__ . '/stats.php';
require __DIR__ . '/features.php';
$cfg = require __DIR__ . '/config.php';

$project = preg_replace('/[^a-z0-9_-]/', '', $_REQUEST['project'] ?? '');
if ($project === '' || !is_dir($cfg['projects_dir'] . '/' . $project)) { json_out(['error' => 'bad request'], 400); }

// 讀取（分析用，需管理密碼）
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['read'])) {
    if (!site_perm($cfg, 'view_stats')) { json_out(['error' => 'forbidden'], 403); }   // 靠管理 cookie，不用網址 token
    json_out(['project' => $project, 'stats' => stats_read($cfg, $project)]);
}

rate_limit($cfg, 'stat');
$type = preg_replace('/[^a-z]/', '', $_REQUEST['type'] ?? '');
$id   = (string)($_REQUEST['id'] ?? '');

$FEATURES = array_keys(souliong_features());
$h = (int)($_REQUEST['h'] ?? -1);
$d = (int)($_REQUEST['d'] ?? -1);
stats_apply($cfg, $project, function (&$s) use ($type, $id, $h, $d, $FEATURES) {
    switch ($type) {
        case 'view':
            stats_bump($s, 'views');
            if ($h >= 0 && $h <= 23) stats_bump($s, 'by_hour', (string)$h, 24);
            if ($d >= 0 && $d <= 6)  stats_bump($s, 'by_dow', (string)$d, 7);
            break;
        case 'point':
            if (ctype_digit($id) && strlen($id) <= 5) stats_bump($s, 'points', $id, 3000);
            break;
        case 'session':
            stats_bump($s, 'sessions');
            $ub = stats_ua_buckets((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));   // 一個工作階段記一次，只計大類不存 UA
            stats_bump($s, 'browser', $ub['browser'], 12);
            stats_bump($s, 'os', $ub['os'], 8);
            break;
        case 'device':
            if ($id === 'mobile' || $id === 'desktop') stats_bump($s, 'device', $id, 4);
            break;
        case 'theme':
            if ($id === 'light' || $id === 'dark') stats_bump($s, 'theme', $id, 2);
            break;
        case 'feature':
            if (in_array($id, $FEATURES, true)) stats_bump($s, 'features', $id, 20);
            break;
    }
});
json_out(['ok' => true]);
