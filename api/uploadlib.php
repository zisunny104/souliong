<?php
// 上傳檔案共用邏輯（純函式，無副作用，可安全被多處 require）：從 api/upload.php 抽出的 MIME 偵測與
// 檔案驗證／命名／落地，供 api/upload.php（投稿牆五種型別）與 api/spotcontent.php（地點原生內容）
// 共用同一套規則。縮圖產生留在 upload.php——那是投稿牆卡片列表的顯示需求，地點原生內容沒有縮圖。
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/features.php';

/**
 * 判斷上傳檔的實際 MIME。圖片優先用 getimagesize()（不依賴 fileinfo 擴充，較可攜）；影音沒有
 * 等價的可攜函式，只能靠 finfo，主機沒裝 fileinfo 擴充時影音就一律收不了。
 * 這是刻意的：絕對不能改用 $_FILES['type']，那個值由瀏覽器（也就是投稿者）說了算、可任意偽造，
 * 拿它當白名單等於沒有白名單。
 */
function detect_mime(string $tmp): string {
    $info = @getimagesize($tmp);
    if (is_array($info) && !empty($info['mime'])) return (string)$info['mime'];
    if (class_exists('finfo')) {
        $m = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (is_string($m) && $m !== '') return $m;
    }
    return '';
}

/**
 * 驗證＋落地單一上傳檔到 projects/<project>/<subdir>/：大小上限、MIME 白名單比對（見 detect_mime()
 * 為何不信任 $_FILES['type']）、命名（<時間>_<隨機碼>.<副檔名>，時間優先用 $tsHint，取不到才用
 * 當下時間）。驗證失敗直接 json_out() 中止整個請求，跟既有 upload.php 的行為一致。
 * 回傳 ['rel' => '<project>/<檔名>', 'mime' => 實際偵測到的 MIME, 'fbase' => 不含副檔名的檔名主體]；
 * $fbase 供呼叫端自己另外存縮圖（<fbase>_t.<ext>），沿用同一組命名規則。
 */
/** php.ini 的大小設定換成位元組；0 或空值是「不限制」，回 PHP_INT_MAX。 */
function uploadlib_ini_bytes(string $key): int {
    $v = trim((string)ini_get($key));
    $mul = ['k' => 1024, 'm' => 1048576, 'g' => 1073741824][strtolower(substr($v, -1))] ?? 1;
    $n = (int)((float)$v * $mul);
    return $n > 0 ? $n : PHP_INT_MAX;
}

/**
 * 前端預檢用的上傳上限（位元組；不限制的項目為 null）：
 *   total  單次請求的總上限＝post_max_size、upload_max_filesize、max_bytes 的最小值
 *   post   post_max_size：同一次請求裡所有檔案加表單欄位的總和不能超過
 *   file   單一檔案的伺服器上限＝min(upload_max_filesize, post_max_size)
 *   kinds  各型別單檔的實際上限（該型別自己的大小上限與 file 取小）
 */
function uploadlib_limits(array $cfg): array {
    $post = uploadlib_ini_bytes('post_max_size');
    $file = min(uploadlib_ini_bytes('upload_max_filesize'), $post);
    $nul = fn(int $n) => $n === PHP_INT_MAX ? null : $n;
    $kinds = [];
    foreach (souliong_kinds() as $k => $def) {
        if (empty($def['file'])) continue;
        $kinds[$k] = $nul(min((int)($cfg['max_bytes_' . $k] ?? $def['max_bytes'] ?? $cfg['max_bytes']), $file));
    }
    return [
        'total' => $nul(min($post, $file, (int)$cfg['max_bytes'])),
        'post'  => $nul($post),
        'file'  => $nul($file),
        'kinds' => $kinds,
    ];
}

function uploadlib_too_large_message(int $bytes): string {
    require_once __DIR__ . '/i18n.php';
    return i18n_t(i18n_dict(i18n_resolve()), 'upload_too_large', ['mb' => max(1, (int)floor($bytes / 1048576))]);
}

/**
 * 請求本體超過 post_max_size 時 PHP 會把 $_POST／$_FILES 整個清空，端點看起來像沒帶任何欄位。
 * 在讀任何欄位之前先擋，回明確的 413，而不是讓後面的驗證回一個誤導的 400。
 */
function uploadlib_reject_oversized_request(): void {
    $post = uploadlib_ini_bytes('post_max_size');
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($post !== PHP_INT_MAX && $len > $post) {
        json_out(['error' => uploadlib_too_large_message($post), 'code' => 'too_large', 'max_bytes' => $post], 413);
    }
}

