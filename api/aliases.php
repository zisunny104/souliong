<?php
/**
 * 專案網址別名與公開代號。
 *
 * 專案的「內部代號」是資料夾名（projects/<id>/），寫進每筆投稿的 project 欄位與照片路徑，之後改不動；
 * 對外的網址則可以另外設定：
 *   meta.json 的 slug     公開網址代號（正確拼法）。沒設定就等於內部代號。網址一律以它為準。
 *   meta.json 的 aliases  別名清單。任何用別名（或舊的內部代號）開出來的網址，會自動導向公開代號的同一個位置。
 * 這樣印出去的 QR 與舊書籤指向打錯的代號時不會失效，也不必搬資料。
 *
 * 導向規則（souliong_normalize_request）：
 *   - 頁面（地圖頁 <base>/<代號>[...]、後台 <base>/manager/<代號>[...]、?p=<代號>）用 GET／HEAD 開啟時，
 *     代號不是公開代號就 301 導向，路徑其餘部分、query string 與 #fragment（瀏覽器自己會保留）原樣帶過去。
 *   - 資料端點（?api=…、list／photo／layer／osm／cover… 與所有 POST）不導向，直接把別名換成內部代號處理，
 *     所以舊的圖片網址、API 呼叫、表單送出都照常運作。
 */

/** 不能拿來當代號的字：index.php 的動作名稱與後台入口別名，撞到會被當成動作而不是地圖 */
const SOULIONG_RESERVED_SLUGS = [
    'list', 'project', 'spots', 'navsheet', 'contribstatus', 'upload', 'photo', 'media', 'layer', 'model3d', 'osm',
    'appasset', 'cover', 'socialpreview', 'pinmark', 'delete', 'featureentry', 'editentry', 'editspot', 'newspot',
    'spotcontent', 'pages', 'unlock', 'exiffix', 'thumbfix', 'tilecut', 'region3d', 'layermigrate', 'newproject',
    'renameproject', 'stat', 'admin', 'manager', 'edit', 'privacy', 'index', 'landing', 'api',
];

/** 路徑第二段是專案代號的動作（<base>/<action>/<代號>/…） */
const SOULIONG_PROJECT_SEG1_ACTIONS = ['list', 'layer', 'osm', 'cover', 'pinmark', 'manager', 'admin'];

function souliong_slug_valid(string $s): bool
{
    return preg_match('/^[a-z0-9_-]{1,40}$/D', $s) === 1;
}

/** 專案資料夾所在位置；沒有傳 $cfg 時自己讀 api/config.php */
function souliong_urls_cfg(?array $cfg = null): array
{
    static $own = null;
    if ($cfg !== null) return $cfg;
    return $own ??= (is_file(__DIR__ . '/config.php') ? (array)(require __DIR__ . '/config.php') : []);
}

/**
 * 掃描所有專案的 meta.json，回傳：
 *   ids    內部代號 => true
 *   slug   內部代號 => 公開代號
 *   names  任何一個名字（內部代號／公開代號／別名） => 內部代號
 *   custom 有沒有任何專案設了公開代號或別名（沒有就不必做任何轉換）
 */
function souliong_url_registry(?array $cfg = null, bool $fresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    $cfg = souliong_urls_cfg($cfg);
    $dir = rtrim((string)($cfg['projects_dir'] ?? (__DIR__ . '/../projects')), '/\\');
    $reg = ['ids' => [], 'slug' => [], 'names' => [], 'custom' => false];
    $metas = [];
    foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $id) {
        if (!souliong_slug_valid($id) || !is_dir($dir . '/' . $id)) continue;
        $reg['ids'][$id] = true;
        $mf = $dir . '/' . $id . '/meta.json';
        $m = is_file($mf) ? json_decode((string)@file_get_contents($mf), true) : null;
        $metas[$id] = is_array($m) ? $m : [];
    }
    // 先登記內部代號（真實資料夾優先），再登記公開代號與別名；撞到已被登記的名字就略過，避免兩張地圖搶同一個網址
    foreach ($metas as $id => $m) $reg['names'][$id] = $id;
    foreach ($metas as $id => $m) {
        $slug = (string)($m['slug'] ?? '');
        $reg['slug'][$id] = $id;
        if ($slug !== '' && $slug !== $id && souliong_slug_valid($slug) && !isset($reg['names'][$slug])) {
            $reg['slug'][$id] = $slug;
            $reg['names'][$slug] = $id;
            $reg['custom'] = true;
        }
    }
    foreach ($metas as $id => $m) {
        foreach ((array)($m['aliases'] ?? []) as $alias) {
            $alias = (string)$alias;
            if (!souliong_slug_valid($alias) || isset($reg['names'][$alias])) continue;
            $reg['names'][$alias] = $id;
            $reg['custom'] = true;
        }
    }
    return $cache = $reg;
}

