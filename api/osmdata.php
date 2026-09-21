<?php
// 預先抓取的 OSM 資料集（屋頂造型、樹木）的共用部分：登記表、專案檔案路徑、標籤值的驗證與正規化。
// 各資料集自己的欄位規則在 api/roofslib.php、api/treeslib.php、api/powerlib.php；tools/osm_fetch.php 負責抓取並寫檔，
// api/osmfile.php 只負責把存好的檔案送出去。純函式、無副作用。
//
// 為什麼預先抓取存進專案：公開 Overpass 有配額與逾時，訪客端即時查詢會拖慢頁面、也會把流量丟給
// 一個公益服務。資料由管理者在維護時抓一次，落地成 projects/<p>/<檔名>.geojson，之後跟其他專案資料同一條路走。

require_once __DIR__ . '/roofslib.php';
require_once __DIR__ . '/treeslib.php';
require_once __DIR__ . '/powerlib.php';

/**
 * 資料集登記表。file 是專案資料夾內的檔名；query 是 Overpass 選取式（不含 bbox 與輸出敘述）；
 * feature 把一個 Overpass 元素轉成 GeoJSON Feature（不可用回 null）；需要跨元素判斷的資料集改給 collect（全部元素 → Feature 清單）；max 是筆數上限，
 * 超過代表範圍太大，抓取工具寧可中止也不寫出一份被截斷的資料。
 */
function souliong_osm_kinds(): array
{
    return [
        'roofs' => [
            'file'    => 'roofs.geojson',
            'query'   => 'way["building"]; way["building:part"]; relation["building"]["type"="multipolygon"];',
            'collect' => 'souliong_roofs_collect',
            'max'     => 60000,
        ],
        'trees' => [
            'file'    => 'trees.geojson',
            'query'   => 'node["natural"="tree"]; way["natural"="tree_row"];',
            'feature' => 'souliong_trees_feature',
            'max'     => 50000,
        ],
        'power' => [
            'file'    => 'power.geojson',
            'query'   => 'node["power"="tower"]; node["power"="pole"]; way["power"="line"]; way["power"="minor_line"];',
            'feature' => 'souliong_power_feature',
            'max'     => 30000,
        ],
    ];
}

/** projects/<proj>/<資料集檔>的絕對路徑；project 或資料集不合法回 null（不檢查檔案是否存在） */
function souliong_osm_path(array $cfg, string $proj, string $kind): ?string
{
    $file = souliong_osm_kinds()[$kind]['file'] ?? null;
    $pdir = rtrim((string)($cfg['projects_dir'] ?? ''), "/\\");
    if ($file === null || $pdir === '' || !preg_match('/^[a-z0-9_-]+$/', $proj)) {
        return null;
    }
    return $pdir . '/' . $proj . '/' . $file;
}

/** 空的 FeatureCollection（檔案不存在時端點的回應，前端不必處理 404） */
function souliong_osm_empty(): array
{
    return ['type' => 'FeatureCollection', 'features' => []];
}

/** CSS 顏色名稱（OSM colour 值允許的名稱集合）→ 十六進位，鍵一律小寫無空白 */
function souliong_osm_css_colors(): array
{
    static $c = null;
    if ($c !== null) return $c;
    $raw = 'aliceblue f0f8ff antiquewhite faebd7 aqua 00ffff aquamarine 7fffd4 azure f0ffff beige f5f5dc bisque ffe4c4 black 000000 '
        . 'blanchedalmond ffebcd blue 0000ff blueviolet 8a2be2 brown a52a2a burlywood deb887 cadetblue 5f9ea0 chartreuse 7fff00 '
        . 'chocolate d2691e coral ff7f50 cornflowerblue 6495ed cornsilk fff8dc crimson dc143c cyan 00ffff darkblue 00008b '
        . 'darkcyan 008b8b darkgoldenrod b8860b darkgray a9a9a9 darkgreen 006400 darkgrey a9a9a9 darkkhaki bdb76b darkmagenta 8b008b '
        . 'darkolivegreen 556b2f darkorange ff8c00 darkorchid 9932cc darkred 8b0000 darksalmon e9967a darkseagreen 8fbc8f '
        . 'darkslateblue 483d8b darkslategray 2f4f4f darkslategrey 2f4f4f darkturquoise 00ced1 darkviolet 9400d3 deeppink ff1493 '
        . 'deepskyblue 00bfff dimgray 696969 dimgrey 696969 dodgerblue 1e90ff firebrick b22222 floralwhite fffaf0 forestgreen 228b22 '
        . 'fuchsia ff00ff gainsboro dcdcdc ghostwhite f8f8ff gold ffd700 goldenrod daa520 gray 808080 green 008000 greenyellow adff2f '
        . 'grey 808080 honeydew f0fff0 hotpink ff69b4 indianred cd5c5c indigo 4b0082 ivory fffff0 khaki f0e68c lavender e6e6fa '
        . 'lavenderblush fff0f5 lawngreen 7cfc00 lemonchiffon fffacd lightblue add8e6 lightcoral f08080 lightcyan e0ffff '
        . 'lightgoldenrodyellow fafad2 lightgray d3d3d3 lightgreen 90ee90 lightgrey d3d3d3 lightpink ffb6c1 lightsalmon ffa07a '
        . 'lightseagreen 20b2aa lightskyblue 87cefa lightslategray 778899 lightslategrey 778899 lightsteelblue b0c4de lightyellow ffffe0 '
        . 'lime 00ff00 limegreen 32cd32 linen faf0e6 magenta ff00ff maroon 800000 mediumaquamarine 66cdaa mediumblue 0000cd '
        . 'mediumorchid ba55d3 mediumpurple 9370db mediumseagreen 3cb371 mediumslateblue 7b68ee mediumspringgreen 00fa9a '
        . 'mediumturquoise 48d1cc mediumvioletred c71585 midnightblue 191970 mintcream f5fffa mistyrose ffe4e1 moccasin ffe4b5 '
        . 'navajowhite ffdead navy 000080 oldlace fdf5e6 olive 808000 olivedrab 6b8e23 orange ffa500 orangered ff4500 orchid da70d6 '
        . 'palegoldenrod eee8aa palegreen 98fb98 paleturquoise afeeee palevioletred db7093 papayawhip ffefd5 peachpuff ffdab9 peru cd853f '
        . 'pink ffc0cb plum dda0dd powderblue b0e0e6 purple 800080 red ff0000 rosybrown bc8f8f royalblue 4169e1 saddlebrown 8b4513 '
        . 'salmon fa8072 sandybrown f4a460 seagreen 2e8b57 seashell fff5ee sienna a0522d silver c0c0c0 skyblue 87ceeb slateblue 6a5acd '
        . 'slategray 708090 slategrey 708090 snow fffafa springgreen 00ff7f steelblue 4682b4 tan d2b48c teal 008080 thistle d8bfd8 '
        . 'tomato ff6347 turquoise 40e0d0 violet ee82ee wheat f5deb3 white ffffff whitesmoke f5f5f5 yellow ffff00 yellowgreen 9acd32';
    $t = explode(' ', $raw);
    $c = [];
    for ($i = 0; $i + 1 < count($t); $i += 2) {
        $c[$t[$i]] = '#' . $t[$i + 1];
    }
    return $c;
}

