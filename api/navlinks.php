<?php
/**
 * 點位導航的外部連結模板 —— 全站唯一一份。跟 routes.php 同精神：外部網址不散落在前端或各頁面，
 * 前端只拿 APP.nav 的清單套值，不自己拼。
 *
 * 模板佔位符：{lat}、{lon}（十進位度數，原樣填入）、{name}（點位名稱，已 URL 編碼；沒有名稱時
 * 呼叫端改填 "lat,lon"）。platform 是顯示條件：all＝所有裝置，ios＝只在 iOS 裝置顯示
 * （Apple 地圖在其他平台開不起來）。icon 是 Font Awesome 6（pages/view.php 載入 all.min.css，含 brands）的 class。
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
        ['id' => 'google', 'icon' => 'fa-brands fa-google', 'lang' => 'nav_app_google', 'url' => 'https://www.google.com/maps/dir/?api=1&destination={lat},{lon}', 'platform' => 'all'],
        ['id' => 'apple', 'icon' => 'fa-brands fa-apple',  'lang' => 'nav_app_apple',  'url' => 'https://maps.apple.com/?daddr={lat},{lon}&dirflg=d&q={name}', 'platform' => 'ios'],
        ['id' => 'geo', 'icon' => 'fa-solid fa-location-arrow',    'lang' => 'nav_app_geo',    'url' => 'geo:{lat},{lon}?q={lat},{lon}({name})', 'platform' => 'all'],
        ['id' => 'osm', 'icon' => 'fa-solid fa-route',    'lang' => 'nav_app_osm',    'url' => 'https://www.openstreetmap.org/directions?route=;{lat},{lon}', 'platform' => 'all'],
    ];
}

/** 給前端的完整導航設定：按鈕圖示與各軟體清單 */
function souliong_nav_config(): array
{
    return ['icon' => 'fa-solid fa-diamond-turn-right', 'apps' => souliong_nav_apps()];
}

/**
 * 套值：把模板的 {lat}／{lon}／{name} 填成實際連結。座標取到小數 6 位；沒有名稱時 name 改填 "lat,lon"，
 * 並一律 URL 編碼。前端 viewer.core.js 的 navUrl() 與 api=spots 的 nav 欄位語意相同。
 */
function souliong_nav_url(string $tpl, float $lat, float $lon, string $title = ''): string
{
    $la = json_encode(round($lat, 6));
    $lo = json_encode(round($lon, 6));
    return strtr($tpl, ['{lat}' => $la, '{lon}' => $lo, '{name}' => rawurlencode($title !== '' ? $title : $la . ',' . $lo)]);
}
