<?php
// 多語系：語言判斷（?lang= 手動切換 > lang cookie > 瀏覽器 Accept-Language > 預設 zh_TW）
// + t() 翻譯輔助。zh_TW 為主字典，其他語言檔只需列出已翻譯的 key，缺的 key 自動 fallback 回 zh_TW。

function i18n_supported(): array
{
    return ['zh_TW', 'en'];
}

// $persist=false 時 ?lang= 只影響本次請求、不寫 lang cookie（無狀態的嵌入頁用）
function i18n_resolve(bool $persist = true): string
{
    $supported = i18n_supported();
    $q = (string)($_GET['lang'] ?? '');
    if ($q !== '' && in_array($q, $supported, true)) {
        if ($persist) setcookie('lang', $q, time() + 60 * 60 * 24 * 365, '/');
        return $q;
    }
    $cookie = (string)($_COOKIE['lang'] ?? '');
    if ($cookie !== '' && in_array($cookie, $supported, true)) return $cookie;
    foreach (explode(',', (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $part) {
        $code = strtolower(trim(explode(';', $part)[0] ?? ''));
        if ($code === '') continue;
        if (strpos($code, 'zh') === 0) return 'zh_TW';
        if (strpos($code, 'en') === 0) return 'en';
    }
    return 'zh_TW';
}

function i18n_dict(string $lang): array
{
    $base = require __DIR__ . '/../lang/zh_TW.php';
    if ($lang === 'zh_TW') return $base;
    $file = __DIR__ . '/../lang/' . $lang . '.php';
    if (is_file($file)) return array_merge($base, require $file);
    return $base;
}

// 回傳 [語言代碼, 該語言字典]，供頁面同時取用
function i18n_init(bool $persist = true): array
{
    $lang = i18n_resolve($persist);
    return [$lang, i18n_dict($lang)];
}

function i18n_t(array $dict, string $key, array $vars = []): string
{
    $s = $dict[$key] ?? $key;
    foreach ($vars as $k => $v) $s = str_replace('{' . $k . '}', (string)$v, $s);
    return $s;
}
