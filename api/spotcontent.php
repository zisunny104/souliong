<?php
// POST spotcontent.php：整體儲存點位內容（spots.jsonl 起點的 content 區塊陣列）。
// 內容區是一個整體：編輯就是編輯整體，一次儲存 = 一筆版本 = 一個編輯者。
// 投稿與點位是兩個平行的域：投稿在 entries.jsonl，content 是點位自己的原生內容，兩者不互相引用。
// 權限是點位軸的 edit_spots（含 CSRF），跟投稿代碼、contrib_open()、模組旗標都無關——模組旗標只決定
// 前端要不要顯示編輯入口，不是後端的把關條件。item_num 對不到起點回 404，不產生孤兒紀錄。
// POST project, item_num（必填，起點的 num）, csrf, op=save, name（選填，編輯者顯示名稱）,
//   base_rev（前端載入內容時的 content_rev）, blocks（JSON 陣列，依顯示順序）,
//   media_0、media_1…（新增音訊／照片區塊的檔案，由區塊的 file 欄位指名；照片區塊可再用 thumb 欄位
//   指名一張前端縮好的縮圖，沒給就由 photo.php 首次請求時產生）。
// blocks 元素：{id?, kind, comment?, file?, thumb?, source_url?, source_license?, license?, duration?}
//   有 id 且存在於現有內容：保留原區塊，只採用送來的 comment，其餘欄位一律沿用原值；
//   沒有 id：新區塊，text 須有非空 comment，audio／photo 須有 file 指向 media_N（photo 的 comment 是選填說明）；
//   現有內容中沒被列出的區塊即刪除；順序即 blocks 順序；空陣列表示清空內容。
// 伺服器一律以 spot_effective() 的現有內容為底重建，不信任前端送整包。內容與現況完全相同就不寫版本、
// 直接回傳現況；base_rev 與現有 content_rev 不同回 409，不寫入。
// 回應的 item：content（完整區塊陣列，text 區塊附衍生欄位 html）、content_rev、name、created_at。
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/spotlib.php';
require_once __DIR__ . '/uploadlib.php';
require_once __DIR__ . '/features.php';
$cfg = require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST only'], 405);
}
uploadlib_reject_oversized_request();
rate_limit($cfg, 'write');

$project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
if ($project === '' || !is_dir($cfg['projects_dir'] . '/' . $project)) {
    json_out(['error' => 'bad request'], 400);
}

$actor = Auth::require($cfg, $project, 'edit_spots', true, '沒有權限編輯點位內容（僅限主要管理者，或已被授權的專案管理者）');

$item_num = (isset($_POST['item_num']) && $_POST['item_num'] !== '') ? (int)$_POST['item_num'] : null;
if ($item_num === null || ($_POST['op'] ?? '') !== 'save') {
    json_out(['error' => 'bad request'], 400);
}
$submitted = json_decode((string)($_POST['blocks'] ?? ''), true);
if (!is_array($submitted) || ($submitted && array_keys($submitted) !== range(0, count($submitted) - 1))) {
    json_out(['error' => 'bad request'], 400);
}

