<?php
/**
 * 向 Overpass 預先抓取專案範圍內的 OSM 資料，存成 projects/<p>/ 底下的 GeoJSON：
 *   roofs  有屋頂造型、樓層數或外牆／屋頂顏色材質的整棟建物（含全部部件）→ roofs.geojson
 *   trees  natural=tree 樹木與 tree_row 行道樹 → trees.geojson
 *   power  電塔、電桿與高壓／配電線路 → power.geojson
 * 欄位定義見 api/roofslib.php、api/treeslib.php、api/powerlib.php；資料集登記表見 api/osmdata.php。
 *
 * 用法：php tools/osm_fetch.php <project> [--kind=roofs|trees|power] [--bbox=南,西,北,東] [--endpoint=<url>] [--contact=<信箱或網址>] [--dry-run]
 *   不指定 --kind 就依序抓全部資料集。
 *   範圍預設取這張地圖點位的外框加 25% 邊距；沒有點位就用 meta.json 的 center 上下左右各約 500 公尺。
 *   --dry-run 只印查詢與範圍，不連線、不寫檔。
 *
 * 對 Overpass 的禮貌：一次一個請求（不平行、資料集之間停頓）、自訂 User-Agent（建議用 --contact 留聯絡方式）、
 * 伺服器與本地逾時、失敗最多重試 3 次並遞增等待。筆數超過資料集上限（範圍太大）、抓取失敗或回應被截斷時，
 * 都不動既有檔案。
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}
$root = dirname(__DIR__);
$cfg = require $root . '/api/config.php';
require_once $root . '/api/osmdata.php';

const OSM_DEFAULT_ENDPOINT = 'https://overpass-api.de/api/interpreter';
const OSM_MAX_SPAN = 0.2;          // 單邊上限（度）：約 22 公里，超過請縮小範圍分次抓
const OSM_ATTEMPTS = 3;
const OSM_SERVER_TIMEOUT = 90;
const OSM_CLIENT_TIMEOUT = 120;
const OSM_PAUSE_BETWEEN_KINDS = 5;

function osm_die(string $msg): never
{
    fwrite(STDERR, $msg . "\n");
    exit(1);
}

$opts = [];
$pos = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? '1';
    } else {
        $pos[] = $a;
    }
}
$proj = $pos[0] ?? '';
$kinds = souliong_osm_kinds();
$want = isset($opts['kind']) ? [$opts['kind']] : array_keys($kinds);
$probe = souliong_osm_path($cfg, $proj, $want[0]);
if ($probe === null || !is_dir(dirname($probe))) {
    osm_die('用法：php tools/osm_fetch.php <project> [--kind=' . implode('|', array_keys($kinds)) . "] [--bbox=S,W,N,E] [--dry-run]\n找不到專案或資料集：$proj");
}
$dir = dirname($probe);

$bbox = isset($opts['bbox']) ? osm_parse_bbox($opts['bbox']) : osm_project_bbox($dir);
[$s, $w, $n, $e] = $bbox;
if (!osm_bbox_ok($s, $w, $n, $e)) {
    osm_die('範圍不合法（南<北、西<東、緯度 ±85、經度 ±180，單邊不超過 ' . OSM_MAX_SPAN . ' 度）');
}
echo "專案 $proj  範圍 S,W,N,E = " . implode(',', $bbox) . "\n";

$endpoint = (string)($opts['endpoint'] ?? ($cfg['overpass_url'] ?? OSM_DEFAULT_ENDPOINT));
if (!preg_match('#^https?://#i', $endpoint)) {
    osm_die('endpoint 必須是 http(s) 網址');
}
$contact = isset($opts['contact']) ? ' (' . preg_replace('/[^\w@.:\/+-]/', '', $opts['contact']) . ')' : '';
$ua = 'Souliong-osm_fetch/1.0' . $contact;

$failed = false;
foreach ($want as $i => $kind) {
    $def = $kinds[$kind];
    // 多要一筆：超過上限時才分得出「剛好到頂」與「被截斷」
    $query = sprintf(
        "[out:json][timeout:%d][bbox:%s];\n(\n  %s\n);\nout geom %d;",
        OSM_SERVER_TIMEOUT,
        implode(',', $bbox),
        str_replace('; ', ";\n  ", $def['query']),
        $def['max'] + 1
    );
    if (isset($opts['dry-run'])) {
        echo "\n[$kind]\n$query\n";
        continue;
    }
    if ($i > 0) {
        sleep(OSM_PAUSE_BETWEEN_KINDS);
    }
    echo "\n[$kind] ";
    $failed = !osm_fetch_kind($kind, $def, souliong_osm_path($cfg, $proj, $kind), $query, $endpoint, $ua, $bbox) || $failed;
}
exit($failed ? 1 : 0);

// ── 輔助 ────────────────────────────────────────────────────────────────

/** 抓一個資料集並寫檔；成功回 true，失敗（不動既有檔案）印原因回 false */
function osm_fetch_kind(string $kind, array $def, string $out, string $query, string $endpoint, string $ua, array $bbox): bool
{
    $json = null;
    for ($try = 1; $try <= OSM_ATTEMPTS; $try++) {
        [$code, $body, $err] = osm_post($endpoint, $query, $ua);
        $data = $code === 200 ? json_decode($body, true) : null;
        // remark 帶 runtime error 表示伺服器中途放棄，elements 可能只是一部分
        if (is_array($data) && isset($data['elements']) && !preg_match('/runtime error|timed out|out of memory/i', (string)($data['remark'] ?? ''))) {
            $json = $data;
            break;
        }
        echo "第 $try 次失敗：" . ($err ?: "HTTP $code" . (is_array($data) ? ' ' . ($data['remark'] ?? '') : '')) . "\n";
        if ($try < OSM_ATTEMPTS) {
            sleep(10 * $try);
        }
    }
    if ($json === null) {
        fwrite(STDERR, "$kind：Overpass 取不到完整資料，既有檔案未更動。\n");
        return false;
    }
    if (count($json['elements']) > $def['max']) {
        fwrite(STDERR, "$kind：超過上限 {$def['max']} 筆，範圍太大；請用 --bbox 縮小後分次抓。既有檔案未更動。\n");
        return false;
    }

    if (isset($def['collect'])) {
        $features = ($def['collect'])($json['elements']);
        $dropped = count($json['elements']) - count($features);
    } else {
        $features = [];
        $dropped = 0;
        foreach ($json['elements'] as $el) {
            $f = is_array($el) ? ($def['feature'])($el) : null;
            if ($f === null) $dropped++; else $features[] = $f;
        }
    }
    usort($features, fn($a, $b) => strcmp($a['properties']['id'], $b['properties']['id']));

    [$s, $w, $n, $e] = $bbox;
    $fc = [
        'type'        => 'FeatureCollection',
        'attribution' => '© OpenStreetMap contributors (ODbL)',
        'bbox'        => [$w, $s, $e, $n],
        'fetchedAt'   => gmdate('c'),
        'features'    => $features,
    ];
    $enc = json_encode($fc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $tmp = $out . '.tmp';
    if ($enc === false || file_put_contents($tmp, $enc, LOCK_EX) === false || !rename($tmp, $out)) {
        @unlink($tmp);
        fwrite(STDERR, "$kind：寫檔失敗：$out\n");
        return false;
    }
    printf("%d 筆寫入 %s（%d 筆因缺屬性或幾何不合被略過，%d 位元組）\n", count($features), basename($out), $dropped, strlen($enc));
    return true;
}

function osm_bbox_ok(float $s, float $w, float $n, float $e): bool
{
    return $s < $n && $w < $e && $s >= -85 && $n <= 85 && $w >= -180 && $e <= 180
        && ($n - $s) <= OSM_MAX_SPAN && ($e - $w) <= OSM_MAX_SPAN;
}

/** "S,W,N,E" → 四個浮點數 */
function osm_parse_bbox(string $s): array
{
    $p = array_map('trim', explode(',', $s));
    if (count($p) !== 4 || array_filter($p, fn($x) => !is_numeric($x))) {
        osm_die('--bbox 格式：南,西,北,東（十進位度數）');
    }
    return array_map('floatval', $p);
}

/** 專案點位外框＋25% 邊距（至少各邊 0.004 度）；沒有點位則取 meta.center 為中心 */
function osm_project_bbox(string $dir): array
{
    $lats = $lons = [];
    foreach (@file($dir . '/spots.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $r = json_decode($line, true);
        if (is_array($r) && isset($r['lat'], $r['lon']) && is_numeric($r['lat']) && is_numeric($r['lon'])) {
            $lats[] = (float)$r['lat'];
            $lons[] = (float)$r['lon'];
        }
    }
    if (!$lats) {
        $c = json_decode((string)@file_get_contents($dir . '/meta.json'), true)['center'] ?? null;
        if (!is_array($c) || count($c) < 2) {
            osm_die('專案沒有點位也沒有 meta.center，請用 --bbox 指定範圍。');
        }
        $lats = [(float)$c[0]];
        $lons = [(float)$c[1]];
    }
    $padLat = max(0.004, (max($lats) - min($lats)) * 0.25);
    $padLon = max(0.004, (max($lons) - min($lons)) * 0.25);
    return [
        round(min($lats) - $padLat, 5), round(min($lons) - $padLon, 5),
        round(max($lats) + $padLat, 5), round(max($lons) + $padLon, 5),
    ];
}

/**
 * POST 表單參數 data=<query>；回傳 [HTTP 狀態碼, 內文, 錯誤說明]。
 * PHP 有 https 支援就直接用；沒有（例如未啟用 openssl 的環境）退回系統的 curl 指令。
 */
function osm_post(string $url, string $query, string $ua): array
{
    if (in_array('https', stream_get_wrappers(), true) || stripos($url, 'https://') !== 0) {
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: $ua\r\nAccept: application/json\r\n",
            'content'       => http_build_query(['data' => $query]),
            'timeout'       => OSM_CLIENT_TIMEOUT,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $code = (int)$m[1];
        }
        return [$code, (string)$body, $body === false ? '連線失敗' : ''];
    }
    $cmd = [
        'curl', '-sS', '--max-time', (string)OSM_CLIENT_TIMEOUT, '-A', $ua,
        '-w', "\n%{http_code}", '--data-urlencode', 'data@-', $url,
    ];
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) {
        return [0, '', '無法執行 curl'];
    }
    fwrite($pipes[0], $query);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]);
    $err = trim((string)stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($p);
    $nl = strrpos($raw, "\n");
    return [$nl === false ? 0 : (int)substr($raw, $nl + 1), $nl === false ? '' : substr($raw, 0, $nl), $err];
}
