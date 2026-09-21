<?php
// OSM 樹木（natural=tree 節點、natural=tree_row 線）的欄位規則。
// 共用的值驗證與資料集登記表在 api/osmdata.php。

/** 學名（genus／species）：拉丁字母、空白、連字號、點、乘號，其餘 null */
function souliong_trees_taxon(mixed $v): ?string
{
    $s = trim(explode(';', (string)$v)[0]);
    return preg_match('/^[A-Za-z][A-Za-z .×x-]{0,62}$/u', $s) ? $s : null;
}

/**
 * 一筆 OSM 標籤 → 前端用的屬性表。輸出欄位（值皆已驗證，缺的欄位不輸出）：
 *   id         "node/123"｜"way/456"
 *   kind       "tree"（單株，Point）｜"row"（行道樹，LineString，沿線的間距由前端決定）
 *   height     公尺（上限 150）
 *   trunk      樹幹直徑，公尺：circumference（周長，公尺）除以 π 估得，上限 30 公尺周長；沒有周長時取 diameter（上限 10 公尺）
 *   crown      樹冠直徑，公尺（diameter_crown，上限 100）
 *   leafType   broadleaved｜needleleaved 等 leaf_type 詞彙
 *   leafCycle  deciduous｜evergreen｜semi_deciduous 等 leaf_cycle 詞彙
 *   genus／species  拉丁學名
 */
function souliong_trees_props(array $tags, string $id, string $kind): array
{
    $circ = souliong_osm_meters($tags['circumference'] ?? '', 30.0);
    $diam = souliong_osm_meters($tags['diameter'] ?? '', 10.0);
    $p = [
        'id'        => $id,
        'kind'      => $kind,
        'height'    => souliong_osm_meters($tags['height'] ?? '', 150.0),
        'trunk'     => $circ !== null ? round($circ / M_PI, 2) : $diam,
        'crown'     => souliong_osm_meters($tags['diameter_crown'] ?? '', 100.0),
        'leafType'  => souliong_osm_word($tags['leaf_type'] ?? ''),
        'leafCycle' => souliong_osm_word($tags['leaf_cycle'] ?? ''),
        'genus'     => souliong_trees_taxon($tags['genus'] ?? ''),
        'species'   => souliong_trees_taxon($tags['species'] ?? ''),
    ];
    return array_filter($p, fn($x) => $x !== null);
}

/** Overpass 元素 → GeoJSON Feature；node＝natural=tree，way＝natural=tree_row，其餘回 null */
function souliong_trees_feature(array $el): ?array
{
    $type = (string)($el['type'] ?? '');
    $tags = (array)($el['tags'] ?? []);
    if (!isset($el['id'])) return null;
    $id = $type . '/' . (int)$el['id'];
    if ($type === 'node' && ($tags['natural'] ?? '') === 'tree' && isset($el['lat'], $el['lon'])) {
        $geom = ['type' => 'Point', 'coordinates' => [round((float)$el['lon'], 7), round((float)$el['lat'], 7)]];
        return ['type' => 'Feature', 'properties' => souliong_trees_props($tags, $id, 'tree'), 'geometry' => $geom];
    }
    if ($type === 'way' && ($tags['natural'] ?? '') === 'tree_row') {
        $line = souliong_osm_line((array)($el['geometry'] ?? []));
        return count($line) < 2 ? null
            : ['type' => 'Feature', 'properties' => souliong_trees_props($tags, $id, 'row'), 'geometry' => ['type' => 'LineString', 'coordinates' => $line]];
    }
    return null;
}
