<?php
// OSM 電力設施（power=tower／pole 節點、power=line／minor_line 線）的欄位規則。
// 共用的值驗證與資料集登記表在 api/osmdata.php。

/** 分號清單裡的最大數字（電壓、迴路數等清單取最大）；沒有合法數字或超過 $max 回 null */
function souliong_power_max(mixed $v, float $max): ?float
{
    $best = null;
    foreach (explode(';', (string)$v) as $part) {
        $part = str_replace(',', '.', trim($part));
        if (preg_match('/^\d+(?:\.\d+)?$/', $part) && (float)$part <= $max) {
            $best = max($best ?? 0.0, (float)$part);
        }
    }
    return $best;
}

/** 編號（ref）：字母、數字、空白與 ._/- ，最長 32 字；不合格回 null */
function souliong_power_ref(mixed $v): ?string
{
    $s = trim((string)$v);
    return preg_match('/^[\p{L}\p{N} ._\/-]{1,32}$/u', $s) ? $s : null;
}

/**
 * 一筆 OSM 標籤 → 前端用的屬性表。輸出欄位（值皆已驗證，缺的欄位不輸出）：
 *   id         "node/123"｜"way/456"
 *   kind       塔桿："tower"｜"pole"；線："line"｜"minor_line"
 *   voltage    伏特，清單取最大值（上限 2,000,000）
 *   circuits   迴路數                    cables  導線總數
 *   wires      單一相的導線數："single"｜"double"｜"triple"｜"quad" 等（僅線）
 *   height     公尺（僅塔桿，上限 300）
 *   structure  lattice｜tubular｜concrete｜wood 等（僅塔桿）
 *   design     one-level｜two-level｜three-level｜portal｜delta｜donau 等，未知值原樣保留（僅塔桿）
 *   material   steel｜concrete｜wood 等（僅塔桿）
 *   colour     #rrggbb（僅塔桿）
 *   ref        編號（僅塔桿）
 */
function souliong_power_props(array $tags, string $id, string $kind): array
{
    $volt = souliong_power_max($tags['voltage'] ?? '', 2000000);
    $circ = souliong_power_max($tags['circuits'] ?? '', 100);
    $cabl = souliong_power_max($tags['cables'] ?? '', 200);
    $p = [
        'id'       => $id,
        'kind'     => $kind,
        'voltage'  => $volt !== null ? (int)$volt : null,
        'circuits' => $circ !== null ? (int)$circ : null,
        'cables'   => $cabl !== null ? (int)$cabl : null,
    ];
    if ($kind === 'line' || $kind === 'minor_line') {
        $p['wires'] = souliong_osm_word($tags['wires'] ?? '');
    } else {
        $p += [
            'height'    => souliong_osm_meters($tags['height'] ?? '', 300.0),
            'structure' => souliong_osm_word($tags['structure'] ?? ''),
            'design'    => souliong_osm_word($tags['design'] ?? ''),
            'material'  => souliong_osm_word($tags['material'] ?? ''),
            'colour'    => souliong_osm_color($tags['colour'] ?? ''),
            'ref'       => souliong_power_ref($tags['ref'] ?? ''),
        ];
    }
    return array_filter($p, fn($x) => $x !== null);
}

/** Overpass 元素 → GeoJSON Feature；塔桿為 Point，線為 LineString，地下／水下線路與其餘回 null */
function souliong_power_feature(array $el): ?array
{
    $type = (string)($el['type'] ?? '');
    $tags = (array)($el['tags'] ?? []);
    $power = (string)($tags['power'] ?? '');
    if (!isset($el['id'])) return null;
    $id = $type . '/' . (int)$el['id'];
    if ($type === 'node' && in_array($power, ['tower', 'pole'], true) && isset($el['lat'], $el['lon'])) {
        $geom = ['type' => 'Point', 'coordinates' => [round((float)$el['lon'], 7), round((float)$el['lat'], 7)]];
        return ['type' => 'Feature', 'properties' => souliong_power_props($tags, $id, $power), 'geometry' => $geom];
    }
    if ($type === 'way' && in_array($power, ['line', 'minor_line'], true)
        && !in_array($tags['location'] ?? '', ['underground', 'underwater'], true)) {
        $line = souliong_osm_line((array)($el['geometry'] ?? []));
        return count($line) < 2 ? null
            : ['type' => 'Feature', 'properties' => souliong_power_props($tags, $id, $power), 'geometry' => ['type' => 'LineString', 'coordinates' => $line]];
    }
    return null;
}
