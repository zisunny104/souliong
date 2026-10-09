<?php
require_once __DIR__ . '/oglib.php';
require_once __DIR__ . '/imaging.php';
require_once __DIR__ . '/markercolors.php';
require_once __DIR__ . '/licenses.php';
require_once __DIR__ . '/layers.php';
require_once __DIR__ . '/socialmaplib.php';

/** Only image files named by a public record in this project can enter the renderer. */
function souliong_social_image(array $cfg, string $project, mixed $photo): ?string
{
    if (!is_string($photo) || !preg_match('#^' . preg_quote($project, '#') . '/([A-Za-z0-9_-]+\.(?:jpg|jpeg|png|webp))$#iD', $photo, $match)) return null;
    $root = realpath(project_dir($cfg, $project) . '/photos');
    if (!$root) return null;
    $base = preg_replace('/\.[a-z]+$/i', '', $match[1]);
    foreach ([$base . '_t.webp', $base . '_t.jpg', $base . '_t.png', $match[1]] as $file) {
        $path = realpath($root . '/' . $file);
        if ($path && str_starts_with($path, $root . DIRECTORY_SEPARATOR) && is_file($path) && filesize($path) <= 24 * 1024 * 1024) return $path;
    }
    return null;
}

/** 名片上顯示的聲音長度（秒）：聲音投稿用該筆的長度，點位用內容裡第一個有長度的聲音區塊；沒有就是 null。 */
function souliong_social_audio_seconds(?array $entry, ?array $spot): ?int
{
    $pick = fn($v) => is_numeric($v) && $v > 0 && $v <= 86400 ? (int)round((float)$v) : null;
    if ($entry) return ($entry['kind'] ?? '') === 'audio' ? $pick($entry['duration'] ?? null) : null;
    foreach ((array)($spot['content'] ?? []) as $block) {
        if (is_array($block) && ($block['kind'] ?? '') === 'audio' && ($seconds = $pick($block['duration'] ?? null))) return $seconds;
    }
    return null;
}

