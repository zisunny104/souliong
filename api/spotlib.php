<?php
// 點位（spot）共用邏輯（純函式，無副作用，可安全被多處 require）：起點記錄疊上 edit_of 版本鏈，
// 算出「目前有效狀態」，供 api/oglib.php、api/editspot.php、api/spotcontent.php 共用同一套算法，
// 不各自重寫一份；另外提供版本寫入、內容區塊穩定 id、Markdown 算繪等共用函式。
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/markdown.php';
require_once __DIR__ . '/routes.php';

// ---------------------------------------------------------------------------
// 點位「目前有效狀態」共用邏輯：起點＋edit_of 鏈疊加，供 api/oglib.php、api/editspot.php、
// api/spotcontent.php 共用同一套算法，取代各自重寫一份（原本 oglib.php 自己疊一份、
// editspot.php 完全不疊、直接要求前端每次帶齊全部欄位）。
// ---------------------------------------------------------------------------

/** 起點／編輯紀錄裡，哪些欄位是可被 edit_of 鏈覆寫的「有效狀態」欄位——spot_effective()／
 *  spot_append_version() 與其呼叫端都以這份清單為準，不要各自硬編一份欄位名單。 */
function spot_overridable_fields(): array
{
    return ['lat', 'lon', 'content'];
}

/**
 * spotId：起點紀錄的 id（bin2hex(random_bytes(8))，16 位小寫十六進位），是點位對外的穩定識別。
 * num 只是顯示編號，刪除最大號後可能被重用，不可當外部鍵。
 */
function spot_id_valid(string $s): bool
{
    return preg_match('/^[0-9a-f]{16}$/', $s) === 1;
}

/** 舊資料的 kind 值 point／newpoint 一律視為 spot（防禦性正規化，不改儲存資料）。 */
function spot_kind_normalize(?string $kind): string
{
    return in_array($kind, ['point', 'newpoint'], true) ? 'spot' : (string)$kind;
}

/** 把一條 edit_of 鏈逐欄疊到起點上，回傳有效狀態（含 content_rev）。 */
function _spot_overlay(array $origin, array $chain): array
{
    usort($chain, fn($a, $b) => strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? '')));
    // content_rev：目前內容是哪一筆紀錄寫的（鏈上最後一筆帶 content 的版本，沒有就是起點）。整體編輯內容時，
    // 前端帶回它當 base_rev，伺服器據此擋掉兩人同時編輯互相覆蓋。前端 effectiveSpots() 用同一條規則算。
    $rev = (string)$origin['id'];
    foreach ($chain as $e) {
        foreach (spot_overridable_fields() as $k) {
            if (array_key_exists($k, $e)) $origin[$k] = $e[$k];
        }
        if (array_key_exists('content', $e)) $rev = (string)$e['id'];
    }
    $origin['content_rev'] = $rev;
    return $origin;
}

/**
 * 全部點位「目前有效」的狀態（依 num 由小到大）：起點（kind:'spot'、有 num、edit_of 留空）疊上 edit_of 鏈。
 * 逐欄疊加：每個可覆寫欄位取「鏈上帶有該 key 的最新一筆」的值，created_at 相同時檔案裡較後
 * 面那筆勝出，所以一筆版本紀錄只需要寫它真的改到的欄位。疊加用 array_key_exists() 而非
 * isset()——「沒帶這個 key」與「明確覆寫成 null／空陣列」是兩回事。
 */
function spot_effective_all(array $cfg, string $project): array
{
    $origins = [];
    $edits = [];
    foreach (store_all($cfg, $project) as $r) {
        if (spot_kind_normalize($r['kind'] ?? '') !== 'spot') continue;
        if (empty($r['edit_of']) && isset($r['num'])) {
            $origins[] = $r;
        } elseif (!empty($r['edit_of'])) {
            $edits[$r['edit_of']][] = $r;
        }
    }
    $out = [];
    foreach ($origins as $o) {
        $out[] = _spot_overlay($o, $edits[$o['id'] ?? ''] ?? []);
    }
    usort($out, fn($a, $b) => (int)$a['num'] <=> (int)$b['num']);
    return $out;
}

/** 單一點位的有效狀態，依 num 找；找不到這個 item_num 的起點回傳 null。 */
function spot_effective(array $cfg, string $project, int $itemNum): ?array
{
    foreach (spot_effective_all($cfg, $project) as $s) {
        if ((int)$s['num'] === $itemNum) return $s;
    }
    return null;
}

