<?php
// 地圖圖層（底圖／疊圖）註冊表。跟 packs.php 是同一套精神——註冊表就是圖層目錄底下的
// 資料夾本身，沒有中央 index 檔，新增一層只要新增一個資料夾。
//
// 跟主題包的差別在「數量與順序」：一張地圖只套一個 pack，卻可以疊好幾層圖層，而且由下往上的
// 順序有意義，所以 meta.json 存的是有序陣列 "layers": ["paper-ink", "chungshing-art"]。
// 沒有這個欄位 → 退回 config 的 default_layers，舊地圖行為與拆分之前完全一致。
//
// 為什麼底圖不做成插件（見 docs/EXTENDING.md 第七節的判準）：插件是「可以整包關掉、關掉後
// 核心不認得它」的功能，底圖關掉地圖就沒了。它的形狀跟 pack 一樣是「用哪一包」而非布林開關。
//
// ── 兩層作用域 ──
// site    : <app>/layers/<id>/          平台內建、所有地圖共用，隨程式碼進版控
// project : projects/<proj>/layers/<id>/  這張地圖專屬（自繪插畫多半屬於這類）
//
// 專案層之所以放在 projects/ 底下，是因為那整棵目錄本來就在 .gitignore：自繪插畫、切好的
// 圖磚金字塔這種「內容而非程式」的檔案因此天然不進版控，不必為了體積另立規則。同名時專案層
// 覆蓋全站層，讓單一地圖能在不影響其他地圖的前提下改掉內建圖層。
//
// 圖檔怎麼送出去：url 若是相對路徑（不含 "://"），代表圖檔就放在該層自己的資料夾裡，由
// <base>/layer/<project>/<id>/<路徑> 端點輸出——框架不供應靜態檔，理由同 api/photo.php。
// 全站層也走同一條網址，<project> 只是決定解析範圍。反過來說，url 直接指向外部圖磚服務的
// 圖層（國土測繪中心、Esri…）一個檔案都不落地，自然也沒有檔案數量的問題。

/** 平台內建 layers/ 目錄；layers_dir 沒設也要能運作（舊部署的 api/config.php 不會有這個 key）。 */
function souliong_layers_dir(array $cfg): string
{
    $dir = (string)($cfg['layers_dir'] ?? '');
    return rtrim($dir !== '' ? $dir : __DIR__ . '/../layers', "/\\");
}

/**
 * 圖層搜尋路徑，由低優先到高優先（後面的同名 id 覆蓋前面的）。
 * 回傳 [ scope => 絕對路徑 ]，scope 為 'site' 或 'project'。
 */
function souliong_layer_roots(array $cfg, string $proj = ''): array
{
    $roots = [];
    $site = souliong_layers_dir($cfg);
    if ($site !== '') {
        $roots['site'] = $site;
    }
    $pdir = rtrim((string)($cfg['projects_dir'] ?? ''), "/\\");
    if ($proj !== '' && $pdir !== '' && preg_match('/^[a-z0-9_-]+$/', $proj)) {
        $roots['project'] = $pdir . '/' . $proj . '/layers';
    }
    return $roots;
}

/**
 * 掃所有搜尋路徑，回傳 [ id => layer.json 解析後的陣列 ]；沒有合法 layer.json 就跳過。
 * manifest 會被補上 id 與 scope 兩個欄位，端點靠 scope 才知道該去哪個目錄拿圖檔。
 */
function souliong_layer_list(array $cfg, string $proj = ''): array
{
    $out = [];
    foreach (souliong_layer_roots($cfg, $proj) as $scope => $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        foreach (scandir($dir) ?: [] as $id) {
            if ($id === '.' || $id === '..' || !preg_match('/^[a-z0-9_-]+$/', $id)) {
                continue;
            }
            $mf = $dir . '/' . $id . '/layer.json';
            if (!is_file($mf)) {
                continue;
            }
            $manifest = json_decode((string)@file_get_contents($mf), true);
            if (!is_array($manifest)) {
                continue;
            }
            // 資料夾名稱與所在作用域才是真的，layer.json 內容不可覆寫（匯入時避免偽造）
            $manifest['id'] = $id;
            $manifest['scope'] = $scope;
            $out[$id] = $manifest;   // 專案層排在後面，同名時自然覆蓋全站層
        }
    }
    return $out;
}

