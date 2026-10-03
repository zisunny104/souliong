<?php
// 主題包（面板材質／外框／字型）註冊表。跟 features.php 的模組開關不同：
// 這裡選的不是布林值，是「用哪一包」，所以檔案分開放，機制卻刻意跟模組系統同一套精神——
// 註冊表就是資料夾本身，沒有中央 index 檔，新增一包只要新增一個資料夾。
//
// 一包最少要有 pack.json（給後台列表/匯出檔名用的中繼資料）跟 pack.css（真正的樣式，含材質、
// 外框、字型）。pack.css 由 view.php 在既有的 base 主題 CSS 之後原樣 readfile() 進同一個
// <style> 區塊，純靠 cascade 順序覆寫、不需要 !important；沒選包時這個 readfile() 根本不會執行，
// 因此舊專案（meta.json 沒有 pack 欄位）行為與拆分之前完全一致。素材一律用 CSS 內嵌的
// data: URI（見 demo-loud 範例），不落地圖檔，所以不需要像 layers.php 那樣另開檔案端點。
//
// ── 兩層作用域（跟 api/layers.php 同一套，2026-08-25 使用者定調也適用於 packs）──
// site    : <app>/packs/<id>/             平台內建、所有地圖共用，隨程式碼進版控
// project : projects/<proj>/packs/<id>/   這張地圖專屬（客製材質不想污染全站清單時用）
//
// 專案層放在 projects/ 底下的理由跟圖層一樣：那整棵資料夾本來就在 .gitignore，天然不進版控。
// 同名時專案層覆蓋全站層。
//
// 作用範圍以專案為主，另有一層全站預設（state/settings.json 的 pack，後台「工具」分頁設定），
// 供「整個平台想有一致外觀」的部署使用；解析順序見 souliong_pack_for()。

require_once __DIR__ . '/settings.php';

/** 平台內建 packs/ 資料夾；packs_dir 沒設也要能運作（舊部署的 api/config.php 不會有這個 key）。 */
function souliong_packs_dir(array $cfg): string
{
    $dir = (string)($cfg['packs_dir'] ?? '');
    return rtrim($dir !== '' ? $dir : __DIR__ . '/../packs', "/\\");
}

/**
 * 主題包搜尋路徑，由低優先到高優先（後面的同名 id 覆蓋前面的）。
 * 回傳 [ scope => 絕對路徑 ]，scope 為 'site' 或 'project'。
 */
function souliong_pack_roots(array $cfg, string $proj = ''): array
{
    $roots = [];
    $site = souliong_packs_dir($cfg);
    if ($site !== '') {
        $roots['site'] = $site;
    }
    $pdir = rtrim((string)($cfg['projects_dir'] ?? ''), "/\\");
    if ($proj !== '' && $pdir !== '' && preg_match('/^[a-z0-9_-]+$/', $proj)) {
        $roots['project'] = $pdir . '/' . $proj . '/packs';
    }
    return $roots;
}

/**
 * 掃所有搜尋路徑，回傳 [ id => pack.json 解析後的陣列 ]；沒有合法 pack.json 就跳過。
 * manifest 會被補上 id 與 scope 兩個欄位——資料夾名稱與所在作用域才是真的，
 * pack.json 內容不可覆寫（匯入時避免偽造）。
 */
function souliong_pack_list(array $cfg, string $proj = ''): array
{
    $out = [];
    foreach (souliong_pack_roots($cfg, $proj) as $scope => $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        foreach (scandir($dir) ?: [] as $id) {
            if ($id === '.' || $id === '..' || !preg_match('/^[a-z0-9_-]+$/', $id)) {
                continue;
            }
            $mf = $dir . '/' . $id . '/pack.json';
            if (!is_file($mf)) {
                continue;
            }
            $manifest = json_decode((string)@file_get_contents($mf), true);
            if (!is_array($manifest)) {
                continue;
            }
            $manifest['id'] = $id;
            $manifest['scope'] = $scope;
            $out[$id] = $manifest;   // 專案層排在後面，同名時自然覆蓋全站層
        }
    }
    return $out;
}

/** 單包的資料夾絕對路徑（專案層優先）；id 不合法或哪邊都沒有回 null。 */
function souliong_pack_dir(array $cfg, string $id, string $proj = ''): ?string
{
    if (!preg_match('/^[a-z0-9_-]+$/', $id)) {
        return null;
    }
    $found = null;
    foreach (souliong_pack_roots($cfg, $proj) as $dir) {
        if (is_dir($dir . '/' . $id)) {
            $found = $dir . '/' . $id;   // 不 return，讓後面的專案層蓋掉前面的全站層
        }
    }
    return $found;
}

/**
 * $meta 是專案 meta.json 解析後的陣列（可能是 null），$proj 是該專案 id（用來解析專案層的包）。
 * 回傳該專案目前生效的主題包 manifest；選的包已不存在或最後解析成空 → null（維持黑白預設）。
 *
 * 解析順序（以專案為主，全站只是沒指定時的退路）：
 *   1. meta.json 有 pack 欄位 → 以它為準。**值是空字串代表「這張地圖明確不套用」**，
 *      即使全站設了包也不跟——想維持黑白的地圖要能拒絕全站換皮。
 *   2. 沒有 pack 欄位 → 用全站預設（state/settings.json 的 pack）。舊地圖都是這一類，
 *      全站沒設包時結果仍是 null，行為與加入這層之前完全一致。
 */
function souliong_pack_for(array $cfg, ?array $meta, string $proj = ''): ?array
{
    $id = (is_array($meta) && array_key_exists('pack', $meta) && is_string($meta['pack']))
        ? $meta['pack']
        : souliong_site_pack($cfg);
    if ($id === '') {
        return null;
    }
    return souliong_pack_list($cfg, $proj)[$id] ?? null;
}
