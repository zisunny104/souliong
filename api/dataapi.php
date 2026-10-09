<?php
/**
 * 對外唯讀資料 API（v1）。由 index.php 以 $dataApiKind 指定要哪一支：
 *   GET ?api=project&project=<slug>   專案描述：標題、預設視角、圖層、分類
 *   GET ?api=spots&project=<slug>     目前有效狀態的點位清單（spotId／num／座標／導航連結）
 *
 * 輸出一律由白名單欄位組成，不含任何雜湊（contrib_hash、owner_hash、src_hash）、IP、投稿者身分、
 * 後台網址、CSRF、perms。點位以起點＋edit_of 鏈算出的有效狀態為準（api/spotlib.php）。
 * spotId 是穩定外部鍵；num 只是顯示編號，可能被重用，外部系統不可當鍵。
 *
 * 快取：ETag＋Cache-Control: public, max-age=60，支援 If-None-Match 回 304。
 * 跨來源：只有 Origin 在允許清單內才送 CORS 標頭（見 api/embedorigins.php），不用 *。
 * 不開 session、不送 Set-Cookie。
 */
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/spotlib.php';
require_once __DIR__ . '/markercolors.php';
require_once __DIR__ . '/oglib.php';
require_once __DIR__ . '/layers.php';
require_once __DIR__ . '/navlinks.php';
require_once __DIR__ . '/embedorigins.php';
$cfg = require __DIR__ . '/config.php';

$kind = ($dataApiKind ?? '') === 'spots' ? 'spots' : 'project';

/** 輸出 JSON 錯誤並結束（CORS 標頭已在此之前送出，瀏覽器端讀得到錯誤內容）。 */
function dataapi_fail(int $code, string $error): void
{
    header('Cache-Control: no-store');
    json_out(['error' => $error], $code);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$slug = (string)($_GET['project'] ?? '');
$meta = null;
if (preg_match('/^[a-z0-9_-]{1,40}$/D', $slug)) {
    $mf = project_dir($cfg, $slug) . '/meta.json';
    $meta = is_file($mf) ? json_decode((string)@file_get_contents($mf), true) : null;
    if (!is_array($meta)) $meta = null;
}

// CORS 在任何可能提早結束的分支之前送出（含 404／429）
embed_send_cors(embed_origins_allowed($cfg, $meta));

if ($method === 'OPTIONS') {
    http_response_code(204);
    header('Cache-Control: no-store');
    exit;
}
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD, OPTIONS');
    dataapi_fail(405, 'method not allowed');
}

$cfg['rate_limits']['dataapi'] = $cfg['rate_limits']['dataapi'] ?? ['max' => 120, 'window' => 60];
rate_limit($cfg, 'dataapi');

if (!preg_match('/^[a-z0-9_-]{1,40}$/D', $slug)) dataapi_fail(400, 'invalid project');
if ($meta === null) dataapi_fail(404, 'project not found');

/** 圖層清單裡的相對（本地）網址補成絕對網址；外部網址原樣。 */
function dataapi_abs_url(string $u): string
{
    if ($u === '' || strpos($u, '://') !== false || strpos($u, '//') === 0) return $u;
    return Route::abs($u);
}

/** layer.json 的 attribution 轉成 [{text,url}]；{osm_contributors} 這類佔位符補成英文。 */
function dataapi_attribution(mixed $list): array
{
    $out = [];
    foreach ((array)$list as $a) {
        if (!is_array($a) || empty($a['text'])) continue;
        $suffix = trim((string)preg_replace('/\{osm_contributors\}/', 'contributors', (string)($a['suffix'] ?? '')));
        $suffix = trim((string)preg_replace('/\{[a-z_]+\}/', '', $suffix));
        $row = ['text' => trim((string)$a['text'] . ($suffix !== '' ? ' ' . $suffix : ''))];
        if (!empty($a['url'])) $row['url'] = (string)$a['url'];
        $out[] = $row;
    }
    return $out;
}

function dataapi_layers(array $cfg, array $meta, string $slug): array
{
    $out = [];
    foreach (souliong_layers_public($cfg, $meta, $slug, Route::base()) as $l) {
        $isVector = ($l['type'] ?? '') === 'vector';
        $row = ['id' => (string)($l['id'] ?? ''), 'type' => $isVector ? 'vector-style' : 'raster',
            'label' => (string)($l['label'] ?? $l['id'] ?? ''), 'pane' => (string)($l['pane'] ?? 'art')];
        if ($isVector) {
            $row['styleUrl'] = dataapi_abs_url((string)($l['url'] ?? ''));
            if (!empty($l['urlDark'])) $row['styleUrlDark'] = dataapi_abs_url((string)$l['urlDark']);
        } else {
            $row['styleUrl'] = null;
            $row['tileUrl'] = dataapi_abs_url((string)($l['url'] ?? ''));
            if (!empty($l['urlDark'])) $row['tileUrlDark'] = dataapi_abs_url((string)$l['urlDark']);
            if (is_array($l['bounds'] ?? null)) $row['bounds'] = $l['bounds'];
        }
        $row['attribution'] = dataapi_attribution($l['attribution'] ?? []);
        $out[] = $row;
    }
    return $out;
}