/** 單一點位的有效狀態，依 spotId 找；找不到回傳 null。 */
function spot_effective_by_id(array $cfg, string $project, string $spotId): ?array
{
    if (!spot_id_valid($spotId)) return null;
    foreach (spot_effective_all($cfg, $project) as $s) {
        if (($s['id'] ?? '') === $spotId) return $s;
    }
    return null;
}

/**
 * 點位參照解析（?spot=）：16 位十六進位視為 spotId，
 * 純數字視為 num；其他回 null。回傳有效狀態，找不到回 null。
 */
function spot_effective_by_ref(array $cfg, string $project, string $ref): ?array
{
    if (spot_id_valid($ref)) return spot_effective_by_id($cfg, $project, $ref);
    if ($ref !== '' && ctype_digit($ref) && strlen($ref) <= 9) return spot_effective($cfg, $project, (int)$ref);
    return null;
}

/**
 * 表單的 item_num 欄位（num 或 spotId）轉成 num。空值回 null；spotId 對不到點位或格式不對回 false。
 * 純數字不檢查該 num 是否存在。
 */
function spot_num_from_ref(array $cfg, string $project, $raw)
{
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    if (spot_id_valid($raw)) {
        $s = spot_effective_by_id($cfg, $project, $raw);
        return $s ? (int)$s['num'] : false;
    }
    return is_numeric($raw) ? (int)$raw : false;
}

/**
 * 寫一筆點位版本紀錄：稀疏——只帶 $changes 裡屬於可覆寫欄位的 key，沒提到的欄位不寫、也不會被
 * 蓋掉（見 spot_effective() 的逐欄疊加）。$eff 是 spot_effective() 的結果，用來取起點 id 與
 * item_num；$audit 是操作者稽核字串（Actor 的 audit()），$name 是顯示用的編輯者名稱。
 * 全部寫版本紀錄的地方（editspot、spotcontent、遷移工具）都走這裡，不各自組紀錄。
 */
function spot_append_version(array $cfg, string $project, array $eff, array $changes, string $audit, string $name): array
{
    $fields = array_intersect_key($changes, array_flip(spot_overridable_fields()));
    if (!$fields) throw new InvalidArgumentException('spot version has no overridable field');
    $record = [
        'id'         => bin2hex(random_bytes(8)),
        'project'    => $project,
        'kind'       => 'spot',
        'item_num'   => (int)($eff['num'] ?? $eff['item_num']),
        'edit_of'    => (string)$eff['id'],
        'name'       => $name,
        'actor'      => $audit,
    ] + $fields + ['created_at' => gmdate('c')];
    store_append($cfg, $project, $record);
    return $record;
}

/** 點位內容區塊的穩定 id（區塊之間靠它指名編輯、刪除、排序）。 */
function spot_new_block_id(): string
{
    return bin2hex(random_bytes(6));
}

/** 點位內容裡第一個文字區塊的文字，給 OG 描述等只需要一段字的地方用；沒有回空字串。 */
function spot_content_text(array $eff): string
{
    foreach ((array)($eff['content'] ?? []) as $b) {
        if (is_array($b) && ($b['kind'] ?? '') === 'text' && !empty($b['comment'])) return (string)$b['comment'];
    }
    return '';
}

/** 內容文字（點位 text 區塊、訪客 text 投稿）的 Markdown 算繪，全站唯一入口。 */
function spot_markdown(string $md): string
{
    return Markdown::toHtml($md, ['heading_ids' => false, 'heading_offset' => 2]);
}

/**
 * 點位內容區塊的輸出形式：text 區塊附加衍生欄位 html（comment 的 Markdown 算繪結果，全站只有
 * Markdown::toHtml() 這一份定義）。html 只在輸出時算，不寫進 spots.jsonl；前端直接用 block.html。
 */
function spot_content_render(array $content): array
{
    $out = [];
    foreach ($content as $b) {
        if (is_array($b) && ($b['kind'] ?? '') === 'text' && !empty($b['comment'])) {
            $b['html'] = spot_markdown((string)$b['comment']);
        } elseif (is_array($b) && ($b['kind'] ?? '') === 'photo' && !empty($b['photo'])) {
            $b['photo_url'] = Route::api('photo', ['f' => $b['photo']]);
            $b['thumb_url'] = Route::api('photo', ['f' => $b['thumb'] ?: $b['photo']] + (empty($b['thumb']) ? ['th' => 1] : []));
        }
        $out[] = $b;
    }
    return $out;
}
