<?php
/** Font Awesome Free 6.5.1 Solid subset; names and SVG paths come from a fixed catalog. */
const SOULIONG_DEFAULT_PIN_ICON = 'location-dot';
function souliong_marker_icons(): array
{
    static $icons;
    return $icons ??= json_decode(file_get_contents(__DIR__ . '/../assets/icons/fontawesome-solid.json'), true, 512, JSON_THROW_ON_ERROR);
}
function souliong_marker_icon(mixed $name): string
{
    return is_string($name) && array_key_exists($name, souliong_marker_icons()) ? $name : SOULIONG_DEFAULT_PIN_ICON;
}
function souliong_marker_icon_svg(mixed $name): string
{
    $icon = souliong_marker_icons()[souliong_marker_icon($name)];
    $svg = '<svg viewBox="0 0 ' . (int)$icon['width'] . ' ' . (int)$icon['height'] . '" fill="currentColor" aria-hidden="true">';
    foreach ($icon['paths'] as $path) $svg .= '<path d="' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '"/>';
    return $svg . '</svg>';
}