/** 有效點位 → 對外白名單形狀；沒有合法座標的點位略過。 */
function dataapi_spots(array $cfg, string $slug): array
{
    $out = [];
    foreach (spot_effective_all($cfg, $slug) as $s) {
        $lat = $s['lat'] ?? null;
        $lon = $s['lon'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lon) || abs((float)$lat) > 90 || abs((float)$lon) > 180) continue;
        $lat = (float)$lat;
        $lon = (float)$lon;
        $title = souliong_og_spot_name($s);
        $nav = [];
        foreach (souliong_nav_apps() as $app) {
            $nav[$app['id']] = souliong_nav_url($app['url'], $lat, $lon, $title);
        }
        $out[] = [
            'spotId' => (string)$s['id'],
            'num'    => (int)$s['num'],
            'title'  => $title,
            'area'   => (string)($s['area'] ?? ''),
            'cat'    => (string)($s['cat'] ?? ''),
            'lat'    => $lat,
            'lon'    => $lon,
            'nav'    => $nav,
        ];
    }
    return $out;
}

/** 分類：key／label／color，依 meta.categoryOrder 優先，其餘依點位出現順序。 */
function dataapi_cats(array $cfg, array $meta, string $slug): array
{
    $seen = [];
    foreach (spot_effective_all($cfg, $slug) as $s) {
        $k = (string)($s['cat'] ?? '');
        if ($k === '' || isset($seen[$k])) continue;
        $seen[$k] = ['key' => $k, 'label' => spot_category_display_name($meta, $s), 'color' => souliong_spot_color($meta, $s)];
    }
    $out = [];
    foreach ((array)($meta['categoryOrder'] ?? []) as $k) {
        if (is_string($k) && isset($seen[$k])) {
            $out[] = $seen[$k];
            unset($seen[$k]);
        }
    }
    return array_merge($out, array_values($seen));
}

if ($kind === 'spots') {
    $spots = dataapi_spots($cfg, $slug);
    $body = ['v' => 1, 'project' => $slug, 'etag' => substr(sha1(json_encode($spots, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 16), 'spots' => $spots];
    $etag = $body['etag'];
} else {
    $center = $meta['center'] ?? null;
    $view = [
        'center'  => (is_array($center) && count($center) === 2 && is_numeric($center[0]) && is_numeric($center[1])) ? [(float)$center[0], (float)$center[1]] : null,
        'zoom'    => isset($meta['zoom']) && is_numeric($meta['zoom']) ? $meta['zoom'] + 0 : null,
        'minZoom' => isset($meta['minZoom']) && is_numeric($meta['minZoom']) ? $meta['minZoom'] + 0 : 0,
        'maxZoom' => isset($meta['maxZoom']) && is_numeric($meta['maxZoom']) ? $meta['maxZoom'] + 0 : 19,
    ];
    $mtime = max(
        (int)@filemtime(project_dir($cfg, $slug) . '/meta.json'),
        (int)@filemtime(store_file($cfg, $slug, 'spot'))
    );
    $body = [
        'v'         => 1,
        'id'        => $slug,
        'title'     => (string)($meta['title'] ?? $slug),
        'subtitle'  => (string)($meta['subtitle'] ?? ''),
        'view'      => $view,
        'layers'    => dataapi_layers($cfg, $meta, $slug),
        'cats'      => dataapi_cats($cfg, $meta, $slug),
        'updatedAt' => gmdate('Y-m-d\TH:i:s\Z', $mtime),
    ];
    $etag = substr(sha1(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 16);
}

$quoted = '"' . $etag . '"';
header('ETag: ' . $quoted);
header('Cache-Control: public, max-age=60');
$inm = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($inm !== '') {
    foreach (explode(',', $inm) as $cand) {
        $cand = trim($cand);
        if ($cand === '*' || preg_replace('#^W/#', '', $cand) === $quoted) {
            http_response_code(304);
            exit;
        }
    }
}
$json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Content-Length: ' . strlen($json));
if ($method !== 'HEAD') echo $json;
