<?php
require_once __DIR__ . '/oglib.php';
require_once __DIR__ . '/imaging.php';
require_once __DIR__ . '/markercolors.php';
require_once __DIR__ . '/licenses.php';
require_once __DIR__ . '/layers.php';
require_once __DIR__ . '/socialmaplib.php';
require_once __DIR__ . '/i18n.php';

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
        'brand' => i18n_t(i18n_dict('zh_TW'), 'app_title'),   // 與首頁相同的平台名稱（中英並列）
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
function souliong_social_round($canvas, int $x, int $y, int $width, int $height, int $radius, $background = null, bool $rightCorners = true): void
{
    $radius = min($radius, (int)($width / 2), (int)($height / 2));
    $corners = $rightCorners ? [[0, 0], [$width - $radius, 0], [0, $height - $radius], [$width - $radius, $height - $radius]] : [[0, 0], [0, $height - $radius]];
    foreach ($corners as [$cx, $cy]) {
        for ($i = 0; $i < $radius; $i++) for ($j = 0; $j < $radius; $j++) {
            $px = $cx + $i; $py = $cy + $j;
            $dx = $cx === 0 ? $radius - .5 - $i : $i + .5;
            $dy = $cy === 0 ? $radius - .5 - $j : $j + .5;
            if ($dx * $dx + $dy * $dy > $radius * $radius) imagesetpixel($canvas, $x + $px, $y + $py, $background ? imagecolorat($background, $px, $py) : 0xffffff);
        }
    }
}

