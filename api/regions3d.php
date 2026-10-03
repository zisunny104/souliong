<?php
// 自訂 3D 模型區域註冊表：projects/<proj>/regions3d/<id>/。跟圖層系統（api/layers.php）同樣是
// 「資料夾本身就是登記表，沒有中央 index」的精神，差別是這裡只有專案作用域——自訂模型天生是
// 某個地標的一次性創作，不像底圖有跨專案共用的價值，所以不設全站層。
//
// 每個區域資料夾：
//   region.json  公開 manifest：
//                  { id, label,
//                    polygon: <GeoJSON Polygon，經緯度>,
//                    excludedBuildingIds: [...]，   -- 存檔當下算好的排除清單，見下方說明
//                    model: { anchor:[lat,lon], rotationDeg, scale, altitudeOffset },
//                    attribution }
//   model.glb    模型本體，由 api/model3dfile.php 輸出（框架不供應靜態檔，理由同 api/photo.php）
//
// ── 排除機制 ──
// excludedBuildingIds 是管理者在 api/region3d.php 存檔當下、對照公用建物圖磚 queryRenderedFeatures
// 算出來的靜態清單，不是訪客端即時重算（原因見 api/region3d.php 開頭的說明：即時重算會有「還沒
// 平移過去之前公用建物照樣畫出來」的時序問題）。assets/js/plugins/map3d.js 把所有已存區域的
// 這份清單攤平聯集，套成 building 圖層一條固定的 filter，圖層建立當下就生效，不管訪客怎麼平移。
function souliong_regions3d_root(array $cfg, string $proj): ?string
{
    $pdir = rtrim((string)($cfg['projects_dir'] ?? ''), "/\\");
    if ($pdir === '' || !preg_match('/^[a-z0-9_-]+$/', $proj)) {
        return null;
    }
    return $pdir . '/' . $proj . '/regions3d';
}

/** 單一區域的資料夾絕對路徑；project/id 不合法回 null（不檢查資料夾是否存在）。 */
function souliong_region3d_dir(array $cfg, string $proj, string $id): ?string
{
    $root = souliong_regions3d_root($cfg, $proj);
    if ($root === null || !preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $id)) {
        return null;
    }
    return $root . '/' . $id;
}

/** 掃專案底下所有區域，回傳 [ id => region.json 解析後的陣列 ]；manifest 補上 id（資料夾名稱才是真的）。 */
function souliong_region3d_list(array $cfg, string $proj): array
{
    $out = [];
    $root = souliong_regions3d_root($cfg, $proj);
    if ($root === null || !is_dir($root)) {
        return $out;
    }
    foreach (scandir($root) ?: [] as $id) {
        if ($id === '.' || $id === '..' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $id)) {
            continue;
        }
        $mf = $root . '/' . $id . '/region.json';
        if (!is_file($mf)) {
            continue;
        }
        $manifest = json_decode((string)@file_get_contents($mf), true);
        if (!is_array($manifest)) {
            continue;
        }
        $manifest['id'] = $id;
        $out[$id] = $manifest;
    }
    return $out;
}

/**
 * 前端用的 manifest：模型固定檔名改寫成 <base>/model3d/<project>/<id>/model.glb 絕對網址。
 * 跟 souliong_layer_public() 同樣的用意——前端不必知道模型放在哪個資料夾結構裡，region.json
 * 本身不存 url（一律是同資料夾的 model.glb，理由同 layer.json 系列刻意固定檔名的地方）。
 */
function souliong_region3d_public(array $manifest, string $base, string $proj): array
{
    $id = (string)($manifest['id'] ?? '');
    if ($id !== '') {
        $manifest['modelUrl'] = $base . 'model3d/' . rawurlencode($proj) . '/' . rawurlencode($id) . '/model.glb';
    }
    return $manifest;
}

/** souliong_region3d_list() 的前端版本：額外套用 souliong_region3d_public()。 */
function souliong_region3d_public_list(array $cfg, string $proj, string $base): array
{
    return array_values(array_map(
        fn(array $m): array => souliong_region3d_public($m, $base, $proj),
        souliong_region3d_list($cfg, $proj)
    ));
}

/**
 * 所有已存區域的排除建物 id，攤平成單一陣列聯集——這是 map3d.js 要套在 building 圖層上那條
 * 靜態 filter 的來源。攤平在伺服器端做，前端不必知道「目前有幾個 region」。
 */
function souliong_region3d_excluded_ids(array $cfg, string $proj): array
{
    $out = [];
    foreach (souliong_region3d_list($cfg, $proj) as $m) {
        foreach ((array)($m['excludedBuildingIds'] ?? []) as $bid) {
            if (is_int($bid) || is_string($bid)) {
                $out[] = $bid;
            }
        }
    }
    return array_values(array_unique($out, SORT_REGULAR));
}
