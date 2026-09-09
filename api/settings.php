<?php
// 平台全域設定（跨地圖，不屬於任何單一 project，所以不放 meta.json）。
// 存在 state/settings.json，比照 security.php 的 admin_pins.json 讀寫模式。
// 目前唯一用到的旗標：首頁「隨機探索」按鈕（從所有地圖裡隨機挑一張跳轉，是首頁層級的功能）。

function souliong_settings_file(array $cfg): string
{
    return rtrim($cfg['state_dir'], '/\\') . '/settings.json';
}

function souliong_settings_load(array $cfg): array
{
    $d = is_file(souliong_settings_file($cfg)) ? json_decode((string)@file_get_contents(souliong_settings_file($cfg)), true) : null;
    return is_array($d) ? $d : [];
}

function souliong_settings_save(array $cfg, array $d): void
{
    @file_put_contents(souliong_settings_file($cfg), json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

// 沒存過（舊安裝）一律視為開啟，行為與拆分之前一致。
function souliong_random_explore_on(array $cfg): bool
{
    $s = souliong_settings_load($cfg);
    return (bool)($s['random_explore'] ?? true);
}

// 全站預設主題包（包 id；空字串＝沒設，維持黑白預設）。
// 主題包「以專案為主」：地圖自己的 meta.json 選了什麼就是什麼，沒選過的才落到這個全站預設。
// 完整解析順序寫在 packs.php 的 souliong_pack_for()。
function souliong_site_pack(array $cfg): string
{
    $s = souliong_settings_load($cfg);
    return is_string($s['pack'] ?? null) ? $s['pack'] : '';
}

// 帳號自助註冊開關：預設關閉（只能靠 primary 產生的一次性轉換/邀請連結建帳號），
// 避免公開註冊表單被拿去暴力灌帳號；primary 需要時才手動開，用完建議關回去。
function souliong_registration_open(array $cfg): bool
{
    $s = souliong_settings_load($cfg);
    return (bool)($s['registration_open'] ?? false);
}