/** 單層的資料夾絕對路徑（專案層優先）；id 不合法或哪邊都沒有回 null。 */
function souliong_layer_dir(array $cfg, string $id, string $proj = ''): ?string
{
    if (!preg_match('/^[a-z0-9_-]+$/', $id)) {
        return null;
    }
    $found = null;
    foreach (souliong_layer_roots($cfg, $proj) as $dir) {
        if (is_dir($dir . '/' . $id)) {
            $found = $dir . '/' . $id;   // 不 return，讓後面的專案層蓋掉前面的全站層
        }
    }
    return $found;
}

/**
 * 「保留原稿」的落點：`projects/<proj>/layersrc/<id>/`，跟圖磚那份 `layers/<id>/` 是兄弟目錄。
 * 裡面放 `edit.json`（整疊圖片的位置與設定）與工具自己命名的原稿檔（`p0.png`、`p1.webp`…）。
 *
 * 為什麼擺在圖層搜尋路徑「之外」，而不是放進圖層資料夾裡再加一條黑名單：layerfile.php 的
 * 把關是副檔名白名單，而原稿本身就是合法圖檔——只要放得到那底下，網址猜對就送得出去，一張
 * 沒公開過的高解析手稿因此外流。結構上拿不到，比規則上不准拿可靠。原稿一律走
 * `tilecut.php?action=srcfile`，那條路查的是專案管理權。
 *
 * 只有專案層有原稿。全站層進版控，數十 MB 的手稿本來就不該塞進 repo。
 */
function souliong_layersrc_dir(array $cfg, string $proj, string $id): ?string
{
    $pdir = rtrim((string)($cfg['projects_dir'] ?? ''), "/\\");
    if ($pdir === '' || !preg_match('/^[a-z0-9_-]+$/', $proj) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $id)) {
        return null;
    }
    return $pdir . '/' . $proj . '/layersrc/' . $id;
}

/** 這一層留著可以載回重編的原稿嗎。後台要靠它決定顯不顯示「重新編輯」。 */
function souliong_layersrc_editable(array $cfg, string $proj, string $id): bool
{
    $d = souliong_layersrc_dir($cfg, $proj, $id);
    return $d !== null && is_file($d . '/edit.json');
}

/**
 * 原稿佔多少空間。上傳每一塊之前都會問一次，所以只數這一層自己的檔案（不遞迴，本來就是平的）。
 */
function souliong_layersrc_bytes(?string $dir): int
{
    if ($dir === null || !is_dir($dir)) {
        return 0;
    }
    $sum = 0;
    foreach (scandir($dir) ?: [] as $e) {
        $p = $dir . '/' . $e;
        if ($e !== '.' && $e !== '..' && !is_link($p) && is_file($p)) {
            $sum += (int)filesize($p);
        }
    }
    return $sum;
}

/**
 * 「含原稿」匯出時併進 souliong_layer_files() 結果的原稿清單，格式同款
 * （[ "<id>/相對路徑" => 磁碟絕對路徑 ]），可以直接 array 相加。
 *
 * 放在 `<id>/_src/` 底下：圖磚路徑一律是數字組成的 `<z>/<x>/<y>.ext`，`_src` 不會跟任何一級
 * zoom 資料夾撞名。匯入時要靠這個固定前綴把原稿路由回 layersrc/，不能跟圖層本體檔案混在一起
 * 落地（那邊沒有存取管制，見 souliong_layersrc_dir() 的說明）。
 */
function souliong_layersrc_files(?string $dir, string $id): array
{
    if ($dir === null || !is_dir($dir)) {
        return [];
    }
    $out = [];
    foreach (scandir($dir) ?: [] as $e) {
        $p = $dir . '/' . $e;
        if ($e !== '.' && $e !== '..' && !is_link($p) && is_file($p)) {
            $out[$id . '/_src/' . $e] = $p;
        }
    }
    return $out;
}

