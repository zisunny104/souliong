<?php
/** 投稿授權白名單；CC 授權使用 4.0，CC0 使用 1.0。 */
function souliong_licenses(): array {
    $names = ['cc0' => 'CC0', 'cc-by' => 'CC BY', 'cc-by-sa' => 'CC BY-SA', 'cc-by-nd' => 'CC BY-ND', 'cc-by-nc' => 'CC BY-NC', 'cc-by-nc-sa' => 'CC BY-NC-SA', 'cc-by-nc-nd' => 'CC BY-NC-ND'];
    $out = [];
    foreach ($names as $key => $label) {
        $code = substr($key, 3);
        $out[$key] = ['label' => $label, 'url' => $key === 'cc0' ? 'https://creativecommons.org/publicdomain/zero/1.0/deed.zh-hant' : 'https://creativecommons.org/licenses/' . $code . '/4.0/deed.zh-hant'];
    }
    return $out;
}
function souliong_author_url(mixed $value): ?string {
    if (!is_string($value) || strlen($value) > 500 || !filter_var($value, FILTER_VALIDATE_URL)) return null;
    return in_array(strtolower((string)parse_url($value, PHP_URL_SCHEME)), ['https', 'http'], true) ? $value : null;
}
