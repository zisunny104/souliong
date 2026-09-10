<?php
// 投稿種類（kind）與功能使用統計（feature）的中央註冊表。
// upload.php 依此決定怎麼收檔案、stat.php 依此限制可用的 feature key、manager.php 依此產生中文標籤、
// view.php 依 tab 決定要載哪些前端型別檔、投稿對話框依 tab 分頁——四邊共用同一份定義，不必個別維護。

// 投稿種類：key => 中繼資料
//   label    後台投稿列表的種類標籤
//   tab      投稿對話框的分頁代號；null＝不出現在對話框（由專屬流程產生）
//   postable upload.php 是否接受前端直接 POST 這個 kind。**這個旗標是安全邊界，不是分類**：
//            point（定位點版本）只能由 editpoint.php 在 admin_perm() 把關後寫入，newpoint
//            由 newpoint.php 依專案設定把關；若讓它們 postable，任何人都能 POST 到
//            upload.php 偽造一筆座標覆蓋紀錄，繞過整個權限檢查。新增 kind 時預設要想清楚。
//   file     要收的 $_FILES 欄位名；null＝純文字投稿。photo 沿用歷史欄位名 'photo' 且存進
//            projects/<id>/photos/，讓 exiffix.php／thumbfix.php／editentry.php／前端的
//            photoFullUrl() 全部不用改；影音一律用 'media' 欄位、存進 projects/<id>/media/。
//   thumb    是否伴隨一張顯示用縮圖（影片由前端抽第一幀，見 assets/js/contrib/kind-video.js）
//   mimes    允許的 MIME => 副檔名。這份取代了 config 裡只服務照片的 allowed_mime。
//   max_bytes 該種類的大小上限；沒寫就用 config 的 max_bytes，config 也可用 max_bytes_<kind>
//            單獨覆寫（部署主機的實際上限還是卡在 php.ini 的 upload_max_filesize／
//            post_max_size，那是這裡改不到的）。
function souliong_kinds(): array
{
    return [
        'photo' => [
            'label' => '照片', 'tab' => 'media', 'postable' => true,
            'file' => 'photo', 'thumb' => true,
            'mimes' => ['image/webp' => 'webp', 'image/jpeg' => 'jpg', 'image/png' => 'png'],
        ],
        'video' => [
            'label' => '影片', 'tab' => 'media', 'postable' => true,
            'file' => 'media', 'thumb' => true, 'max_bytes' => 64 * 1024 * 1024,
            'mimes' => ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'],
        ],
        'audio' => [
            'label' => '音訊', 'tab' => 'audio', 'postable' => true,
            'file' => 'media', 'thumb' => false, 'max_bytes' => 24 * 1024 * 1024,
            // 這裡比對的是 finfo 從檔案內容判出來的 MIME，不是瀏覽器宣稱的 MIME，兩者常常不一樣：
            // 純音訊的 WebM／MP4 容器裡沒有視訊軌，但 finfo 只看容器格式，實測分別回報
            // video/webm 與 audio/x-m4a——MediaRecorder 的 'audio/webm;codecs=opus'（Chrome／
            // Firefox）與 'audio/mp4'（Safari）產出的正是這兩種，不一起放行的話現場錄音會被
            // 自己的白名單擋掉，iPhone 的語音備忘錄（.m4a）也傳不上來。
            // 副檔名一律照「送進來的 kind 是 audio」來給，顯示端要的是 <audio>。
            'mimes' => [
                'audio/webm' => 'weba', 'video/webm' => 'weba',
                'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'video/mp4' => 'm4a',
                'audio/mpeg' => 'mp3', 'audio/ogg' => 'ogg', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav',
            ],
        ],
        'text' => [
            'label' => '文字紀錄', 'tab' => 'text', 'postable' => true,
            'file' => null, 'thumb' => false,
        ],
        // desc 跟 text 是不同的東西，不要合併：desc 是「改寫這個地點的故事」，會取最新一筆
        // 覆蓋顯示在故事區（見 viewer.core.js 的 renderEntries()）；text 是「我留下的一則
        // 紀錄」，跟照片一樣平行地排在投稿牆上。前者由 story-editor.js 送出，不進投稿對話框。
        'desc' => [
            'label' => '地點故事版本', 'tab' => null, 'postable' => true,
            'file' => null, 'thumb' => false,
        ],
        'point' => [
            'label' => '定位點版本', 'tab' => null, 'postable' => false,
        ],
        'newpoint' => [
            'label' => '新增地點', 'tab' => 'newpoint', 'postable' => false,
        ],
    ];
}

function souliong_kind_label(string $kind): string
{
    return souliong_kinds()[$kind]['label'] ?? $kind;
}

/** upload.php 的白名單判斷（見 souliong_kinds() 的 postable 說明——這是安全邊界）。 */
function souliong_kind_postable(string $kind): bool
{
    return (bool)(souliong_kinds()[$kind]['postable'] ?? false);
}

/** 可以出現在投稿對話框、由使用者自己選擇要投什麼的內容種類（newpoint 不算，它是建立地點不是投內容）。 */
function souliong_contrib_kinds(): array
{
    $out = [];
    foreach (souliong_kinds() as $k => $info) {
        if (($info['tab'] ?? null) !== null && $k !== 'newpoint') $out[] = $k;
    }
    return $out;
}

