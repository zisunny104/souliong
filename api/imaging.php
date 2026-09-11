<?php
/**
 * 共用 GD 影像處理：解碼（檔案／二進位皆可）＋縮放＋編碼＋原子寫檔。
 * 縮圖（photo.php）與封面圖（cover.php）共用同一套邏輯，只有輸出尺寸與檔名不同。
 */

/** 從二進位內容解出 GD 資源＋原始尺寸；非圖片／GD 不支援該格式／圖太大（DoS 防護）一律回 null。 */
function souliong_image_decode_bytes(string $bytes): ?array {
    if (!function_exists('imagecreatefromstring')) return null;
    $info = @getimagesizefromstring($bytes);
    // 防止對超大圖解壓（GD 會整張展開進記憶體）；正常投稿/擷圖都遠低於這個門檻
    if (!is_array($info) || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 40000000) return null;
    $img = @imagecreatefromstring($bytes);
    if (!$img) return null;
    return [$img, $info[0], $info[1]];
}

/** 同 souliong_image_decode_bytes()，來源是檔案路徑。讀檔失敗也回 null。 */
function souliong_image_decode_file(string $path): ?array {
    $bytes = @file_get_contents($path);
    return $bytes === false ? null : souliong_image_decode_bytes($bytes);
}

/**
 * 縮放（最長邊 $maxDim，不放大）＋編碼（webp 優先，無 webp 支援退回 jpg，皆無則回 null）＋
 * 原子寫檔（先寫暫存檔再 rename，避免併發請求互吃半成品）。
 * $src 是呼叫端已解碼好的 GD 資源，這支不負責釋放它。輸出檔名＝$outBase 加對應副檔名。
 */
function souliong_image_resize_encode($src, int $w, int $h, int $maxDim, string $outBase): ?string {
    if (max($w, $h) > $maxDim) {
        $s = $maxDim / max($w, $h);
        $tw = max(1, (int)round($w * $s));
        $th = max(1, (int)round($h * $s));
    } else {
        $tw = $w;
        $th = $h;
    }
    $dst = imagecreatetruecolor($tw, $th);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));   // 透明背景壓白底（縮圖/封面僅供顯示）
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
    if (function_exists('imagewebp')) {
        $out = $outBase . '.webp';
        $write = fn(string $p): bool => imagewebp($dst, $p, 78);
    } elseif (function_exists('imagejpeg')) {
        $out = $outBase . '.jpg';
        $write = fn(string $p): bool => imagejpeg($dst, $p, 80);
    } else {
        imagedestroy($dst);
        return null;
    }
    $tmp = $out . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $ok = @$write($tmp);
    imagedestroy($dst);
    if (!$ok || !@rename($tmp, $out)) {
        @unlink($tmp);
        return null;
    }
    return $out;
}