/** 單一上傳檔是否被 upload_max_filesize／表單上限擋下（$_FILES[x]['error']）。 */
function uploadlib_file_too_large(array $file): bool {
    return in_array($file['error'] ?? UPLOAD_ERR_OK, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
}

/**
 * 伺服器端縮小過大的照片：超過位元組門檻或長邊上限時，依 EXIF 方向轉正、縮到長邊上限，
 * 再以 WebP（沒有就 JPEG）逐步降品質到門檻以下。內嵌 EXIF 會隨重新編碼消失（方向已轉正），
 * 投稿的 exif 欄位是前端另外送、存在紀錄裡，不受影響。任何一步不確定（動圖、含透明又只能轉 JPEG、
 * 讀不了方向、結果沒有變小）就回 null，呼叫端照原檔存，不報錯。
 * 成功回傳 ['tmp' => 新暫存檔, 'mime' => ..., 'ext' => ...]，呼叫端負責搬走或刪除 tmp。
 */
function uploadlib_compress_photo(array $cfg, string $src, string $mime, array $allowed): ?array {
    if (!($cfg['compress_photo'] ?? true) || !function_exists('imagecreatetruecolor')) return null;
    $limitBytes = (int)($cfg['compress_photo_bytes'] ?? 1572864);
    $maxDim     = max(320, (int)($cfg['compress_photo_max_dim'] ?? 2560));
    $info = @getimagesize($src);
    $size = @filesize($src);
    if (!is_array($info) || $size === false) return null;
    [$w, $h] = $info;
    if ($w < 1 || $h < 1 || $w * $h > 64000000) return null;
    if ($size <= $limitBytes && max($w, $h) <= $maxDim) return null;

    $raw = @file_get_contents($src);
    if ($raw === false) return null;
    if ($mime === 'image/webp' && strpos(substr($raw, 0, 4096), 'ANIM') !== false) return null;
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return null;

    $orient = 1;
    if ($mime === 'image/jpeg') {
        if (!function_exists('exif_read_data')) return null;
        $ex = @exif_read_data($src);
        if (is_array($ex) && isset($ex['Orientation'])) $orient = (int)$ex['Orientation'];
    }
    if (in_array($orient, [2, 4, 5, 7], true)) return null;
    $img = @imagecreatefromstring($raw);
    unset($raw);
    if (!$img) return null;

    $hasAlpha = false;
    if ($mime === 'image/png') {
        $head = (string)@file_get_contents($src, false, null, 0, 8192);
        $ct = ord($head[25] ?? "\0");
        $hasAlpha = $ct === 4 || $ct === 6 || strpos($head, 'tRNS') !== false;
    } elseif ($mime === 'image/webp') {
        $hasAlpha = true;
    }
    $useWebp = isset($allowed['image/webp']) && function_exists('imagewebp');
    if (!$useWebp && (!isset($allowed['image/jpeg']) || $hasAlpha)) { imagedestroy($img); return null; }

    $rot = [3 => 180, 6 => -90, 8 => 90][$orient] ?? 0;
    if ($rot) { $r = @imagerotate($img, $rot, 0); if ($r) { imagedestroy($img); $img = $r; } }

    $cw = imagesx($img); $ch = imagesy($img);
    $scale = min(1.0, $maxDim / max($cw, $ch));
    $out = tempnam(sys_get_temp_dir(), 'ulc');
    $best = null;
    for ($round = 0; $round < 4 && $best === null; $round++) {
        $tw = max(1, (int)round($cw * $scale)); $th = max(1, (int)round($ch * $scale));
        $dst = imagecreatetruecolor($tw, $th);
        if ($useWebp || $hasAlpha) {
            imagealphablending($dst, false); imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        } else {
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        }
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $tw, $th, $cw, $ch);
        foreach ([85, 78, 70, 62, 55] as $q) {
            $ok = $useWebp ? @imagewebp($dst, $out, $q) : @imagejpeg($dst, $out, $q);
            clearstatcache(true, $out);
            if ($ok && filesize($out) <= $limitBytes) { $best = true; break; }
        }
        imagedestroy($dst);
        $scale *= 0.8;
    }
    imagedestroy($img);
    clearstatcache(true, $out);
    if (!$best || filesize($out) >= $size) { @unlink($out); return null; }
    return $useWebp ? ['tmp' => $out, 'mime' => 'image/webp', 'ext' => $allowed['image/webp']]
                    : ['tmp' => $out, 'mime' => 'image/jpeg', 'ext' => $allowed['image/jpeg']];
}

