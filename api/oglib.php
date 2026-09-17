<?php
// 社群分享預覽卡（OG/Twitter Card）共用邏輯：純函式，無副作用，供 pages/view.php／pages/landing.php
// 一起用。點位解析沿用 assets/js/viewer.core.js 的 effectiveSpots() 同一套「起點（有 num）＋
// edit_of 鏈取最新一筆覆寫 lat/lon/feature」演算法，在伺服器端重寫一次。
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/coverlib.php';

/** 點位「名稱」欄位不是單一 key，跟前端 spotName() 用同一套優先序。 */
function souliong_og_spot_name(array $s): string
{
    return (string)($s['theme'] ?? $s['title'] ?? $s['chair'] ?? '');
}

/** 依 numbering 規則組出跟前端 spotTitle() 一致的標題。 */
function souliong_og_spot_title(string $name, ?int $num, string $numbering): string
{
    if ($numbering === 'disable' || $num === null) return $name;
    $n = str_pad((string)$num, 2, '0', STR_PAD_LEFT);
    return $numbering === 'prefix' ? ($n . ' ' . $name) : ($name . ' ' . $n);
}

/** 把長文字裁到 OG 描述合理長度，換行轉空白。 */
function souliong_og_truncate(string $s, int $len = 200): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    if (function_exists('mb_strlen')) {
        return mb_strlen($s, 'UTF-8') > $len ? mb_substr($s, 0, $len, 'UTF-8') . '…' : $s;
    }
    return strlen($s) > $len ? substr($s, 0, $len) . '…' : $s;
}

/**
 * 解析 ?spot=<num>：掃 store_all() 篩 kind==='spot'，起點判斷用 isset($r['num'])（跟
 * effectiveSpots()/editspot.php 同一套邏輯），找到起點後再找 edit_of 指到它、created_at 最新的
 * 一筆覆寫 lat/lon/feature。回傳 null 表示這個 num 不存在（回退到專案預設卡）。
 */
function souliong_og_resolve_spot(array $apiCfg, string $project, int $num): ?array
{
    $all = store_all($apiCfg, $project);
    $origin = null;
    $edits = [];
    foreach ($all as $r) {
        if (($r['kind'] ?? '') !== 'spot') continue;
        if (empty($r['edit_of']) && isset($r['num'])) {
            if ((int)$r['num'] === $num) $origin = $r;
        } elseif (!empty($r['edit_of'])) {
            $edits[$r['edit_of']][] = $r;
        }
    }
    if ($origin === null) return null;

    $latest = null;
    foreach ($edits[$origin['id']] ?? [] as $e) {
        if ($latest === null || (string)($e['created_at'] ?? '') > (string)($latest['created_at'] ?? '')) $latest = $e;
    }
    if ($latest !== null) {
        foreach (['lat', 'lon', 'feature'] as $k) {
            if (array_key_exists($k, $latest)) $origin[$k] = $latest[$k];
        }
    }
    return $origin;
}

/**
 * ?entry=<id>：直接用既有的 store_find()——og:image 只看檔案欄位（photo/thumb/media），這些
 * 欄位不受編輯覆寫。
 */
function souliong_og_resolve_entry(array $apiCfg, string $project, string $id): ?array
{
    return store_find($apiCfg, $project, $id);
}

/**
 * 依 entry 的 kind 決定組 Route::api('photo',...) 還是 Route::api('media',...) 的縮圖網址；
 * audio／text 或缺縮圖回 null，呼叫端接手 fallback 到專案封面。回傳 [action, qs]。
 */
function souliong_og_entry_image_qs(array $entry): ?array
{
    $kind = (string)($entry['kind'] ?? 'photo');
    if ($kind === 'photo' && !empty($entry['photo'])) {
        return ['photo', ['f' => $entry['photo']]];
    }
    if ($kind === 'video' && !empty($entry['thumb'])) {
        return ['photo', ['f' => $entry['thumb']]];
    }
    return null;
}
