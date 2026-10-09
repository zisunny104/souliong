<?php
/** 儲存前核對專案設定版本，避免較舊表單覆蓋新設定。 */
function project_meta_write(string $path, string $json, string $revision): bool {
    $fp = fopen($path, 'c+b');
    if (!$fp) throw new RuntimeException('Cannot open project settings');
    try {
        if (!flock($fp, LOCK_EX)) throw new RuntimeException('Cannot lock project settings');
        $current = stream_get_contents($fp);
        if (!hash_equals($revision, hash('sha256', $current))) return false;
        rewind($fp);
        if (!ftruncate($fp, 0) || fwrite($fp, $json) !== strlen($json) || !fflush($fp)) {
            throw new RuntimeException('Cannot write project settings');
        }
        return true;
    } finally { flock($fp, LOCK_UN); fclose($fp); }
}
