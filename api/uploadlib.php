<?php
// 上傳檔案共用邏輯（純函式，無副作用，可安全被多處 require）：從 api/upload.php 抽出的 MIME 偵測與
// 檔案驗證／命名／落地，供 api/upload.php（投稿牆五種型別）與 api/spotcontent.php（點位原生內容）
// 共用同一套規則。縮圖產生留在 upload.php——那是投稿牆卡片列表的顯示需求，點位原生內容沒有縮圖。
require_once __DIR__ . '/store.php';

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
function uploadlib_store_file(array $cfg, string $project, array $file, string $subdir, array $mimes, int $maxBytes, ?int $tsHint = null): array {
    if ($file['size'] > $maxBytes) {
        json_out(['error' => 'file too large'], 413);
    }
    $mime = detect_mime($file['tmp_name']);
    if (!isset($mimes[$mime])) {
        json_out(['error' => 'unsupported type: ' . ($mime === '' ? '(unknown)' : $mime)], 415);
    }
    $ext = $mimes[$mime];
    $destDir = project_dir($cfg, $project) . '/' . $subdir;
    if (!is_dir($destDir)) { @mkdir($destDir, 0775, true); }
    $fbase = date('Ymd_His', $tsHint ?? time()) . '_' . bin2hex(random_bytes(4));
    $fname = $fbase . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $destDir . '/' . $fname)) {
        json_out(['error' => 'save failed'], 500);
    }
    return ['rel' => $project . '/' . $fname, 'mime' => $mime, 'fbase' => $fbase];
}
