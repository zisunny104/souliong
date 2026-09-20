<?php
/**
 * 點位導航的外部連結模板 —— 全站唯一一份。跟 routes.php 同精神：外部網址不散落在前端或各頁面，
 * 前端只拿 APP.nav 的清單套值，不自己拼。
 *
 * 模板佔位符：{lat}、{lon}（十進位度數，原樣填入）、{name}（點位名稱，已 URL 編碼；沒有名稱時
 * 呼叫端改填 "lat,lon"）。platform 是顯示條件：all＝所有裝置，ios＝只在 iOS 裝置顯示
 * （Apple 地圖在其他平台開不起來）。
 *
 * 各家格式（皆為官方文件或站方原始碼認可的寫法，改動前請重新查證）：
 *   Google Maps   Maps URLs：/maps/dir/?api=1&destination=lat,lon
 *   Apple 地圖    Map Links：daddr=lat,lon 為目的地、dirflg=d 開車、q 為標籤
 *   geo:          RFC 5870 加 q 標籤（OsmAnd、Organic Maps 等註冊了 geo: 的 App 會接手）
 *   OSM 網頁      directions 頁吃 route=<起點>;<終點>，起點留空即「從目前位置／讓使用者填」
 */
function souliong_nav_apps(): array
{
    return [
        ['id' => 'google', 'lang' => 'nav_app_google', 'url' => 'https://www.google.com/maps/dir/?api=1&destination={lat},{lon}', 'platform' => 'all'],
        ['id' => 'apple',  'lang' => 'nav_app_apple',  'url' => 'https://maps.apple.com/?daddr={lat},{lon}&dirflg=d&q={name}', 'platform' => 'ios'],
        ['id' => 'geo',    'lang' => 'nav_app_geo',    'url' => 'geo:{lat},{lon}?q={lat},{lon}({name})', 'platform' => 'all'],
        ['id' => 'osm',    'lang' => 'nav_app_osm',    'url' => 'https://www.openstreetmap.org/directions?route=;{lat},{lon}', 'platform' => 'all'],
    ];
}

/** 依模板產生連結；座標非有限數字回 null（無座標的點位不該有導航）。 */
function souliong_nav_link(string $id, $lat, $lon, string $name = ''): ?string
{
    if (!is_numeric($lat) || !is_numeric($lon) || abs((float)$lat) > 90 || abs((float)$lon) > 180) return null;
    foreach (souliong_nav_apps() as $app) {
        if ($app['id'] !== $id) continue;
        $ll = (float)$lat . ',' . (float)$lon;
        return strtr($app['url'], [
            '{lat}'  => (string)(float)$lat,
            '{lon}'  => (string)(float)$lon,
            '{name}' => rawurlencode($name !== '' ? $name : $ll),
        ]);
    }
    return null;
}