/** 主機有 ffmpeg 才做的影音重新編碼；沒有、逾時、失敗、沒變小都回 null（照原檔存）。 */
function uploadlib_ffmpeg_bin(array $cfg): ?string {
    static $bin = false;
    if ($bin !== false) return $bin;
    $bin = null;
    if (!function_exists('proc_open')) return null;
    $cand = (string)($cfg['ffmpeg_bin'] ?? 'ffmpeg');
    $p = @proc_open([$cand, '-version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) return null;
    stream_get_contents($pipes[1]);
    foreach ($pipes as $pp) fclose($pp);
    if (proc_close($p) === 0) $bin = $cand;
    return $bin;
}

function uploadlib_compress_media(array $cfg, string $src, string $kind, array $allowed): ?array {
    if (!($cfg['compress_media'] ?? true)) return null;
    $isVideo = ($kind === 'video');
    if (!$isVideo && $kind !== 'audio') return null;
    $size = @filesize($src);
    if ($size === false || $size <= (int)($cfg[$isVideo ? 'compress_video_bytes' : 'compress_audio_bytes'] ?? ($isVideo ? 16 : 4) * 1048576)) return null;
    $mime = $isVideo ? 'video/mp4' : 'audio/mpeg';
    if (!isset($allowed[$mime])) return null;
    $bin = uploadlib_ffmpeg_bin($cfg);
    if ($bin === null) return null;
    $out = tempnam(sys_get_temp_dir(), 'ulm');
    $args = $isVideo
        ? [$bin, '-y', '-i', $src, '-vf', "scale='min(1280,iw)':-2", '-c:v', 'libx264', '-crf', '28', '-preset', 'veryfast', '-c:a', 'aac', '-b:a', '96k', '-movflags', '+faststart', '-f', 'mp4', $out]
        : [$bin, '-y', '-i', $src, '-vn', '-c:a', 'libmp3lame', '-b:a', '96k', '-f', 'mp3', $out];
    $p = @proc_open($args, [1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']], $pipes);
    if (!is_resource($p)) { @unlink($out); return null; }
    $deadline = microtime(true) + (int)($cfg['compress_media_timeout'] ?? 90);
    $code = -1;
    while (true) {
        $st = proc_get_status($p);
        if (!$st['running']) { $code = $st['exitcode']; break; }
        if (microtime(true) > $deadline) { proc_terminate($p); proc_close($p); @unlink($out); return null; }
        usleep(100000);
    }
    proc_close($p);
    clearstatcache(true, $out);
    if ($code !== 0 || filesize($out) < 1 || filesize($out) >= $size) { @unlink($out); return null; }
    return ['tmp' => $out, 'mime' => $mime, 'ext' => $allowed[$mime]];
}

function uploadlib_store_file(array $cfg, string $project, array $file, string $subdir, array $mimes, int $maxBytes, ?int $tsHint = null, string $kind = ''): array {
    if ($file['size'] > $maxBytes) {
        json_out(['error' => uploadlib_too_large_message($maxBytes), 'code' => 'too_large', 'max_bytes' => $maxBytes], 413);
    }
    $mime = detect_mime($file['tmp_name']);
    if (!isset($mimes[$mime])) {
        json_out(['error' => 'unsupported type: ' . ($mime === '' ? '(unknown)' : $mime)], 415);
    }
    $ext = $mimes[$mime];
    $packed = $subdir === 'photos'
        ? uploadlib_compress_photo($cfg, $file['tmp_name'], $mime, $mimes)
        : uploadlib_compress_media($cfg, $file['tmp_name'], $kind, $mimes);
    if ($packed) { $mime = $packed['mime']; $ext = $packed['ext']; }
    $destDir = project_dir($cfg, $project) . '/' . $subdir;
    if (!is_dir($destDir)) { @mkdir($destDir, 0775, true); }
    $fbase = date('Ymd_His', $tsHint ?? time()) . '_' . bin2hex(random_bytes(4));
    $fname = $fbase . '.' . $ext;
    $moved = $packed
        ? @rename($packed['tmp'], $destDir . '/' . $fname) || (@copy($packed['tmp'], $destDir . '/' . $fname) && @unlink($packed['tmp']))
        : move_uploaded_file($file['tmp_name'], $destDir . '/' . $fname);
    if (!$moved) {
        if ($packed) @unlink($packed['tmp']);
        json_out(['error' => 'save failed'], 500);
    }
    return ['rel' => $project . '/' . $fname, 'mime' => $mime, 'fbase' => $fbase, 'compressed' => (bool)$packed];
}
