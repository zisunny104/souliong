<?php
// 社群分享預覽卡（OG/Twitter Card）共用邏輯：純函式，無副作用，供 pages/view.php／pages/landing.php
// 一起用。點位解析沿用 assets/js/viewer.core.js 的 effectiveSpots() 同一套「起點（有 num）＋
// edit_of 鏈取最新一筆覆寫 lat/lon/content」演算法，在伺服器端重寫一次。
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/coverlib.php';
require_once __DIR__ . '/spotlib.php';

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

/** Markdown 內容轉成純文字（OG 描述不能帶標記）：走 spot_markdown() 算繪後去標籤。 */
function souliong_og_plain(string $md): string
{
    $html = preg_replace('#</(p|li|h[1-6]|tr|blockquote|pre)>|<br\s*/?>#i', ' ', spot_markdown($md));
    return html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
 * 解析 ?spot=<num>：委派給 api/spotlib.php 的 spot_effective()，跟 editspot.php／
 * spotcontent.php 共用同一套「起點＋edit_of 鏈取最新覆寫」算法。回傳 null 表示這個 num
 * 不存在（回退到專案預設卡）。
 */
function souliong_og_resolve_spot(array $apiCfg, string $project, int $num): ?array
{
    return spot_effective($apiCfg, $project, $num);
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
