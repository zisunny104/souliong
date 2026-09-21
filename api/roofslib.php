<?php
// OSM 屋頂造型（Simple 3D Buildings 的 roof:shape 一族）的欄位規則與幾何處理。
// 共用的值驗證（顏色、長度、數字、詞彙）與資料集登記表在 api/osmdata.php。

/** 屋頂方向：0–360 的度數，或 N／NNE…／north 等羅盤方位（十六方位）；其餘 null。度數 360 折成 0 */
function souliong_roofs_direction(mixed $v): ?float
{
    $s = strtolower(trim((string)$v));
    if ($s === '') return null;
    if (preg_match('/^\d+(?:\.\d+)?$/', $s)) {
        $d = (float)$s;
        return $d <= 360 ? fmod($d, 360) : null;
    }
    $pts = ['n', 'nne', 'ne', 'ene', 'e', 'ese', 'se', 'sse', 's', 'ssw', 'sw', 'wsw', 'w', 'wnw', 'nw', 'nnw'];
    $names = ['north' => 'n', 'east' => 'e', 'south' => 's', 'west' => 'w',
        'northeast' => 'ne', 'southeast' => 'se', 'southwest' => 'sw', 'northwest' => 'nw'];
    $k = array_search($names[$s] ?? $s, $pts, true);
    return $k === false ? null : $k * 22.5;
}

/**
 * 一筆 OSM 標籤 → 前端用的屬性表；不是有造型的建物就回 null。
 * 輸出欄位（值皆已驗證，缺的欄位不輸出）：
 *   id             "way/123"｜"relation/456"
 *   kind           "building"｜"part"（building:part）
 *   shape          roof:shape（小寫，如 gabled、hipped、dome）
 *   levels         building:levels                 minLevel   building:min_level
 *   height         公尺（含屋頂總高）              minHeight  公尺（離地起算高度）
 *   roofHeight     公尺                            roofLevels roof:levels
 *   roofAngle      度                              roofDirection 度（0＝北，順時針；斜面朝下的方向）
 *   roofOrientation "along"｜"across"
 *   roofColour／buildingColour／colour   #rrggbb
 *   roofMaterial／buildingMaterial       小寫英數底線
 */
function souliong_roofs_props(array $tags, string $id, bool $anyShape = false): ?array
{
    $shape = souliong_osm_word($tags['roof:shape'] ?? '', 24);
    if ($shape === 'no') $shape = null;
    $part = isset($tags['building:part']) && $tags['building:part'] !== 'no';
    if (($shape === null && !$anyShape) || (!$part && empty($tags['building']))) {
        return null;
    }
    $orient = strtolower(trim((string)($tags['roof:orientation'] ?? '')));
    $angle = souliong_osm_number($tags['roof:angle'] ?? '');
    $p = [
        'id'              => $id,
        'kind'            => $part ? 'part' : 'building',
        'shape'           => $shape,
        'levels'          => souliong_osm_number($tags['building:levels'] ?? ''),
        'minLevel'        => souliong_osm_number($tags['building:min_level'] ?? ''),
        'height'          => souliong_osm_meters($tags['height'] ?? ''),
        'minHeight'       => souliong_osm_meters($tags['min_height'] ?? ''),
        'roofHeight'      => souliong_osm_meters($tags['roof:height'] ?? ''),
        'roofLevels'      => souliong_osm_number($tags['roof:levels'] ?? ''),
        'roofAngle'       => ($angle !== null && $angle <= 90) ? $angle : null,
        'roofDirection'   => souliong_roofs_direction($tags['roof:direction'] ?? $tags['roof:slope:direction'] ?? ''),
        'roofOrientation' => in_array($orient, ['along', 'across'], true) ? $orient : null,
        'roofColour'      => souliong_osm_color($tags['roof:colour'] ?? ''),
        'buildingColour'  => souliong_osm_color($tags['building:colour'] ?? ''),
        'colour'          => souliong_osm_color($tags['colour'] ?? ''),
        'roofMaterial'    => souliong_osm_word($tags['roof:material'] ?? ''),
        'buildingMaterial' => souliong_osm_word($tags['building:material'] ?? ''),
    ];
    return array_filter($p, fn($x) => $x !== null);
}