/**
 * 原稿的體積上限：單檔與單層各一個。
 *
 * 會設上限是因為這條路徑跟圖磚不同——圖磚是工具切出來的、大小可預期，原稿是使用者手上的檔案，
 * 一張 A1 掃描稿就可能上百 MB，而 projects/ 通常跟照片共用同一顆硬碟。超過就當場說不行，
 * 比事後發現硬碟滿了、投稿的照片存不進去要好。
 */
function souliong_layersrc_limits(array $cfg): array
{
    $file  = (int)($cfg['layersrc_max_file'] ?? 0);
    $total = (int)($cfg['layersrc_max_total'] ?? 0);
    return [
        'file'  => $file > 0 ? $file : 64 * 1024 * 1024,
        'total' => $total > 0 ? $total : 256 * 1024 * 1024,
    ];
}

/**
 * 沒有自己指定圖層的地圖套用哪一組。
 *
 * 預設值不是空陣列而是 ['paper-ink']：api/config.php 不進版控，「設定檔沒有這個欄位」
 * 是常態而不是意外，這時仍要有一張底圖，地圖才不會開天窗。後台要顯示「跟隨全站預設（…）」時
 * 也叫這裡，免得說明文字跟實際生效的圖層各講各的。
 */
function souliong_default_layers(array $cfg): array
{
    return (array)($cfg['default_layers'] ?? ['paper-ink']);
}

/**
 * $meta 是專案 meta.json 解析後的陣列（可能是 null）。
 * 回傳該地圖生效的圖層 manifest 陣列，由下往上排序；選到不存在的 id 會被靜靜略過，全部都不存在則退回 default_layers
 * （比照 souliong_pack_for()：資料指到已刪除的資源時退回預設，不讓整張地圖開天窗）。
 */
function souliong_layers_for(array $cfg, ?array $meta, string $proj = ''): array
{
    $all = souliong_layer_list($cfg, $proj);
    $want = (is_array($meta) && isset($meta['layers']) && is_array($meta['layers']) && $meta['layers'])
        ? $meta['layers']
        : souliong_default_layers($cfg);
    $pick = function (array $ids) use ($all): array {
        $out = [];
        foreach ($ids as $id) {
            if (is_string($id) && isset($all[$id])) {
                $out[] = $all[$id];
            }
        }
        return $out;
    };
    // 指定的 id 全都不存在（圖層資料夾已刪）時退回預設，不讓整張地圖沒有底圖
    return $pick($want) ?: $pick(souliong_default_layers($cfg));
}

/** 已封存（過時、保留只為相容）的圖層：manifest 的 deprecated 為 true。後台清單據此加標籤、排在新選項之後。 */
function souliong_layer_deprecated(array $manifest): bool
{
    return ($manifest['deprecated'] ?? false) === true;
}

/** 這個 manifest 的圖檔是不是放在自己資料夾裡（相對 url）＝要走 layer 端點輸出。 */
function souliong_layer_is_local(array $manifest): bool
{
    $url = (string)($manifest['url'] ?? '');
    return $url !== '' && strpos($url, '://') === false && strpos($url, '//') !== 0;
}

/**
 * 前端用的 manifest：把相對 url 改寫成 <base>/layer/<project>/<id>/... 的絕對路徑。
 * 在伺服器端解析掉的用意是「前端完全不必知道 local 與 remote、site 與 project 的差別」——
 * viewer 拿到的永遠是一條可以直接餵給 L.tileLayer 的網址。
 */
function souliong_layer_public(array $manifest, string $base, string $proj): array
{
    $id = (string)($manifest['id'] ?? '');
    foreach (['url', 'urlDark'] as $k) {
        $v = (string)($manifest[$k] ?? '');
        // "//host/..." 是協定相對的外部網址，不是本地檔
        if ($v === '' || strpos($v, '://') !== false || strpos($v, '//') === 0) {
            continue;
        }
        $manifest[$k] = $base . 'layer/' . rawurlencode($proj) . '/' . rawurlencode($id) . '/' . ltrim($v, '/');
    }
    unset($manifest['scope']);   // 伺服器端解析用的欄位，前端不需要也不該知道檔案放在哪
    return $manifest;
}

