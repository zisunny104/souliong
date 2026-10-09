<?php
/** Safe project display colors. Stored spot records remain unchanged. */
function souliong_hex_color(mixed $value, string $fallback): string
{
    return is_string($value) && preg_match('/^#[0-9a-f]{6}$/iD', $value) ? strtolower($value) : $fallback;
}
function souliong_spot_color(array $meta, array $spot): string
{
    if (is_string($spot['markerColor'] ?? null) && preg_match('/^#[0-9a-f]{6}$/iD', $spot['markerColor'])) return strtolower($spot['markerColor']);
    $cat = (string)($spot['cat'] ?? '');
    $category = souliong_hex_color($meta['categoryColors'][$cat] ?? null, '');
    if ($category !== '') return $category;
    if ($cat === '' || $cat === 'new') return '#7a7f87';
    return souliong_hex_color($meta['categoryColors'][$cat] ?? null, souliong_hex_color($spot['color'] ?? null, '#7a7f87'));
}
