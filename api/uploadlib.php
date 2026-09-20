<?php
// 上傳檔案共用邏輯（純函式，無副作用，可安全被多處 require）：從 api/upload.php 抽出的 MIME 偵測與
// 檔案驗證／命名／落地，供 api/upload.php（投稿牆五種型別）與 api/spotcontent.php（點位原生內容）
// 共用同一套規則。縮圖產生留在 upload.php——那是投稿牆卡片列表的顯示需求，點位原生內容沒有縮圖。
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
 *   kinds  各種類單檔的實際上限（該種類自己的大小上限與 file 取小）
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

function uploadlib_store_file(array $cfg, string $project, array $file, string $subdir, array $mimes, int $maxBytes, ?int $tsHint = null): array {
    if ($file['size'] > $maxBytes) {
        json_out(['error' => uploadlib_too_large_message($maxBytes), 'code' => 'too_large', 'max_bytes' => $maxBytes], 413);
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