/** souliong_layers_for() 的前端版本：解析順序相同，額外套用 souliong_layer_public()。 */
function souliong_layers_public(array $cfg, ?array $meta, string $proj, string $base): array
{
    return array_map(
        fn(array $m): array => souliong_layer_public($m, $base, $proj),
        souliong_layers_for($cfg, $meta, $proj)
    );
}

/**
 * 圖層資料夾裡允許出現的圖檔副檔名 → MIME。端點輸出（layerfile.php）、後台匯出打包、後台匯入
 * 落地共用這一份名單：匯得出去的東西就該匯得回來，一份名單才不會三邊各長各的。
 */
function souliong_layer_mimes(): array
{
    return [
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'avif' => 'image/avif',
        'svg'  => 'image/svg+xml',
        'json' => 'application/json',   // 自訂向量樣式（style.json），layerfile.php 另外擋掉檔名 layer.json 本身
    ];
}

/**
 * 遞迴列出一個圖層資料夾該進 ZIP 的檔案，回傳 [ "<id>/相對路徑" => 磁碟絕對路徑 ]。
 *
 * 跟主題包的差別是檔案數量不固定：單張疊圖只有 layer.json ＋一個圖檔，切好的圖磚金字塔可能
 * 上萬個。所以這裡設上限，超過就整包放棄並把訊息 key 寫進 $err——與其讓請求跑到逾時、留下
 * 一個半截的 ZIP，不如當場說清楚「這包太大，請直接從伺服器取」。
 */
function souliong_layer_files(?string $dir, string $id, ?string &$err = null, int $maxFiles = 5000, int $maxBytes = 300 * 1024 * 1024): array
{
    $err = '';
    if ($dir === null || !is_dir($dir)) {
        $err = 'layer_not_found_msg';
        return [];
    }
    $root  = rtrim(str_replace('\\', '/', $dir), '/');
    $mimes = souliong_layer_mimes();
    $out   = [];
    $bytes = 0;
    $stack = [$root];
    while ($stack) {
        $cur = array_pop($stack);
        foreach (scandir($cur) ?: [] as $e) {
            if ($e === '.' || $e === '..' || !preg_match('/^[A-Za-z0-9_.-]+$/', $e)) {
                continue;
            }
            $path = $cur . '/' . $e;
            if (is_link($path)) {
                continue;   // 符號連結可能指到資料夾外，打包時一律跳過
            }
            if (is_dir($path)) {
                $stack[] = $path;
                continue;
            }
            $ext = strtolower(pathinfo($e, PATHINFO_EXTENSION));
            if ($e !== 'layer.json' && !isset($mimes[$ext])) {
                continue;
            }
            $bytes += (int)filesize($path);
            if (count($out) >= $maxFiles || $bytes > $maxBytes) {
                $err = 'layer_too_big_msg';
                return [];
            }
            $out[$id . '/' . ltrim(substr($path, strlen($root)), '/')] = $path;
        }
    }
    if (!isset($out[$id . '/layer.json'])) {
        $err = 'layer_not_found_msg';   // 沒有 manifest 就不是一個圖層，匯出去也裝不回來
        return [];
    }
    return $out;
}

/**
 * 圖層可以擺在哪幾個 pane，由下而上。切圖磚工具與後台的就地編輯共用這一份白名單——
 * 兩邊各寫一份的話，總有一天其中一邊會多出（或少掉）一個選項而沒人發現。
 */
function souliong_layer_panes(): array
{
    return ['base', 'paper', 'road', 'art'];
}

/**
 * 疊圖的四角邊界合不合理。±85.0511 是 Web Mercator 的實際上下限（Leaflet 用的也是這個數），
 * 超過去不是「畫錯位置」而是「這個投影根本畫不出來」，所以擋在這裡而不是留給前端去發散。
 */
function souliong_layer_bounds_valid(float $s, float $w, float $n, float $e): bool
{
    return $s < $n && $w < $e
        && abs($s) <= 85.0511 && abs($n) <= 85.0511
        && abs($w) <= 180 && abs($e) <= 180;
}