/** OSM 顏色值（CSS 名稱、#rgb、#rrggbb）→ 小寫 #rrggbb；解析不了回 null */
function souliong_osm_color(mixed $v): ?string
{
    $s = strtolower(preg_replace('/\s+/', '', (string)$v));
    if ($s === '') return null;
    if (preg_match('/^#([0-9a-f]{6})$/', $s, $m)) return '#' . $m[1];
    if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $s, $m)) {
        return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
    }
    return souliong_osm_css_colors()[$s] ?? null;
}

/**
 * OSM 長度值 → 公尺（兩位小數）。接受 "12"、"12.5 m"、"12,5"、"1200 cm"、"40 ft"、"12'6\""；
 * 負值、非數字、超過 $max（預設 1000）公尺都回 null（多半是輸入錯誤，寧可不畫）。
 */
function souliong_osm_meters(mixed $v, float $max = 1000.0): ?float
{
    $s = strtolower(trim((string)$v));
    $n = '(\d+(?:[.,]\d+)?)';
    $f = fn(string $x): float => (float)str_replace(',', '.', $x);
    if (preg_match("/^{$n}\s*'\s*(?:{$n}\s*\"?)?$/", $s, $m)) {
        $m = $f($m[1]) * 0.3048 + (isset($m[2]) ? $f($m[2]) * 0.0254 : 0.0);
    } elseif (preg_match("/^{$n}\s*(m|meters?|metres?|ft|feet|cm|km)?$/", $s, $mm)) {
        $unit = $mm[2] ?? 'm';
        $mult = ['ft' => 0.3048, 'feet' => 0.3048, 'cm' => 0.01, 'km' => 1000.0][$unit] ?? 1.0;
        $m = $f($mm[1]) * $mult;
    } else {
        return null;
    }
    return ($m >= 0 && $m <= $max) ? round($m, 2) : null;
}

/** 非負十進位數字（層數、角度等，接受逗號小數）；超過 $max 或格式不符回 null */
function souliong_osm_number(mixed $v, float $max = 300.0): ?float
{
    $s = str_replace(',', '.', trim((string)$v));
    if (!preg_match('/^\d+(?:\.\d+)?$/', $s)) return null;
    $n = (float)$s;
    return $n <= $max ? $n : null;
}

/** 材質／造型這類受控詞彙（含 half-hipped、one-level 這種連字號寫法）：取分號清單的第一個，只留小寫英數、底線與連字號 */
function souliong_osm_word(mixed $v, int $max = 32): ?string
{
    $s = strtolower(trim(explode(';', (string)$v)[0]));
    return preg_match('/^[a-z0-9_-]+$/', $s) && strlen($s) <= $max ? $s : null;
}

/** Overpass 的 geometry（[{lat,lon}…]）→ GeoJSON 座標 [[lon,lat]…]，七位小數 */
function souliong_osm_line(array $geom): array
{
    $out = [];
    foreach ($geom as $pt) {
        if (is_array($pt) && isset($pt['lat'], $pt['lon'])) {
            $out[] = [round((float)$pt['lon'], 7), round((float)$pt['lat'], 7)];
        }
    }
    return $out;
}