/** 標籤有沒有值得畫的外觀：屋頂造型、樓層數，或外牆／屋頂的顏色與材質（都沒有的建築維持公用圖磚的預設外觀） */
function souliong_roofs_styled(array $tags): bool
{
    $shape = souliong_osm_word($tags['roof:shape'] ?? '', 24);
    if ($shape !== null && $shape !== 'no') return true;
    if (souliong_osm_number($tags['building:levels'] ?? '') !== null) return true;
    foreach (['building:colour', 'colour', 'roof:colour'] as $k) {
        if (souliong_osm_color($tags[$k] ?? '') !== null) return true;
    }
    foreach (['building:material', 'roof:material'] as $k) {
        if (souliong_osm_word($tags[$k] ?? '') !== null) return true;
    }
    return false;
}

function souliong_roofs_closed(array $ring): bool
{
    return count($ring) >= 4 && $ring[0] === $ring[count($ring) - 1];
}

/** 環的有號面積（正＝逆時針） */
function souliong_roofs_area(array $ring): float
{
    $a = 0.0;
    for ($i = 0, $n = count($ring) - 1; $i < $n; $i++) {
        $a += $ring[$i][0] * $ring[$i + 1][1] - $ring[$i + 1][0] * $ring[$i][1];
    }
    return $a / 2;
}

/** 依 RFC 7946 定向：外環逆時針、內環順時針 */
function souliong_roofs_orient(array $ring, bool $outer): array
{
    return (souliong_roofs_area($ring) > 0) === $outer ? $ring : array_reverse($ring);
}

function souliong_roofs_in_ring(array $pt, array $ring): bool
{
    $in = false;
    for ($i = 0, $j = count($ring) - 1; $i < count($ring); $j = $i++) {
        [$xi, $yi] = $ring[$i];
        [$xj, $yj] = $ring[$j];
        if (($yi > $pt[1]) !== ($yj > $pt[1]) && $pt[0] < ($xj - $xi) * ($pt[1] - $yi) / ($yj - $yi) + $xi) {
            $in = !$in;
        }
    }
    return $in;
}

/** 把一批線段（多條 way 的座標）接成閉合環；接不起來的丟棄 */
function souliong_roofs_stitch(array $lines): array
{
    $rings = [];
    while ($lines) {
        $cur = array_shift($lines);
        while (!souliong_roofs_closed($cur)) {
            $joined = false;
            foreach ($lines as $k => $ln) {
                if ($ln[0] === $cur[count($cur) - 1]) {
                    $cur = array_merge($cur, array_slice($ln, 1));
                } elseif ($ln[count($ln) - 1] === $cur[count($cur) - 1]) {
                    $cur = array_merge($cur, array_slice(array_reverse($ln), 1));
                } else {
                    continue;
                }
                unset($lines[$k]);
                $joined = true;
                break;
            }
            if (!$joined) break;
        }
        if (souliong_roofs_closed($cur)) $rings[] = $cur;
    }
    return $rings;
}

/** way／relation 元素 → GeoJSON geometry；不是面（未閉合、拼不成環）回 null */
function souliong_roofs_geometry(array $el): ?array
{
    if (($el['type'] ?? '') === 'way') {
        $ring = souliong_osm_line((array)($el['geometry'] ?? []));
        return souliong_roofs_closed($ring)
            ? ['type' => 'Polygon', 'coordinates' => [souliong_roofs_orient($ring, true)]]
            : null;
    }
    if (($el['type'] ?? '') !== 'relation' || ($el['tags']['type'] ?? '') !== 'multipolygon') {
        return null;
    }
    $outer = $inner = [];
    foreach ((array)($el['members'] ?? []) as $mem) {
        if (($mem['type'] ?? '') !== 'way') continue;
        $line = souliong_osm_line((array)($mem['geometry'] ?? []));
        if (count($line) < 2) continue;
        if (($mem['role'] ?? '') === 'inner') $inner[] = $line; else $outer[] = $line;
    }
    $outers = array_map(fn($r) => souliong_roofs_orient($r, true), souliong_roofs_stitch($outer));
    $inners = array_map(fn($r) => souliong_roofs_orient($r, false), souliong_roofs_stitch($inner));
    if (!$outers) return null;
    $polys = array_map(fn($o) => [$o], $outers);
    foreach ($inners as $hole) {
        foreach ($outers as $k => $o) {
            if (souliong_roofs_in_ring($hole[0], $o)) {
                $polys[$k][] = $hole;
                break;
            }
        }
    }
    return count($polys) === 1
        ? ['type' => 'Polygon', 'coordinates' => $polys[0]]
        : ['type' => 'MultiPolygon', 'coordinates' => $polys];
}