/**
 * 刪掉一棵目錄樹，但只准刪 $root 之內、且不等於 $root 的東西。
 *
 * 兩個地方要用：重切圖層時得先清掉舊磚（金字塔是稀疏的，上一版畫到、這一版沒畫到的格子若
 * 留著，就會變成怎麼擦都擦不掉的殘影），以及後台整層刪除。刪除不可逆，所以這裡用 realpath
 * 攤平後再確認一次目標確實在圖層資料夾底下，並且一律不跟隨符號連結。
 * 回傳「這棵樹真的不見了」。
 */
function souliong_layer_rmtree(string $dir, string $root): bool
{
    $rr = realpath($root);
    $rd = realpath($dir);
    if ($rr === false || $rd === false) {
        return false;
    }
    $n = fn(string $p): string => rtrim(str_replace('\\', '/', $p), '/');
    if ($n($rd) === $n($rr) || strpos($n($rd) . '/', $n($rr) . '/') !== 0) {
        return false;   // 目標不在 root 之內，或目標就是 root 本身——都不動
    }
    $stack = [$rd];
    $dirs  = [];
    while ($stack) {
        $cur = array_pop($stack);
        $dirs[] = $cur;
        foreach (scandir($cur) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = $cur . '/' . $e;
            if (is_link($p) || !is_dir($p)) {
                if (!@unlink($p) && file_exists($p)) {
                    @chmod($p, 0666);
                    @unlink($p);
                }
                continue;
            }
            $stack[] = $p;
        }
    }
    foreach (array_reverse($dirs) as $d) {
        if (!@rmdir($d) && is_dir($d)) {
            // Windows 上同步工具（OneDrive…）會給資料夾掛唯讀屬性，而 RemoveDirectory 遇到唯讀
            // 資料夾一律回 ACCESS_DENIED。清掉屬性再試一次——chmod 在 Windows 只動唯讀位元。
            @chmod($d, 0777);
            @rmdir($d);
        }
    }
    return !is_dir($rd);
}

/**
 * 版權標註（PHP 端）：跟 assets/js/engine/map-engine.js 的 creditHtml()／creditListHtml() 是
 * 同一套規則、同一份資料形狀（layer.json 的 attribution: [{text,url,copyright,suffix}]）——
 * 只有 copyright:true 的項目才加 &copy;，suffix 支援 {key} 形式的 i18n 代換。兩邊分別存在是因為
 * PHP 頁面（pages/landing.php）沒有載入前端地圖引擎，執行環境不同、共用不了同一份函式；
 * 但規則要對得起來，改動一邊記得另一邊也要跟著改。
 */
function souliong_credit_i18n_sub(string $s, array $DICT): string
{
    return preg_replace_callback('/\{([a-z0-9_]+)\}/i', fn(array $m): string => i18n_t($DICT, $m[1]), $s);
}

/** 單一署名項目（{text,url,copyright,suffix}）轉成一段 HTML。 */
function souliong_credit_html(?array $part, array $DICT): string
{
    if (!$part) {
        return '';
    }
    $text = htmlspecialchars(souliong_credit_i18n_sub((string)($part['text'] ?? ''), $DICT), ENT_QUOTES, 'UTF-8');
    $body = !empty($part['url'])
        ? '<a href="' . htmlspecialchars((string)$part['url'], ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">' . $text . '</a>'
        : $text;
    $suffix = !empty($part['suffix'])
        ? ' ' . htmlspecialchars(souliong_credit_i18n_sub((string)$part['suffix'], $DICT), ENT_QUOTES, 'UTF-8')
        : '';
    return (!empty($part['copyright']) ? '&copy;&nbsp;' : '') . $body . $suffix;
}

/** 這組圖層裡只要有向量圖層，主引擎署名就換成 MapLibre；判斷方式跟 view.php 的 $primaryEngine 一致。 */
function souliong_engine_credit(array $layers): array
{
    foreach ($layers as $l) {
        if (($l['type'] ?? '') === 'vector') {
            return ['text' => 'MapLibre', 'url' => 'https://maplibre.org'];
        }
    }
    return ['text' => 'Leaflet', 'url' => 'https://leafletjs.com'];
}
