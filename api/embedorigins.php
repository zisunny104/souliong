<?php
/**
 * 允許嵌入的來源清單：全站唯一一份解析與驗證，CORS、frame-ancestors、postMessage 白名單都從這裡取。
 * 清單由全站與專案兩層取聯集，預設為空，空清單不開放任何來源。
 * 只接受 https://host[:port]，本機開發另可用 http://localhost。
 */

/** 本機開發主機：唯一允許 http 的主機名 */
function embed_origin_dev_host(string $host): bool
{
    return $host === 'localhost' || $host === '127.0.0.1';
}

/**
 * 單一來源字串轉成標準形式（小寫、省略預設埠）；格式不合法回 null。
 * 容許結尾一個斜線（常見貼上習慣），其餘路徑一律拒絕。
 */
function embed_origin_normalize(string $raw): ?string
{
    $s = trim($raw);
    if (substr($s, -1) === '/') $s = substr($s, 0, -1);
    if (!preg_match('#^(https?)://([A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?)(?::([0-9]{1,5}))?$#i', $s, $m)) return null;
    $scheme = strtolower($m[1]);
    $host = strtolower($m[2]);
    if (strpos($host, '..') !== false) return null;
    $port = $m[3] ?? '';
    if ($port !== '') {
        $n = (int)$port;
        if ($n < 1 || $n > 65535) return null;
        $port = (string)$n;
    }
    if ($scheme === 'http' && !embed_origin_dev_host($host)) return null;
    if ($scheme === 'https' && $port === '443') $port = '';
    if ($scheme === 'http' && $port === '80') $port = '';
    return $scheme . '://' . $host . ($port !== '' ? ':' . $port : '');
}

/**
 * 解析使用者輸入（字串以空白／逗號／分號／換行分隔，或陣列）。
 * 回傳 ['valid' => 去重後的標準來源, 'invalid' => 原樣的不合法項目]。
 */
function embed_origins_parse($input): array
{
    $items = is_array($input) ? $input : preg_split('/[\s,;]+/', (string)$input, -1, PREG_SPLIT_NO_EMPTY);
    $valid = [];
    $invalid = [];
    foreach ($items as $it) {
        if (!is_string($it) || trim($it) === '') continue;
        $n = embed_origin_normalize($it);
        if ($n === null) {
            $invalid[] = $it;
        } elseif (!in_array($n, $valid, true)) {
            $valid[] = $n;
        }
    }
    return ['valid' => $valid, 'invalid' => $invalid];
}

/** 全站層清單檔，由 tools/embed_allow.php 維護。 */
function embed_origins_file(array $cfg): string
{
    return rtrim((string)($cfg['state_dir'] ?? ''), '/\\') . '/embed_origins.json';
}

/** 全站層清單：設定與清單檔取聯集，不合法項目一律忽略。 */
function embed_origins_site(array $cfg): array
{
    $fromFile = [];
    if (!empty($cfg['state_dir'])) {
        $decoded = json_decode((string)@file_get_contents(embed_origins_file($cfg)), true);
        if (is_array($decoded)) $fromFile = $decoded;
    }
    $fromCfg = $cfg['embed_allowed_origins'] ?? [];
    if (is_string($fromCfg)) $fromCfg = [$fromCfg];
    return embed_origins_parse(array_merge((array)$fromCfg, $fromFile))['valid'];
}

/** 專案層清單（meta.json 的 embedOrigins）。 */
function embed_origins_project(?array $meta): array
{
    return embed_origins_parse($meta['embedOrigins'] ?? [])['valid'];
}

/** 這張地圖實際生效的允許清單：全站＋專案聯集；$meta 為 null 時只有全站層。 */
function embed_origins_allowed(array $cfg, ?array $meta = null): array
{
    return array_values(array_unique(array_merge(embed_origins_site($cfg), embed_origins_project($meta))));
}

/** 請求的 Origin 標頭若在清單內，回傳標準化後的來源，否則 null。 */
function embed_origin_match(?string $origin, array $allowed): ?string
{
    if ($origin === null || $origin === '' || !$allowed) return null;
    $n = embed_origin_normalize($origin);
    return ($n !== null && in_array($n, $allowed, true)) ? $n : null;
}

/** 資料 API 的 CORS 標頭：只有來源命中清單才放行，回傳是否命中。 */
function embed_send_cors(array $allowed): bool
{
    header('Vary: Origin');
    $hit = embed_origin_match($_SERVER['HTTP_ORIGIN'] ?? null, $allowed);
    if ($hit === null) return false;
    header('Access-Control-Allow-Origin: ' . $hit);
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: If-None-Match');
    header('Access-Control-Expose-Headers: ETag');
    header('Access-Control-Max-Age: 600');
    return true;
}

/** 清單非空時改送 frame-ancestors 並移除 PHP 端的 X-Frame-Options，回傳是否有送出。 */
function embed_send_frame_headers(array $allowed): bool
{
    if (!$allowed) return false;
    header_remove('X-Frame-Options');
    header("Content-Security-Policy: frame-ancestors 'self' " . implode(' ', $allowed));
    return true;
}