function souliong_social_data(array $cfg, string $project, array $meta, string $entryId = '', string $spotRef = ''): ?array
{
    $entries = souliong_og_entries(store_all($cfg, $project));
    $entry = $entryId !== '' ? ($entries[$entryId] ?? null) : null;
    if ($entryId !== '' && !$entry) return null;
    $spot = $entry
        ? (isset($entry['item_num']) ? souliong_og_resolve_spot($cfg, $project, (string)$entry['item_num']) : null)
        : ($spotRef !== '' ? souliong_og_resolve_spot($cfg, $project, $spotRef) : null);
    if (!$entry && $spotRef !== '' && !$spot) return null;
    $title = (string)($meta['title'] ?? 'Souliong');
    $spotName = $spot ? souliong_og_spot_name($spot) : '';
    $kind = $entry ? (string)($entry['kind'] ?? 'photo') : '';
    $image = $entry ? souliong_social_image($cfg, $project, $kind === 'video' ? ($entry['thumb'] ?? '') : ($entry['photo'] ?? '')) : null;
    if (!$entry && $spot) {
        foreach ((array)($spot['content'] ?? []) as $block) {
            if (is_array($block) && ($block['kind'] ?? '') === 'photo' && ($image = souliong_social_image($cfg, $project, $block['photo'] ?? ''))) break;
        }
        if (!$image) {
            $photos = array_filter($entries, fn($r) => ($r['kind'] ?? 'photo') === 'photo' && !empty($r['photo']));
            usort($photos, fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
            foreach ($photos as $photo) {
                if ((string)($photo['item_num'] ?? '') === (string)$spot['num'] && ($image = souliong_social_image($cfg, $project, $photo['photo']))) break;
            }
        }
    }
    $cover = cover_file_of(project_dir($cfg, $project) . '/cover');
    $root = realpath(project_dir($cfg, $project));
    if ($cover && (!$root || !str_starts_with((string)realpath($cover), $root . DIRECTORY_SEPARATOR) || filesize($cover) > 24 * 1024 * 1024)) $cover = null;
    if (!$image && ($meta['cover']['mode'] ?? '') === 'custom' && !in_array($kind, ['text', 'audio', 'video'], true)) $image = $cover;
    $licenses = souliong_licenses();
    return [
        'kind' => $entry ? 'entry' : ($spot ? 'spot' : 'project'),
        'entryKind' => $kind,
        'entryLabel' => $entry ? souliong_kind_label($kind) : '',
        'projectTitle' => $title,
        'title' => $spot ? souliong_og_spot_title($spotName, (int)$spot['num'], $meta['numbering'] ?? 'suffix') : $title,
        'spotName' => $spotName,
        'spotId' => $spot ? (string)$spot['id'] : null,
        'description' => souliong_og_truncate(souliong_og_plain((string)($entry ? ($entry['comment'] ?? '') : ($spot ? spot_content_text($spot) : ($meta['desc'] ?? $meta['subtitle'] ?? '')))), 240),
        'author' => (string)($entry['name'] ?? ''),
        'license' => (string)($licenses[$entry['license'] ?? '']['label'] ?? ''),
        'image' => $image,
        'map' => ($meta['cover']['mode'] ?? '') === 'auto' ? $cover : null,
        'markerColor' => $spot ? souliong_spot_color($meta, $spot) : null,
        'audioSeconds' => souliong_social_audio_seconds($entry, $spot),
        'coordinates' => $spot && is_numeric($spot['lat'] ?? null) && is_numeric($spot['lon'] ?? null) ? [(float)$spot['lat'], (float)$spot['lon']] : null,
        'metaRevision' => hash('sha256', json_encode($meta)),
    ];
}

function souliong_social_revision(array $cfg, string $project, array $meta, array $data): string
{
    $files = [];
    foreach (['assets/css/map-markers.css', 'assets/js/viewer.core.js', 'assets/js/engine/maplibre-engine.js'] as $file) $files[$file] = filemtime(dirname(__DIR__) . '/' . $file);
    foreach (['image', 'map'] as $field) {
        $path = $data[$field] ?? null;
        if ($path && is_file($path)) $files[$field] = [filesize($path), filemtime($path)];
    }
    foreach (souliong_layers_for($cfg, $meta, $project) as $layer) {
        $dir = souliong_layer_dir($cfg, (string)$layer['id'], $project);
        foreach ($dir ? (glob($dir . '/*.json') ?: []) : [] as $path) $files[$path] = [filesize($path), filemtime($path)];
    }
    return hash('sha256', json_encode([$data, $files, souliong_social_runtime($cfg), filemtime(__FILE__), filemtime(__DIR__ . '/socialmaplib.php'), filemtime(dirname(__DIR__) . '/tools/social-preview-map.cjs')]));
}

function souliong_social_attribution(array $cfg, string $project, array $meta): string
{
    $credits = ['MapLibre'];
    foreach (souliong_layers_for($cfg, $meta, $project) as $layer) {
        foreach ((array)($layer['attribution'] ?? []) as $item) {
            if (!is_array($item) || empty($item['text'])) continue;
            $suffix = str_replace('{osm_contributors}', 'contributors', (string)($item['suffix'] ?? ''));
            $credits[] = (!empty($item['copyright']) ? '© ' : '') . strip_tags((string)$item['text']) . ($suffix !== '' ? ' ' . $suffix : '');
        }
    }
    return implode(' · ', array_unique($credits));
}

/** Round only the corner pixels; image sizing always uses contain, never cover. */
function souliong_social_round($canvas, int $x, int $y, int $width, int $height, int $radius, $background = null): void
{
    $radius = min($radius, (int)($width / 2), (int)($height / 2));
    foreach ([[0, 0], [$width - $radius, 0], [0, $height - $radius], [$width - $radius, $height - $radius]] as [$cx, $cy]) {
        for ($i = 0; $i < $radius; $i++) for ($j = 0; $j < $radius; $j++) {
            $px = $cx + $i; $py = $cy + $j;
            $dx = $cx === 0 ? $radius - .5 - $i : $i + .5;
            $dy = $cy === 0 ? $radius - .5 - $j : $j + .5;
            if ($dx * $dx + $dy * $dy > $radius * $radius) imagesetpixel($canvas, $x + $px, $y + $py, $background ? imagecolorat($background, $px, $py) : 0xffffff);
        }
    }
}

function souliong_social_font(array $cfg): ?string
{
    $paths = isset($cfg['social_preview_font'])
        ? [(string)$cfg['social_preview_font']]
        : [
            '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/truetype/noto/NotoSansTC-Regular.ttf',
            '/usr/share/fonts/truetype/noto/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/opentype/noto/NotoSansCJKtc-Regular.otf',
        ];
    foreach ($paths as $path) if (is_file($path) && is_readable($path)) return $path;
    return null;
}

function souliong_social_ready(array $cfg): bool
{
    return function_exists('imagecreatetruecolor') && function_exists('imagettftext')
        && function_exists('imagejpeg') && souliong_social_font($cfg) !== null;
}

function souliong_social_wrap(string $text, string $font, int $size, int $width, int $limit): array
{
    if ($limit === 1) {
        $line = souliong_og_truncate($text, 500); $trimmed = false;
        while ($line !== '' && (($box = imagettfbbox($size, 0, $font, $line . ($trimmed ? '…' : '')))[2] - $box[0] > $width)) {
            $line = preg_replace('/.$/us', '', $line); $trimmed = true;
        }
        return $line === '' ? [] : [$line . ($trimmed ? '…' : '')];
    }
    $chars = preg_split('//u', souliong_og_truncate($text, 500), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $lines = []; $line = '';
    foreach ($chars as $i => $char) {
        $box = imagettfbbox($size, 0, $font, $line . $char);
        if ($line !== '' && $box[2] - $box[0] > $width) {
            $lines[] = rtrim($line); $line = ltrim($char);
            if (count($lines) === $limit - 1) {
                $line = implode('', array_slice($chars, $i));
                while ($line !== '' && (($box = imagettfbbox($size, 0, $font, $line . '…'))[2] - $box[0] > $width)) {
                    $line = preg_replace('/.$/us', '', $line);
                }
                $lines[] = rtrim($line) . '…';
                return $lines;
            }
        } else $line .= $char;
    }
    if ($line !== '') $lines[] = rtrim($line);
    return $lines;
}

function souliong_social_text($canvas, string $text, string $font, int $size, int $x, int $y, int $width, int $lines, int $color, int $leading): void
{
    foreach (souliong_social_wrap($text, $font, $size, $width, $lines) as $line) {
        imagettftext($canvas, $size, 0, $x, $y, $color, $font, $line);
        $y += $leading;
    }
}

/** Photographs retain their full bounds; only the decorative map background fills the canvas. */
function souliong_social_render(array $data, string $font): ?string
{
    $canvas = imagecreatetruecolor(1200, 630);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 247, 249, 241));
    $decodedMap = !empty($data['mapBytes']) ? souliong_image_decode_bytes($data['mapBytes'])
        : (!empty($data['map']) ? souliong_image_decode_file($data['map']) : null);
    if ($decodedMap) {
        [$source, $w, $h] = $decodedMap;
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, 1200, 630, $w, $h);
        imagedestroy($source);
        for ($x = 0; $x < 1200; $x++) {
            $opacity = (int)round(120 - 115 * min(1, pow($x / 920, 1.2)));
            imageline($canvas, $x, 0, $x, 629, imagecolorallocatealpha($canvas, 250, 250, 244, $opacity));
        }
    } else {
        for ($y = 0; $y < 630; $y++) {
            $mix = $y / 629;
            imageline($canvas, 0, $y, 1199, $y, imagecolorallocate($canvas, (int)(239 + 9 * $mix), (int)(246 + 2 * $mix), (int)(237 + 7 * $mix)));
        }
    }
    $ink = imagecolorallocate($canvas, 35, 55, 48);
    $muted = imagecolorallocate($canvas, 78, 95, 86);
    $accent = imagecolorallocate($canvas, 32, 115, 95);
    souliong_social_text($canvas, $data['projectTitle'], $font, 23, 64, 69, 1068, 1, $accent, 32);
    $hasPhoto = false;
    if (!empty($data['image']) && ($decoded = souliong_image_decode_file($data['image']))) {
        [$source, $w, $h] = $decoded;
        $scale = min(518 / $w, 320 / $h);
        $dw = max(1, (int)round($w * $scale)); $dh = max(1, (int)round($h * $scale));
        $px = 618 + (int)((518 - $dw) / 2); $py = 93 + (int)((320 - $dh) / 2);
        $under = imagecreatetruecolor($dw, $dh); imagecopy($under, $canvas, 0, 0, $px, $py, $dw, $dh);
        imagecopyresampled($canvas, $source, $px, $py, 0, 0, $dw, $dh, $w, $h);
        souliong_social_round($canvas, $px, $py, $dw, $dh, 18, $under); imagedestroy($under);
        imagedestroy($source); $hasPhoto = true;
        if (($data['entryKind'] ?? '') === 'video') {
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefilledellipse($canvas, 877, 253, 64, 64, imagecolorallocatealpha($canvas, 35, 55, 48, 30));
            imagefilledpolygon($canvas, [869, 237, 869, 269, 894, 253], $white);
        }
    }
    if ($data['kind'] === 'entry') {
        if (!$hasPhoto) souliong_social_text($canvas, $data['entryLabel'] ?? '', $font, 18, 618, 130, 518, 1, $accent, 28);
        if (!$hasPhoto && ($data['entryKind'] ?? '') === 'audio') {
            $wave = imagecolorallocate($canvas, 32, 115, 95);
            foreach ([30, 58, 86, 116, 86, 58, 30] as $i => $height) {
                imagefilledrectangle($canvas, 760 + $i * 34, 204 - (int)($height / 2), 771 + $i * 34, 204 + (int)($height / 2), $wave);
            }
        }
        if (!$hasPhoto && ($data['entryKind'] ?? '') === 'video') {
            imagefilledellipse($canvas, 877, 204, 72, 72, $accent);
            imagefilledpolygon($canvas, [867, 185, 867, 223, 897, 204], imagecolorallocate($canvas, 255, 255, 255));
        }
        $media = !$hasPhoto && in_array($data['entryKind'] ?? '', ['audio', 'video'], true);
        souliong_social_text($canvas, $data['description'], $font, 25, 618, $hasPhoto ? 459 : ($media ? 333 : 192), 518, $hasPhoto ? (empty($data['spotName']) ? 3 : 2) : ($media ? 5 : 9), $ink, 38);
        $credit = implode(' · ', array_filter([$data['author'] ?? '', $data['license'] ?? ''], fn($v) => $v !== ''));
        souliong_social_text($canvas, $credit, $font, 18, 618, 598, 518, 1, $muted, 28);
        if (($data['spotName'] ?? '') !== '') souliong_social_text($canvas, $data['spotName'], $font, 18, 618, 553, 518, 1, $accent, 28);
    } else {
        souliong_social_text($canvas, $data['title'], $font, $hasPhoto ? 28 : 39, 618, $hasPhoto ? 463 : 220, 518, $hasPhoto ? 1 : 2, $ink, 54);
        $hasAudio = !empty($data['audioSeconds']);
        souliong_social_text($canvas, $data['description'], $font, 24, 618, $hasPhoto ? 512 : ($hasAudio ? 408 : 365), 518, $hasPhoto ? 2 : ($hasAudio ? 4 : 5), $muted, 36);
    }
    if (!empty($data['audioSeconds']) && $data['kind'] !== 'entry') {
        $seconds = (int)$data['audioSeconds'];
        $y = $hasPhoto ? 590 : 330;
        imagefilledellipse($canvas, 632, $y - 8, 30, 30, $accent);
        imagefilledpolygon($canvas, [626, $y - 15, 626, $y - 1, 639, $y - 8], imagecolorallocate($canvas, 255, 255, 255));
        imagettftext($canvas, 20, 0, 656, $y, $accent, $font, sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60));
    }
    souliong_social_text($canvas, 'Souliong', $font, 14, 32, 582, 530, 1, $accent, 20);
    if (!empty($data['attribution'])) souliong_social_text($canvas, $data['attribution'], $font, 9, 32, 603, 540, 2, $muted, 14);
    // 右緣一條粗線，顏色就是這個點位的地標色；整張名片沒有點位（專案預覽）時用主色
    $barColor = is_string($data['markerColor'] ?? null) && preg_match('/^#[0-9a-f]{6}$/iD', $data['markerColor'])
        ? imagecolorallocate($canvas, hexdec(substr($data['markerColor'], 1, 2)), hexdec(substr($data['markerColor'], 3, 2)), hexdec(substr($data['markerColor'], 5, 2))) : $accent;
    imagefilledrectangle($canvas, 1200 - 22, 0, 1199, 629, $barColor);
    souliong_social_round($canvas, 0, 0, 1200, 630, 24);
    ob_start(); $ok = imagejpeg($canvas, null, 88); $bytes = ob_get_clean();
    imagedestroy($canvas);
    return $ok ? $bytes : null;
}
