<?php
/**
 * 向量底圖的地名標註語言 —— 全站唯一一份設定。前端（maplibre-engine.js）只依 APP.labelFields 把
 * 每個 symbol 圖層的 text-field 組成 coalesce，不自己決定欄位順序。
 *
 * 欄位取自 OpenMapTiles 圖磚的名稱屬性，由優先到備援排列；最後一律是 name（在地名），標註才不會變空白。
 * 鍵是介面語言代碼（i18n_supported()），要新增語言在這裡加一列並補 lang 檔的 maplabel_lang_<代碼>。
 * meta.json 的 mapLabelLang：沒有欄位或 'auto'＝跟隨介面語言；否則必須是這裡的鍵，
 * 未知值一律當 auto（fail-closed，不會把壞值原樣交給前端）。
 */
function souliong_label_fields(): array
{
    return [
        'zh_TW' => ['name:zh-Hant', 'name:zh', 'name:zh-Hans', 'name:en', 'name'],
        'en'    => ['name:en', 'name:latin', 'name_en', 'name'],
    ];
}

/** 這張地圖固定使用的標註語言代碼；沒指定或值不合法回 'auto'。 */
function souliong_label_lang(?array $meta): string
{
    $v = $meta['mapLabelLang'] ?? '';
    return is_string($v) && isset(souliong_label_fields()[$v]) ? $v : 'auto';
}