/**
 * 解析某張地圖的投稿設定（meta.json 的 "contrib" 區塊），比照 souliong_module_on() 的精神：
 * PHP 端算一次，前端直接讀 $APP.contrib，不在兩邊各自重算預設值。
 *
 * 沒有 "contrib" 區塊的舊專案一律解析成「只有照片、不能建點」——也就是跟加入這功能之前
 * 完全一樣的行為，既有地圖不改設定檔就零變化。
 *
 * 回傳：kinds（依註冊表順序的啟用型別）、tabs（由 kinds 推導、去重後的分頁）、
 *       default（初始分頁，保證在 tabs 內）、newPoint（off｜admin｜contributor）。
 */
function souliong_contrib_cfg(?array $meta): array
{
    $all = souliong_kinds();
    $allowed = souliong_contrib_kinds();
    $want = (is_array($meta) && is_array($meta['contrib']['kinds'] ?? null))
        ? $meta['contrib']['kinds']
        : ['photo'];
    // 依註冊表順序而非 meta.json 的書寫順序，分頁排列在每張地圖才會一致
    $kinds = array_values(array_filter($allowed, fn($k) => in_array($k, $want, true)));
    if (!$kinds) $kinds = ['photo'];

    $tabs = [];
    foreach ($kinds as $k) {
        $tab = $all[$k]['tab'];
        if (!in_array($tab, $tabs, true)) $tabs[] = $tab;
    }

    $default = (string)($meta['contrib']['default'] ?? '');
    if (!in_array($default, $tabs, true)) $default = $tabs[0];

    $newPoint = (string)($meta['contrib']['newPoint'] ?? 'off');
    if (!in_array($newPoint, ['off', 'admin', 'contributor'], true)) $newPoint = 'off';

    return ['kinds' => $kinds, 'tabs' => $tabs, 'default' => $default, 'newPoint' => $newPoint];
}

// 功能使用統計：key => 後台「數字說明」區塊要顯示的中文說明
function souliong_features(): array
{
    return [
        'route'  => '路線導覽',
        'photos' => '照片瀏覽',
        'filter' => '套用篩選',
        'embed'  => '嵌入載入',
        'random' => '隨機探索',
        'upload' => '上傳投稿',
        'story'  => '地點故事',
        'theme'  => '主題切換',
        'info'   => '照片資訊',
        'share'  => '分享',
        'newpoint' => '建立地點',
    ];
}

// 每張地圖可獨立開關的功能模組：key => 後台勾選 UI 用的中繼資料。
// 這是「這張地圖要不要有這個功能」的開關（view.php 用它決定渲不渲染、viewer.core.js
// 用它決定要不要啟用行為），跟上面 souliong_features() 那份「事後統計要顯示的名稱」是兩件事。
// 未在 meta.json 出現的 key 一律視為 default 值，舊專案（meta.json 沒有 features 欄位）
// 因此完全不受影響、行為與拆分之前一致。
function souliong_modules(): array
{
    return [
        'route'  => ['label' => '路線導覽', 'desc' => '依編號的路徑導覽，及連點路線鈕的時間軸動畫彩蛋。', 'default' => true],
        'story'  => ['label' => '地點故事編輯', 'desc' => '訪客可送出新版地點故事文字（關閉後地點故事唯讀）。', 'default' => true],
        'upload' => ['label' => '上傳投稿', 'desc' => '訪客上傳照片／文字紀錄；關閉後整張地圖唯讀，投稿碼與解鎖流程一併隱藏。', 'default' => true],
        'embed'  => ['label' => '嵌入載入', 'desc' => '產生可嵌入其他網站的 iframe 碼。', 'default' => true],
        'share'  => ['label' => '分享', 'desc' => '分享連結／QR Code 彈窗。', 'default' => true],
        'homeLink' => ['label' => '回平台首頁', 'desc' => '右上角回到地圖清單的房子鈕。單獨對外掛一張地圖、不想讓訪客看到平台上其他地圖時可關閉（頁尾的來源標示不受影響）。', 'default' => true],
        'identity' => ['label' => '投稿者身分', 'desc' => '右上角身分小標籤（暱稱／管理者／匿名預覽名）與建立身分（PIN）欄位。關閉後依序探索也會一併隱藏。', 'default' => true, 'dependsOn' => 'upload'],
        'personExplore' => ['label' => '依序探索（插件）', 'desc' => '選了投稿者後，可依序探索他的地標／零散照片時間軸。', 'default' => false, 'dependsOn' => 'identity'],
        'delegation' => ['label' => '管理者邀請登入', 'desc' => '地圖頁上的管理者登入／邀請兌換彈窗。關閉後這張地圖不再產生新的專案 PIN 或邀請連結，只能用主 PIN 從後台網址（/manager）登入管理，適合純檢視、僅超級管理者更新內容的部署。', 'default' => true],
        'map3d'  => ['label' => '3D 地圖模式', 'desc' => '訪客可切換到 MapLibre 3D 檢視（公用建物擠出＋自訂模型）。關閉後只有既有 Leaflet 2D 地圖，不載入 MapLibre。', 'default' => false],
    ];
}

// $meta 是該專案 meta.json 解析後的陣列（可能是 null）。
// personExplore 沿用既有的扁平旗標寫法（$meta['personExplore']），其餘模組存在 $meta['features'][$key]。
function souliong_module_on(?array $meta, string $key): bool
{
    $dep = souliong_modules()[$key]['dependsOn'] ?? null;
    if ($dep !== null && !souliong_module_on($meta, $dep)) {
        return false;
    }
    $def = souliong_modules()[$key]['default'] ?? true;
    if (!is_array($meta)) {
        return $def;
    }
    if ($key === 'personExplore') {
        return (bool)($meta['personExplore'] ?? $def);
    }
    if (isset($meta['features'][$key])) {
        return (bool)$meta['features'][$key];
    }
    return $def;
}
