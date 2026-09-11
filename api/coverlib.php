<?php
/**
 * 封面／地圖快照的檔案與 meta.json 操作邏輯。api/cover.php（前端自動快照與上傳走的 JSON 端點）
 * 與 api/manager.php（後台「封面圖片」對話框的手動上傳／重設）共用同一套，只有呼叫端的
 * 回應格式（JSON 或後台導轉）不同。
 */
require_once __DIR__ . '/imaging.php';

/** 目前實際存在的封面檔路徑（webp 優先），沒有就回 null。 */
function cover_file_of(string $coverBase): ?string
{
    foreach (['webp', 'jpg'] as $ext) {
        if (is_file($coverBase . '.' . $ext)) return $coverBase . '.' . $ext;
    }
    return null;
}

/** 寫入前清掉另一種副檔名的舊檔，避免 webp/jpg 兩份同時存在時舊圖仍被 cover_file_of() 挑到。 */
function cover_purge_other(string $coverBase, string $keep): void
{
    foreach (['webp', 'jpg'] as $ext) {
        $p = $coverBase . '.' . $ext;
        if ($p !== $keep) @unlink($p);
    }
}

/** 寫回 meta.json；JSON 編碼失敗絕不覆寫既有檔案。 */
function cover_save_meta(string $mf, array $meta): void
{
    $json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json !== false && is_dir(dirname($mf))) @file_put_contents($mf, $json, LOCK_EX);
}

/**
 * 把一份圖片位元組存成封面。$mode 由呼叫端決定：前台自動快照存 'auto'，管理者上傳（不論
 * 前台自動呼叫還是後台手動上傳）一律存 'custom'——之後 auto 快照就永遠不再覆寫這張。
 * 成功回 ['ok'=>true,'cover'=>['mode'=>...,'updatedAt'=>...]]；失敗回 ['ok'=>false,'error'=>string]。
 */
function cover_apply_bytes(array $cfg, string $coverBase, string $mf, array $meta, string $bytes, string $mode): array
{
    $d = souliong_image_decode_bytes($bytes);
    if ($d === null) return ['ok' => false, 'error' => 'unsupported image'];
    [$src, $w, $h] = $d;
    $out = souliong_image_resize_encode($src, $w, $h, (int)($cfg['cover_max_dim'] ?? 960), $coverBase);
    imagedestroy($src);
    if ($out === null) return ['ok' => false, 'error' => 'save failed'];
    cover_purge_other($coverBase, $out);
    $meta['cover'] = ['mode' => $mode, 'updatedAt' => gmdate('c')];
    cover_save_meta($mf, $meta);
    return ['ok' => true, 'cover' => $meta['cover']];
}

/** 清空封面，卡片退回預設圖示。 */
function cover_apply_reset(string $coverBase, string $mf, array $meta): array
{
    foreach (['webp', 'jpg'] as $ext) @unlink($coverBase . '.' . $ext);
    unset($meta['cover']);
    cover_save_meta($mf, $meta);
    return ['ok' => true, 'cover' => null];
}