/** 網頁那種玻璃膠囊：近白底加一圈淡邊。不用透明度，避免圓角與矩形接縫處重疊變色。 */
function souliong_social_pill($canvas, int $x0, int $y0, int $x1, int $y1): void
{
    $edge = imagecolorallocate($canvas, 214, 222, 211);
    $fill = imagecolorallocate($canvas, 251, 252, 248);
    foreach ([[0, $edge], [1, $fill]] as [$inset, $color]) {
        $a = $x0 + $inset; $b = $y0 + $inset; $c = $x1 - $inset; $d = $y1 - $inset;
        $r = min((int)(($d - $b) / 2), 24);
        imagefilledrectangle($canvas, $a + $r, $b, $c - $r, $d, $color);
        imagefilledrectangle($canvas, $a, $b + $r, $c, $d - $r, $color);
        foreach ([[$a + $r, $b + $r], [$c - $r, $b + $r], [$a + $r, $d - $r], [$c - $r, $d - $r]] as [$cx, $cy]) imagefilledellipse($canvas, $cx, $cy, $r * 2, $r * 2, $color);
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

/** 粗體字型：與一般字型同一套 Noto CJK 的 Bold；找不到就回 null，由呼叫端用重疊繪製模擬。 */
function souliong_social_font_bold(array $cfg): ?string
{
    $paths = isset($cfg['social_preview_font_bold'])
        ? [(string)$cfg['social_preview_font_bold']]
        : [
            '/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc',
            '/usr/share/fonts/truetype/noto/NotoSansTC-Bold.ttf',
            '/usr/share/fonts/truetype/noto/NotoSansCJK-Bold.ttc',
            '/usr/share/fonts/opentype/noto/NotoSansCJKtc-Bold.otf',
        ];
    foreach ($paths as $path) if (is_file($path) && is_readable($path)) return $path;
    return null;
}

/** 名稱用粗體；沒有粗體字型時同一行錯開 1px 畫兩次。 */
function souliong_social_text_bold($canvas, string $text, string $font, ?string $bold, int $size, int $x, int $y, int $width, int $lines, int $color, int $leading): void
{
    $use = $bold ?: $font;
    foreach (souliong_social_wrap($text, $use, $size, $width, $lines) as $line) {
        imagettftext($canvas, $size, 0, $x, $y, $color, $use, $line);
        if (!$bold) imagettftext($canvas, $size, 0, $x + 1, $y, $color, $use, $line);
        $y += $leading;
    }
}

function souliong_social_ready(array $cfg): bool
{
    return function_exists('imagecreatetruecolor') && function_exists('imagettftext')
        && function_exists('imagejpeg') && souliong_social_font($cfg) !== null;
}

function souliong_social_wrap(string $text, string $font, int $size, int $width, int $limit): array
{
    $measure = function (string $t) use ($font, $size): int { $box = imagettfbbox($size, 0, $font, $t); return $box[2] - $box[0]; };
    if ($limit === 1) {
        $line = souliong_og_truncate($text, 500); $trimmed = false;
        while ($line !== '' && $measure($line . ($trimmed ? '…' : '')) > $width) {
            $line = preg_replace('/.$/us', '', $line); $trimmed = true;
        }
        return $line === '' ? [] : [$line . ($trimmed ? '…' : '')];
    }
    // 斷句單位：英數字詞整個一組（不從單字中間斷開）、其餘一字一組；超過欄寬的長字詞才拆成單字元
    preg_match_all('/[A-Za-z0-9][A-Za-z0-9_\'’.\-]*|./us', souliong_og_truncate($text, 500), $found);
    $units = [];
    foreach ($found[0] as $unit) {
        if (preg_match('/^[A-Za-z0-9]/', $unit) && mb_strlen($unit) > 1 && $measure($unit) > $width) {
            foreach (preg_split('//u', $unit, -1, PREG_SPLIT_NO_EMPTY) as $ch) $units[] = $ch;
        } else $units[] = $unit;
    }
    $noStart = ['，', '。', '、', '．', '；', '：', '！', '？', '）', '】', '」', '』', '》', '〉', '”', '’', '…', '～', ',', '.', ';', ':', '!', '?', ')', ']', '}'];   // 不可出現在行首
    $noEnd = ['（', '【', '「', '『', '《', '〈', '“', '‘', '(', '[', '{'];                                                                                  // 不可出現在行尾
    $lines = []; $line = []; $count = count($units);
    for ($i = 0; $i < $count; $i++) {
        $unit = $units[$i];
        if ($line && $measure(implode('', $line) . $unit) > $width) {
            $carry = [];
            if (in_array($unit, $noStart, true) && count($line) > 1) $carry[] = array_pop($line);   // 標點不落在行首：把前一個字一起帶下去
            while ($line && in_array(end($line), $noEnd, true)) array_unshift($carry, array_pop($line));   // 開括號不留在行尾
            if (count($lines) === $limit - 1) {
                $rest = ltrim(implode('', $carry) . implode('', array_slice($units, $i)));
                while ($rest !== '' && $measure($rest . '…') > $width) $rest = preg_replace('/.$/us', '', $rest);
                $lines[] = rtrim(implode('', $line));
                $lines[] = rtrim($rest) . '…';
                return $lines;
            }
            $lines[] = rtrim(implode('', $line));
            $line = $carry;
            if ($unit === ' ') continue;   // 行首不留空白
        }
        $line[] = $unit;
    }
    if ($line) $lines[] = rtrim(implode('', $line));
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
function souliong_social_render(array $data, string $font, ?string $bold = null): ?string
{
    $canvas = imagecreatetruecolor(1200, 630);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 247, 249, 241));
    $decodedMap = !empty($data['mapBytes']) ? souliong_image_decode_bytes($data['mapBytes'])
        : (!empty($data['map']) ? souliong_image_decode_file($data['map']) : null);
    if ($decodedMap) {
        // 地圖只用左側 800px（截圖的左 2/3），完全不加覆蓋
        [$source, $w, $h] = $decodedMap;
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, 800, 630, (int)round($w * 800 / 1200), $h);
        imagedestroy($source);
    } else {
        for ($y = 0; $y < 630; $y++) {
            $mix = $y / 629;
            imageline($canvas, 0, $y, 799, $y, imagecolorallocate($canvas, (int)(239 + 9 * $mix), (int)(246 + 2 * $mix), (int)(237 + 7 * $mix)));
        }
    }
    // 右側資訊欄：白底，與地圖之間一條細線
    imagefilledrectangle($canvas, 800, 0, 1199, 629, imagecolorallocate($canvas, 253, 253, 251));
    imageline($canvas, 800, 0, 800, 629, imagecolorallocate($canvas, 222, 226, 220));
    $ink = imagecolorallocate($canvas, 35, 55, 48);
    $muted = imagecolorallocate($canvas, 78, 95, 86);
    $accent = imagecolorallocate($canvas, 32, 115, 95);
    $titleLines = souliong_social_wrap($data['projectTitle'], $bold ?: $font, 23, 520, 1);
    if ($titleLines) {
        $box = imagettfbbox(23, 0, $bold ?: $font, $titleLines[0]);
        souliong_social_pill($canvas, 36, 69 + $box[5] - 14, 64 + ($box[2] - $box[0]) + 28, 69 + $box[1] + 14);
    }
    souliong_social_text_bold($canvas, $data['projectTitle'], $font, $bold, 23, 64, 69, 520, 1, $accent, 32);
    $cx = 824; $cw = 320;   // 資訊欄文字起點與寬度（右側留給色條）
    $hasPhoto = false;
    $photoBottom = 0;
    if (!empty($data['image']) && ($decoded = souliong_image_decode_file($data['image']))) {
        [$source, $w, $h] = $decoded;
        $scale = min($cw / $w, 210 / $h);
        $dw = max(1, (int)round($w * $scale)); $dh = max(1, (int)round($h * $scale));
        $px = $cx + (int)(($cw - $dw) / 2); $py = 72;
        $under = imagecreatetruecolor($dw, $dh); imagecopy($under, $canvas, 0, 0, $px, $py, $dw, $dh);
        imagecopyresampled($canvas, $source, $px, $py, 0, 0, $dw, $dh, $w, $h);
        souliong_social_round($canvas, $px, $py, $dw, $dh, 16, $under); imagedestroy($under);
        imagedestroy($source); $hasPhoto = true; $photoBottom = $py + $dh;
        if (($data['entryKind'] ?? '') === 'video') {
            $mx = $px + (int)($dw / 2); $my = $py + (int)($dh / 2);
            imagefilledellipse($canvas, $mx, $my, 56, 56, imagecolorallocatealpha($canvas, 35, 55, 48, 30));
            imagefilledpolygon($canvas, [$mx - 8, $my - 14, $mx - 8, $my + 14, $mx + 14, $my], imagecolorallocate($canvas, 255, 255, 255));
        }
    }
    if ($data['kind'] === 'entry') {
        if (!$hasPhoto) souliong_social_text($canvas, $data['entryLabel'] ?? '', $font, 16, $cx, 108, $cw, 1, $accent, 26);
        if (!$hasPhoto && ($data['entryKind'] ?? '') === 'audio') {
            $wave = imagecolorallocate($canvas, 32, 115, 95);
            foreach ([26, 50, 76, 104, 76, 50, 26] as $i => $height) {
                imagefilledrectangle($canvas, $cx + 28 + $i * 36, 190 - (int)($height / 2), $cx + 28 + $i * 36 + 11, 190 + (int)($height / 2), $wave);
            }
        }
        if (!$hasPhoto && ($data['entryKind'] ?? '') === 'video') {
            imagefilledellipse($canvas, $cx + (int)($cw / 2), 190, 72, 72, $accent);
            imagefilledpolygon($canvas, [$cx + (int)($cw / 2) - 10, 171, $cx + (int)($cw / 2) - 10, 209, $cx + (int)($cw / 2) + 20, 190], imagecolorallocate($canvas, 255, 255, 255));
        }
        $media = !$hasPhoto && in_array($data['entryKind'] ?? '', ['audio', 'video'], true);
        souliong_social_text($canvas, $data['description'], $font, 19, $cx, $hasPhoto ? $photoBottom + 44 : ($media ? 290 : 160), $cw, $hasPhoto ? 4 : ($media ? 8 : 12), $ink, 30);
        $credit = implode(' · ', array_filter([$data['author'] ?? '', $data['license'] ?? ''], fn($v) => $v !== ''));
        souliong_social_text($canvas, $credit, $font, 15, $cx, 596, $cw, 1, $muted, 24);
        if (($data['spotName'] ?? '') !== '') souliong_social_text_bold($canvas, $data['spotName'], $font, $bold, 17, $cx, 560, $cw, 1, $accent, 26);
    } else {
        $hasAudio = !empty($data['audioSeconds']);
        if ($hasPhoto) {
            souliong_social_text_bold($canvas, $data['title'], $font, $bold, 24, $cx, $photoBottom + 44, $cw, 2, $ink, 34);
            souliong_social_text($canvas, $data['description'], $font, 17, $cx, $photoBottom + 44 + 34 * 2 + 6, $cw, $hasAudio ? 2 : 4, $muted, 27);
        } else {
            souliong_social_text_bold($canvas, $data['title'], $font, $bold, 34, $cx, 150, $cw, 3, $ink, 48);
            souliong_social_text($canvas, $data['description'], $font, 21, $cx, $hasAudio ? 400 : 340, $cw, $hasAudio ? 5 : 7, $muted, 32);
        }
        if ($hasAudio) {
            $seconds = (int)$data['audioSeconds'];
            $y = $hasPhoto ? 590 : 330;
            imagefilledellipse($canvas, $cx + 22, $y - 11, 44, 44, $accent);
            imagefilledpolygon($canvas, [$cx + 14, $y - 22, $cx + 14, $y, $cx + 34, $y - 11], imagecolorallocate($canvas, 255, 255, 255));
            imagettftext($canvas, 32, 0, $cx + 58, $y, $accent, $font, sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60));
        }
    }
    $creditLines = !empty($data['attribution']) ? souliong_social_wrap($data['attribution'], $font, 9, 540, 2) : [];
    $creditWidth = 0;
    foreach ($creditLines as $line) { $box = imagettfbbox(9, 0, $font, $line); $creditWidth = max($creditWidth, $box[2] - $box[0]); }
    $brandText = (string)($data['brand'] ?? 'Souliong');
    $brand = imagettfbbox(16, 0, $bold ?: $font, $brandText);
    souliong_social_pill($canvas, 18, 556, 32 + max($creditWidth, $brand[2] - $brand[0]) + 22, 556 + 28 + 14 * max(1, count($creditLines)) + 12);
    souliong_social_text_bold($canvas, $brandText, $font, $bold, 16, 32, 582, 530, 1, $accent, 20);
    if (!empty($data['attribution'])) souliong_social_text($canvas, $data['attribution'], $font, 9, 32, 603, 540, 2, $muted, 14);
    // 右緣一條粗線，顏色就是這個點位的地標色；沒有點位（專案預覽）時用主色。右側不倒圓角，粗線直接切齊邊緣
    souliong_social_round($canvas, 0, 0, 1200, 630, 24, null, false);
    $barColor = is_string($data['markerColor'] ?? null) && preg_match('/^#[0-9a-f]{6}$/iD', $data['markerColor'])
        ? imagecolorallocate($canvas, hexdec(substr($data['markerColor'], 1, 2)), hexdec(substr($data['markerColor'], 3, 2)), hexdec(substr($data['markerColor'], 5, 2))) : $accent;
    imagefilledrectangle($canvas, 1200 - 44, 0, 1199, 629, $barColor);
    ob_start(); $ok = imagejpeg($canvas, null, 88); $bytes = ob_get_clean();
    imagedestroy($canvas);
    return $ok ? $bytes : null;
}
