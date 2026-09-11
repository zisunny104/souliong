<?php
// 範本：部署時複製成 config.php 並填入你的機密值（config.php 已被 .gitignore）
return [
    'projects_dir'    => __DIR__ . '/../projects',
    'state_dir'       => __DIR__ . '/../state',
    'packs_dir'       => __DIR__ . '/../packs',              // 主題包（面板材質／外框／字體），每個子目錄一包
    'layers_dir'      => __DIR__ . '/../layers',             // 平台內建地圖圖層（底圖／疊圖），每個子目錄一層；某張地圖專屬的圖層放 projects/<id>/layers/
    'default_layers'  => ['paper-ink'],                       // meta.json 沒寫 layers 時套用的圖層組，由下往上疊
    'layersrc_max_file'  => 64 * 1024 * 1024,                // 切磚工具「保留原稿」的單檔上限
    'layersrc_max_total' => 256 * 1024 * 1024,               // 同上，單一圖層所有原稿加起來的上限

    // 3D 地圖模式：公用建物擠出圖磚服務（見 api/regions3d.php、assets/js/plugins/map3d.js）。
    // OpenFreeMap 免金鑰即可用；換成 MapTiler／Stadia 之類需要 key 的服務時，改這三個值即可，
    // 前端與 region3d.php 都不必動——provider 的形狀（OpenMapTiles schema）看 view.php 怎麼組 APP.map3d。
    'map3d_provider'   => 'openfreemap',
    'map3d_style_url'  => 'https://tiles.openfreemap.org/styles/liberty', // liberty 內建 building-3d fill-extrusion 圖層
    'map3d_key'        => '',
    'model3d_max_bytes' => 24 * 1024 * 1024,                 // 單一自訂模型（.glb）上限
    'max_bytes'       => 12 * 1024 * 1024,
    'allowed_mime'    => ['image/webp' => 'webp', 'image/jpeg' => 'jpg', 'image/png' => 'png'],
    'name_max'        => 60,
    'comment_max'     => 1000,

    // 專案封面／地圖快照（api/cover.php）
    'cover_min_interval' => 3600,   // 自動快照最短重生間距（秒）
    'cover_max_dim'      => 960,    // 封面圖最長邊上限（px）

    // 管理 PIN（人輸入；驗證後以 httpOnly cookie 保持登入，PIN 不進網址）
    'admin_pin'       => 'CHANGE-ME',
    'admin_pin_label' => '',   // 用這把主 PIN 登入後要帶入的顯示名稱（留空則顯示「管理者」）

    // 韌性 / 資安
    'rate_max'        => 40,      // 每 IP 於視窗內最多寫入次數（預設；刪除/換鎖等低頻動作用這個）
    'rate_window'     => 60,      // 視窗秒數
    'rate_limits'     => [        // 個別動作覆寫預設值；上傳是批次投稿常態，量遠高於刪除/換鎖，需要獨立且更高的上限
        'upload' => ['max' => 300, 'window' => 60],
        'admin'  => ['max' => 120, 'window' => 60],   // 後台頁面＋臨時工具共用同一個 bucket，管理者密集操作/測試時預設 40 太容易誤擋自己
    ],
    'trust_forwarded' => false,   // ★ 位於 Nginx 反代後請設 true
    'debug'           => true,    // ★ 上線穩定後設 false

    // 冒名鑑識：加鹽 IP 雜湊（僅管理端可見）
    'log_src'         => true,
    'ip_salt'         => 'CHANGE-ME-隨機鹽值',
];