try {
    $eff = spot_effective($cfg, $project, $item_num);
    if ($eff === null) {
        json_out(['error' => '找不到這個點位'], 404);
    }
    $rev = (string)$eff['content_rev'];
    if ((string)($_POST['base_rev'] ?? '') !== $rev) {
        json_out(['error' => '內容已被別人更新，請重新載入後再編輯'], 409);
    }

    $current = [];
    foreach ((array)($eff['content'] ?? []) as $b) {
        if (is_array($b) && isset($b['id'])) $current[(string)$b['id']] = $b;
    }

    $content = [];
    $seen = [];
    foreach ($submitted as $in) {
        if (!is_array($in)) json_out(['error' => 'bad request'], 400);
        $comment = clean_str(isset($in['comment']) ? (string)$in['comment'] : null, $cfg['comment_max']);
        $id = isset($in['id']) ? (string)$in['id'] : '';
        if ($id !== '') {
            if (!isset($current[$id]) || isset($seen[$id])) json_out(['error' => 'bad request'], 400);
            $seen[$id] = true;
            $block = $current[$id];
            if (($block['kind'] ?? '') === 'text') {
                if ($comment === null) json_out(['error' => 'need comment'], 400);
                $block['comment'] = $comment;
            } elseif (array_key_exists('comment', $in)) {
                $block['comment'] = $comment;
            }
            $content[] = $block;
            continue;
        }

        $kind = (string)($in['kind'] ?? '');
        if (!souliong_kind_spot_content_postable($kind)) {
            json_out(['error' => 'bad kind'], 400);
        }
        $block = ['id' => spot_new_block_id(), 'kind' => $kind];
        if ($kind === 'text') {
            if ($comment === null) json_out(['error' => 'need comment'], 400);
            $block['comment'] = $comment;
        } elseif ($kind === 'audio' || $kind === 'photo') {
            $field = (string)($in['file'] ?? '');
            if (preg_match('/^media_\d+$/', $field) && isset($_FILES[$field]) && uploadlib_file_too_large($_FILES[$field])) {
                $lim = uploadlib_limits($cfg)['kinds'][$kind] ?? uploadlib_limits($cfg)['file'] ?? 0;
                json_out(['error' => uploadlib_too_large_message((int)$lim), 'code' => 'too_large', 'max_bytes' => $lim], 413);
            }
            if (!preg_match('/^media_\d+$/', $field) || !isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
                json_out(['error' => 'need media file'], 400);
            }
            $kindDef  = souliong_kinds()[$kind];
            $isPhoto  = ($kind === 'photo');
            $maxBytes = (int)($cfg['max_bytes_' . $kind] ?? $kindDef['max_bytes'] ?? $cfg['max_bytes']);
            $mimes    = ($isPhoto && !empty($cfg['allowed_mime'])) ? $cfg['allowed_mime'] : ($kindDef['mimes'] ?? []);
            $saved    = uploadlib_store_file($cfg, $project, $_FILES[$field], $isPhoto ? 'photos' : 'media', $mimes, $maxBytes, null, $kind);
            $duration = is_numeric($in['duration'] ?? null) ? round((float)$in['duration'], 2) : null;
            if ($duration !== null && ($duration <= 0 || $duration > 86400)) $duration = null;
            $sourceUrl = clean_str(isset($in['source_url']) ? (string)$in['source_url'] : null, 500);
            if ($sourceUrl !== null && (!preg_match('#^https?://#i', $sourceUrl) || filter_var($sourceUrl, FILTER_VALIDATE_URL) === false)) {
                $sourceUrl = null;
            }
            // CC BY（姓名標示）只對已建立身分（有 ctoken）的撰寫者開放，跟 upload.php 同一條規則
            $license = (!empty($_POST['ctoken']) && ($in['license'] ?? '') === 'cc-by') ? 'cc-by' : 'cc0';
            $extra = [
                'comment'        => $comment,
                'source_url'     => $sourceUrl,
                'source_license' => in_array($in['source_license'] ?? '', ['cc0', 'cc-by'], true) ? $in['source_license'] : null,
                'license'        => $license,
            ];
            if ($isPhoto) {
                // 欄位名沿用投稿的 photo／thumb，前端的 photoFullUrl()／photoThumbUrl() 才能通用
                $block += ['photo' => $saved['rel'], 'thumb' => null] + $extra;
                $tfield = (string)($in['thumb'] ?? '');
                if (preg_match('/^media_\d+$/', $tfield) && $tfield !== $field
                    && isset($_FILES[$tfield]) && $_FILES[$tfield]['error'] === UPLOAD_ERR_OK) {
                    $tf = $_FILES[$tfield];
                    if ($tf['size'] <= 1024 * 1024 && isset($mimes[detect_mime($tf['tmp_name'])])) {
                        $tname = $saved['fbase'] . '_t.' . $mimes[detect_mime($tf['tmp_name'])];
                        if (@move_uploaded_file($tf['tmp_name'], project_dir($cfg, $project) . '/photos/' . $tname)) {
                            $block['thumb'] = $project . '/' . $tname;
                        }
                    }
                }
            } else {
                $block += ['media' => $saved['rel'], 'media_mime' => $saved['mime'], 'duration' => $duration] + $extra;
            }
        } else {
            json_out(['error' => 'bad kind'], 400);
        }
        $content[] = $block;
    }

    if ($content === array_values((array)($eff['content'] ?? []))) {
        $revRec = $eff;
        foreach (store_all($cfg, $project) as $r) {
            if ((string)($r['id'] ?? '') === $rev) { $revRec = $r; break; }
        }
        json_out(['ok' => true, 'item' => [
            'content'     => spot_content_render($content),
            'content_rev' => $rev,
            'name'        => $revRec['name'] ?? null,
            'created_at'  => $revRec['created_at'] ?? null,
        ]]);
    }

    $name   = clean_str($_POST['name'] ?? null, $cfg['name_max']) ?? '管理者';
    $record = spot_append_version($cfg, $project, $eff, ['content' => $content], $actor->audit(), $name);
    json_out(['ok' => true, 'item' => [
        'content'     => spot_content_render($content),
        'content_rev' => $record['id'],
        'name'        => $record['name'],
        'created_at'  => $record['created_at'],
    ]]);
} catch (Throwable $e) {
    error_log('souliong spotcontent: ' . $e->getMessage());
    json_out(['error' => 'server'] + (!empty($cfg['debug']) ? ['detail' => $e->getMessage()] : []), 500);
}