/** Overpass 元素 → GeoJSON Feature；沒有可用屬性或幾何回 null */
function souliong_roofs_feature(array $el): ?array
{
    $type = (string)($el['type'] ?? '');
    if (!isset($el['id']) || !in_array($type, ['way', 'relation'], true)) return null;
    $props = souliong_roofs_props((array)($el['tags'] ?? []), $type . '/' . (int)$el['id']);
    $geom = $props === null ? null : souliong_roofs_geometry($el);
    return $geom === null ? null : ['type' => 'Feature', 'properties' => $props, 'geometry' => $geom];
}

/** 幾何的外環（Polygon 取第一環；MultiPolygon 每個多邊形各一環） */
function souliong_roofs_outers(array $geom): array
{
    return $geom['type'] === 'Polygon' ? [$geom['coordinates'][0]] : array_map(fn($p) => $p[0], $geom['coordinates']);
}

/**
 * 全部 Overpass 元素（建築與 building:part）→ Feature 清單。
 * 只要一棟建築（主體加上落在它輪廓內的分件）有任何一個成員有屋頂造型、樓層數或外牆／屋頂的顏色與材質，
 * 就把這一棟的主體與全部分件都輸出：前端是整棟用 id 蓋掉公用圖磚的擠出，缺了分件的那一塊會憑空消失。
 * 沒有任何外觀標籤的整棟不輸出。分件歸屬看分件的代表點落在哪個建築輪廓內（多個時取面積最小者）。
 */
function souliong_roofs_collect(array $elements): array
{
    $items = [];   // id => [props, geom, styled]
    foreach ($elements as $el) {
        if (!is_array($el) || !isset($el['id']) || !in_array($el['type'] ?? '', ['way', 'relation'], true)) continue;
        $tags = (array)($el['tags'] ?? []);
        $id = $el['type'] . '/' . (int)$el['id'];
        $props = souliong_roofs_props($tags, $id, true);
        $geom = $props === null ? null : souliong_roofs_geometry($el);
        if ($geom === null) continue;
        $items[$id] = ['props' => $props, 'geom' => $geom, 'styled' => souliong_roofs_styled($tags)];
    }

    // 主體的外環放進 0.001 度的格子索引，分件只和同格的主體比對
    $cell = fn(float $x, float $y): string => floor($x * 1000) . ',' . floor($y * 1000);
    $grid = $area = [];
    foreach ($items as $id => $it) {
        if ($it['props']['kind'] !== 'building') continue;
        foreach (souliong_roofs_outers($it['geom']) as $ring) {
            $xs = array_column($ring, 0);
            $ys = array_column($ring, 1);
            $area[$id] = ($area[$id] ?? 0) + abs(souliong_roofs_area($ring));
            for ($cx = floor(min($xs) * 1000); $cx <= floor(max($xs) * 1000); $cx++) {
                for ($cy = floor(min($ys) * 1000); $cy <= floor(max($ys) * 1000); $cy++) {
                    $grid["$cx,$cy"][$id][] = $ring;
                }
            }
        }
    }

    $group = [];   // 主體 id（找不到主體的分件用自己的 id）=> 成員 id 清單
    foreach ($items as $id => $it) {
        if ($it['props']['kind'] === 'building') {
            $group[$id][] = $id;
            continue;
        }
        $ring = souliong_roofs_outers($it['geom'])[0];
        $n = count($ring) - 1;
        $body = array_slice($ring, 0, $n);
        $mean = [array_sum(array_column($body, 0)) / $n, array_sum(array_column($body, 1)) / $n];
        $home = null;
        foreach ([$mean, $ring[0]] as $pt) {
            foreach ($grid[$cell($pt[0], $pt[1])] ?? [] as $bid => $rings) {
                foreach ($rings as $r) {
                    if (souliong_roofs_in_ring($pt, $r) && ($home === null || $area[$bid] < $area[$home])) $home = $bid;
                }
            }
            if ($home !== null) break;
        }
        $group[$home ?? $id][] = $id;
    }

    $out = [];
    foreach ($group as $members) {
        if (!array_filter($members, fn($m) => $items[$m]['styled'])) continue;
        foreach ($members as $m) {
            $out[] = ['type' => 'Feature', 'properties' => $items[$m]['props'], 'geometry' => $items[$m]['geom']];
        }
    }
    return $out;
}
