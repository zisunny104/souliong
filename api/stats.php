<?php
/**
 * 聚合統計工具：只累加「計數」，不存個資、不存逐筆事件列。
 * 每個項目一個極小的 projects/<project>/stats.json，例如：
 *   {
 *     "views": 1234, "sessions": 320, "uploads": 88,
 *     "points": {"9": 41, "23": 77},        // 各點位被點開次數（熱門點）
 *     "by_hour": {"14": 90, ...},           // 依「使用者本地小時」分佈（探索時段）
 *     "by_dow":  {"6": 210, ...},           // 依星期（0=日）
 *     "device":  {"mobile": 900, "desktop": 334},
 *     "browser": {"chrome": 200, "safari": 90, "line": 30},   // UA 歸大類後計數（不存原始 UA）
 *     "os":      {"ios": 150, "android": 120, "windows": 50},
 *     "features":{"route": 40, "photos": 120, "filter": 22, "embed": 5, "random": 18, "upload": 88}
 *   }
 * 效能：單一小檔、加鎖讀寫、有 key 數上限，攻擊者無法灌爆。
 *
 * 之後要「顯示」怎麼做（不需另建資料表）：
 *   讀取：登入管理後（cookie）GET  ?api=stat&project=<id>&read=1   （見 stat.php）
 *   前端可用回傳 JSON 畫圖，例如：
 *     - points 由大到小排序 → 熱門點位長條圖 / 在地圖上用大小標記
 *     - by_hour → 24 格熱力/折線；by_dow → 一週長條
 *     - device / features → 圓餅或數字卡
 *   也可在 manager.php 內加一段 <script> fetch 這個 read API 後用 <canvas> 畫。
 */

function stats_file(array $cfg, string $project): string {
    return project_dir($cfg, $project) . '/stats.json';
}

function stats_read(array $cfg, string $project): array {
    $f = stats_file($cfg, $project);
    $s = is_file($f) ? json_decode((string)file_get_contents($f), true) : [];
    return is_array($s) ? $s : [];
}

/** 以檔案鎖套用一批遞增（$fn 收到 &$s 陣列） */
function stats_apply(array $cfg, string $project, callable $fn): void {
    $f = stats_file($cfg, $project);
    $fp = @fopen($f, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $s = json_decode(trim((string)stream_get_contents($fp)), true);
    if (!is_array($s)) $s = [];
    $fn($s);
    $s['updated'] = gmdate('c');
    ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    flock($fp, LOCK_UN); fclose($fp);
}

/** 從 User-Agent 歸類瀏覽器與作業系統大類（只回傳類別名供計數，絕不儲存原始 UA 字串） */
function stats_ua_buckets(string $ua): array {
    $b = 'other';
    if (stripos($ua, 'Line/') !== false) $b = 'line';                 // 內建瀏覽器要先判斷，它們的 UA 也帶 Chrome/Safari 字樣
    elseif (stripos($ua, 'FBAN') !== false || stripos($ua, 'FBAV') !== false || stripos($ua, 'FB_IAB') !== false || stripos($ua, 'Orca-Android') !== false) $b = 'facebook';   // 含 Messenger
    elseif (stripos($ua, 'Instagram') !== false || stripos($ua, 'Barcelona') !== false) $b = 'instagram';   // 含 Threads
    elseif (stripos($ua, 'MicroMessenger') !== false) $b = 'wechat';
    elseif (stripos($ua, 'DuckDuckGo') !== false || stripos($ua, 'Ddg') !== false) $b = 'duckduckgo';
    elseif (stripos($ua, 'Edg') !== false) $b = 'edge';
    elseif (stripos($ua, 'SamsungBrowser') !== false) $b = 'samsung';
    elseif (stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false) $b = 'opera';
    elseif (stripos($ua, 'Firefox/') !== false || stripos($ua, 'FxiOS') !== false) $b = 'firefox';
    elseif (stripos($ua, 'Chrome/') !== false || stripos($ua, 'CriOS') !== false) $b = 'chrome';
    elseif (stripos($ua, 'Safari') !== false) $b = 'safari';
    $o = 'other';
    if (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false || stripos($ua, 'iPod') !== false) $o = 'ios';
    elseif (stripos($ua, 'Android') !== false) $o = 'android';
    elseif (stripos($ua, 'Windows') !== false) $o = 'windows';
    elseif (stripos($ua, 'Macintosh') !== false || stripos($ua, 'Mac OS X') !== false) $o = 'macos';
    elseif (stripos($ua, 'Linux') !== false) $o = 'linux';
    return ['browser' => $b, 'os' => $o];
}

/** 遞增一個計數；$sub 為子鍵（如點位編號）；$capKeys 限制子鍵數量以防膨脹 */
function stats_bump(array &$s, string $key, $sub = null, int $capKeys = 0): void {
    if ($sub === null) { $s[$key] = ($s[$key] ?? 0) + 1; return; }
    if (!isset($s[$key]) || !is_array($s[$key])) $s[$key] = [];
    if ($capKeys > 0 && !isset($s[$key][$sub]) && count($s[$key]) >= $capKeys) return;
    $s[$key][$sub] = ($s[$key][$sub] ?? 0) + 1;
}
