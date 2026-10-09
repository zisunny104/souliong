<?php
require_once __DIR__ . '/routes.php';
require_once __DIR__ . '/layers.php';

function souliong_social_runtime(array $cfg): ?array
{
    if (!function_exists('proc_open') || ($cfg['social_preview_map'] ?? true) === false) return null;
    $dir = rtrim($cfg['state_dir'], '/\\') . '/social-preview-runtime';
    $nodePaths = isset($cfg['social_preview_node']) ? [(string)$cfg['social_preview_node']]
        : array_merge([$dir . '/node/bin/node', '/usr/bin/node', '/usr/local/bin/node'], array_map(fn($path) => rtrim($path, '/') . '/node', array_filter(explode(PATH_SEPARATOR, (string)getenv('PATH')))));
    $node = null;
    foreach ($nodePaths as $path) if (is_executable($path)) { $node = $path; break; }
    $playwright = (string)($cfg['social_preview_playwright'] ?? $dir . '/node_modules/playwright-core');
    $browsers = isset($cfg['social_preview_chromium']) ? [(string)$cfg['social_preview_chromium']]
        : array_merge(['/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome', '/usr/bin/google-chrome-stable'], glob($dir . '/browsers/chromium-*/chrome-linux*/chrome') ?: []);
    $chromium = null;
    foreach ($browsers as $path) if (is_executable($path)) { $chromium = $path; break; }
    if (!$node || !is_file($playwright . '/package.json') || !$chromium) return null;
    $url = (string)($cfg['social_preview_base_url'] ?? @file_get_contents(rtrim($cfg['state_dir'], '/\\') . '/deploy_check_url'));
    $url = trim($url);
    $parts = parse_url($url);
    if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return null;
    $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    return compact('node', 'playwright', 'chromium', 'origin');
}

/** 最近一次渲染失敗原因，只留一份、不論 debug 是否開啟，方便查地圖名片為何退回淡色底。 */
function souliong_social_log(array $cfg, string $message): void
{
    $line = preg_replace('/[\r\n]+/', ' ', substr($message, -1500));   // 單行，避免頁面錯誤訊息插入偽造的紀錄行
    @file_put_contents(rtrim($cfg['state_dir'], '/\\') . '/social-preview-error.log', date('c') . ' ' . $line . "\n", LOCK_EX);
}

/** 同時只跑一個瀏覽器程序，重用實際的地圖引擎、底圖選擇與標記繪製。別的請求正在渲染時 $busy 為 true。 */
function souliong_social_map(array $cfg, string $project, ?string $spotId, int $zoom, ?bool &$busy = null): ?string
{
    $busy = false;
    $runtime = souliong_social_runtime($cfg);
    if (!$runtime) return null;
    $lockPath = rtrim($cfg['state_dir'], '/\\') . '/social-preview-renderer.lock';
    $lock = @fopen($lockPath, 'c');
    if (!$lock) return null;
    if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); $busy = true; return null; }
    $process = null; $pipes = [];
    try {
        $home = rtrim($cfg['state_dir'], '/\\') . '/social-preview-home';
        if (!is_dir($home)) @mkdir($home, 0775, true);
        // 專案有勾選「無標註」的底圖就直接用它（?layer= 只認專案已啟用的底圖）；沒有才在瀏覽器裡隱藏文字圖層
        $layerParam = '';
        $metaFile = project_dir($cfg, $project) . '/meta.json';
        $meta = is_file($metaFile) ? json_decode((string)file_get_contents($metaFile), true) : null;
        foreach (is_array($meta) ? souliong_layers_visible($cfg, $meta, $project) : [] as $layer) {
            if (($layer['pane'] ?? 'art') === 'base' && str_ends_with((string)($layer['id'] ?? ''), '-nolabels')) { $layerParam = '&layer=' . rawurlencode((string)$layer['id']); break; }
        }
        $url = $runtime['origin'] . Route::map($project) . '?embed=1&ui=bare&view=meta&contributions=0' . $layerParam;
        $process = @proc_open([$runtime['node'], dirname(__DIR__) . '/tools/social-preview-map.cjs'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) return null;
        fwrite($pipes[0], json_encode([
            'url' => $url, 'spot' => $spotId, 'zoom' => max(1, min(20, $zoom)),
            'playwright' => $runtime['playwright'], 'chromium' => $runtime['chromium'],
            'debug' => !empty($cfg['debug']), 'sandbox' => empty($cfg['social_preview_no_sandbox']), 'home' => is_dir($home) && is_writable($home) ? $home : null,
            'allowedHosts' => array_values(array_filter((array)($cfg['social_preview_hosts'] ?? []), fn($host) => is_string($host) && preg_match('/^[a-z0-9.-]+$/iD', $host))),
        ], JSON_UNESCAPED_SLASHES));
        fclose($pipes[0]); unset($pipes[0]);
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $bytes = ''; $errors = ''; $deadline = microtime(true) + 20;
        do {
            $bytes .= stream_get_contents($pipes[1]); $errors = substr($errors . stream_get_contents($pipes[2]), -2000);
            $status = proc_get_status($process);
            if (strlen($bytes) > 4 * 1024 * 1024 || microtime(true) >= $deadline) {
                souliong_social_log($cfg, 'renderer stopped: ' . (microtime(true) >= $deadline ? 'timeout' : 'output too large') . ($errors !== '' ? ' / ' . $errors : ''));
                proc_terminate($process);
                for ($wait = 0; $wait < 50 && proc_get_status($process)['running']; $wait++) usleep(40000);   // 先請它結束，兩秒後仍在就強制終止，不讓 proc_close 一直等
                if (proc_get_status($process)['running']) proc_terminate($process, 9);
                return null;
            }
            if ($status['running']) usleep(40000);
        } while ($status['running']);
        $bytes .= stream_get_contents($pipes[1]);
        $errors = substr($errors . stream_get_contents($pipes[2]), -2000);
        $info = @getimagesizefromstring($bytes);
        if (!$info) souliong_social_log($cfg, 'url=' . $url . ' ' . ($errors !== '' ? $errors : 'renderer produced no image'));
        return $info && $info[0] === 1200 && $info[1] === 630 && ($info['mime'] ?? '') === 'image/png' ? $bytes : null;
    } finally {
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        if (is_resource($process)) proc_close($process);
        flock($lock, LOCK_UN); fclose($lock);
    }
}