/** 任何名字（內部代號／公開代號／別名）→ 內部代號；找不到回 null */
function souliong_resolve_project(string $name, ?array $cfg = null): ?string
{
    return souliong_url_registry($cfg)['names'][$name] ?? null;
}

/** 內部代號 → 網址上要用的公開代號（沒設定或不存在就原樣回傳） */
function souliong_public_slug(string $id, ?array $cfg = null): string
{
    return souliong_url_registry($cfg)['slug'][$id] ?? $id;
}

/**
 * 檢查要存的公開代號與別名。回傳錯誤訊息；沒問題回 ''。
 * 規則：格式 [a-z0-9_-]{1,40}；不能撞保留字；不能跟其他專案的內部代號、公開代號、別名重複。
 */
function souliong_urls_error(string $id, string $slug, array $aliases, ?array $cfg = null): string
{
    $reg = souliong_url_registry($cfg, true);
    $taken = [];   // 別的專案已經用掉的名字
    foreach ($reg['names'] as $name => $owner) if ($owner !== $id) $taken[$name] = $owner;
    $all = array_merge($slug !== '' ? [$slug] : [], $aliases);
    $seen = [];
    foreach ($all as $name) {
        if (!souliong_slug_valid($name)) return '「' . $name . '」格式不正確：只能用小寫英文字母、數字、底線與減號，最多 40 個字元。';
        if (in_array($name, SOULIONG_RESERVED_SLUGS, true)) return '「' . $name . '」是系統保留字，不能當網址代號。';
        if (isset($taken[$name])) return '「' . $name . '」已經被另一張地圖（' . $taken[$name] . '）使用。';
        if (isset($seen[$name])) return '「' . $name . '」重複了。';
        $seen[$name] = true;
    }
    return '';
}

/** 取得字串形式的 query 參數（保留原始位元組，其餘參數原樣不動） */
function souliong_replace_query_params(string $query, array $keys, callable $replace): array
{
    if ($query === '') return [$query, false];
    $changed = false;
    $parts = explode('&', $query);
    foreach ($parts as $i => $part) {
        $eq = strpos($part, '=');
        $key = urldecode($eq === false ? $part : substr($part, 0, $eq));
        if ($eq === false || !in_array($key, $keys, true)) continue;
        $value = urldecode(substr($part, $eq + 1));
        $new = $replace($key, $value);
        if ($new !== null && $new !== $value) { $parts[$i] = substr($part, 0, $eq + 1) . rawurlencode($new); $changed = true; }
    }
    return [implode('&', $parts), $changed];
}

/**
 * index.php 在拆完路徑之後、分派動作之前呼叫。
 * 會（1）對頁面網址送出 301 導向；（2）把所有別名／公開代號換成內部代號，後面的程式只會看到內部代號。
 * $seg 與 $action 以參照傳入並就地改寫。
 */
function souliong_normalize_request(array &$seg, &$action, string $reqPath, string $prefixPath): void
{
    $reg = souliong_url_registry();
    if (!$reg['custom']) return;   // 沒有任何專案設公開代號或別名：完全不介入
    $names = $reg['names'];
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $isPageMethod = $method === 'GET' || $method === 'HEAD';
    $query = (string)($_SERVER['QUERY_STRING'] ?? '');

    $head = (string)($seg[0] ?? '');
    $isAction = in_array($head, SOULIONG_RESERVED_SLUGS, true);
    // 哪些路徑段是專案代號（0 起算的 $seg 索引）
    $positions = [];
    if ($head !== '' && !$isAction) $positions[] = 0;
    elseif (in_array($head, SOULIONG_PROJECT_SEG1_ACTIONS, true) && isset($seg[1])) $positions[] = 1;
    // 導向只對頁面：地圖頁（第 0 段是代號）與後台（manager／admin 後面的代號）。資料端點只換代號、不導向
    $pageSeg = ($head !== '' && !$isAction) || in_array($head, ['manager', 'admin'], true);

    $redirectSeg = [];
    foreach ($positions as $i) {
        $name = (string)$seg[$i];
        $id = $names[$name] ?? null;
        if ($id === null) continue;
        if ($pageSeg && $name !== ($reg['slug'][$id] ?? $id)) $redirectSeg[$i] = $reg['slug'][$id] ?? $id;
        $seg[$i] = $id;
    }
    if ($head !== '' && !$isAction) $action = $seg[0];

    // query：project／p 是代號，f 是「代號/檔名」
    $isPageQuery = !isset($_GET['api']);   // ?p=<代號> 是頁面；?api=… 是資料端點
    $toPublic = function (string $key, string $value) use ($names, $reg, $isPageQuery): ?string {
        if ($key === 'f') {
            $slash = strpos($value, '/');
            if ($slash === false) return null;
            $id = $names[substr($value, 0, $slash)] ?? null;
            return $id === null ? null : $id . substr($value, $slash);
        }
        $id = $names[$value] ?? null;
        if ($id === null) return null;
        return $isPageQuery && $key === 'p' ? ($reg['slug'][$id] ?? $id) : $id;
    };
    [$newQuery, $queryChanged] = souliong_replace_query_params($query, ['p', 'project', 'f'], $toPublic);
    // 內部使用的 $_GET／$_POST 一律換成內部代號
    foreach (['project', 'p'] as $key) {
        foreach ([&$_GET, &$_POST] as &$bag) {
            if (isset($bag[$key]) && is_string($bag[$key]) && isset($names[$bag[$key]])) $bag[$key] = $names[$bag[$key]];
        }
        unset($bag);
    }
    foreach ([&$_GET, &$_POST] as &$bag) {
        if (isset($bag['f']) && is_string($bag['f']) && ($slash = strpos($bag['f'], '/')) !== false && isset($names[substr($bag['f'], 0, $slash)])) {
            $bag['f'] = $names[substr($bag['f'], 0, $slash)] . substr($bag['f'], $slash);
        }
    }
    unset($bag);
    $_REQUEST = array_merge($_REQUEST, array_intersect_key($_GET + $_POST, array_flip(['project', 'p', 'f'])));

    // 頁面導向：路徑段與 ?p= 的代號不是公開代號時 301；只有 ?p= 的頁面才改 query（資料端點的 query 不動）
    $queryRedirect = $isPageQuery && $queryChanged;
    if (!$isPageMethod || (!$redirectSeg && !$queryRedirect)) return;
    $afterRaw = substr($reqPath, strlen($prefixPath));
    $raw = explode('/', $afterRaw);
    $k = -1;
    foreach ($raw as $idx => $piece) {
        if ($piece === '') continue;
        $k++;
        if (isset($redirectSeg[$k])) $raw[$idx] = rawurlencode($redirectSeg[$k]);
    }
    $target = $prefixPath . implode('/', $raw);
    $outQuery = $queryRedirect ? $newQuery : $query;
    if ($target === '' || $target[0] !== '/') $target = '/' . $target;
    header('Location: ' . $target . ($outQuery !== '' ? '?' . $outQuery : ''), true, 301);
    header('Cache-Control: public, max-age=3600');
    exit;
}
