<?php
// 常駐工具：專案改名（把內部代號——資料夾名稱——改成新的）。只有主管理者能用。
// 一般只想讓網址好看或修正拼字，優先用後台「網址設定」的公開代號與別名（不動資料、隨時可改回）；
// 這支是真的把資料夾與資料裡的代號全部改掉，執行前請先備份（後台全站備份）。
//
// 會改的東西：
//   projects/<舊>/ → projects/<新>/；投稿與點位紀錄裡的 project 欄位、photo／thumb／media 這類 "<舊>/檔名" 路徑；
//   meta.json 的別名（舊代號自動加進去，舊網址會導向新網址）；state/pins.json 的專案 PIN 清單；state/social-previews/<舊>/。
// 改名之後會失效、需要重新取得的東西：
//   該專案的專案 PIN 登入狀態（簽章綁專案代號，要重新登入）、訪客手上的投稿代碼解鎖狀態（要重新輸入代碼）。
// 失敗時會把已經改的還原；每次執行前都會在 state/rename-backups/ 留一份 jsonl、meta、PIN 清單的備份。
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/routes.php';
require_once __DIR__ . '/aliases.php';
require_once __DIR__ . '/../pages/error.php';
$cfg = require __DIR__ . '/config.php';
rate_limit($cfg, 'admin');
[$LANG, $DICT] = i18n_init();
$esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$tr = fn(string $key, array $vars = []): string => i18n_t($DICT, $key, $vars);

/** 遞迴改寫一筆紀錄：project 欄位等於舊代號、字串值是 "<舊>/檔名" 的路徑，換成新代號。$n 累計改了幾處 */
function rename_rewrite_value($v, string $old, string $new, int &$n, string $key = '')
{
    if (is_array($v)) {
        foreach ($v as $k => $item) $v[$k] = rename_rewrite_value($item, $old, $new, $n, (string)$k);
        return $v;
    }
    if (!is_string($v)) return $v;
    if ($key === 'project' && $v === $old) { $n++; return $new; }
    if (str_starts_with($v, $old . '/') && preg_match('#^[a-z0-9_-]+/[A-Za-z0-9_.-]+$#D', $v)) { $n++; return $new . substr($v, strlen($old)); }
    return $v;
}

/** 改寫一個 jsonl 檔，回傳 [紀錄數, 改了幾處]。$apply=false 只統計。壞行原樣保留 */
function rename_rewrite_jsonl(string $file, string $old, string $new, bool $apply): array
{
    if (!is_file($file)) return [0, 0];
    $in = fopen($file, 'rb');
    if (!$in) throw new RuntimeException('無法讀取 ' . basename($file));
    flock($in, LOCK_SH);
    $out = [];
    $records = 0; $changed = 0;
    while (($line = fgets($in)) !== false) {
        $trim = rtrim($line, "\r\n");
        $d = $trim === '' ? null : json_decode($trim, true);
        if (!is_array($d)) { $out[] = $trim; continue; }
        $records++;
        $n = 0;
        $d = rename_rewrite_value($d, $old, $new, $n);
        $changed += $n;
        $out[] = $n ? json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $trim;
    }
    flock($in, LOCK_UN); fclose($in);
    if ($apply && $changed) {
        $tmp = $file . '.rename.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, implode("\n", $out) . "\n", LOCK_EX) === false || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('無法寫入 ' . basename($file));
        }
    }
    return [$records, $changed];
}

function rename_plan(array $cfg, string $old, string $new): array
{
    $dir = project_dir($cfg, $old);
    $plan = ['files' => [], 'records' => 0, 'changed' => 0, 'pins' => 0, 'previews' => false];
    foreach (glob($dir . '/*.jsonl') ?: [] as $f) {
        [$r, $c] = rename_rewrite_jsonl($f, $old, $new, false);
        $plan['files'][basename($f)] = [$r, $c];
        $plan['records'] += $r; $plan['changed'] += $c;
    }
    $pins = pins_load($cfg);
    $plan['pins'] = count($pins['projects'][$old] ?? []);
    $plan['previews'] = is_dir(rtrim($cfg['state_dir'], '/\\') . '/social-previews/' . $old);
    return $plan;
}

function rename_copy_dir_files(array $files, string $dest): void
{
    if (!is_dir($dest) && !@mkdir($dest, 0775, true)) throw new RuntimeException('無法建立備份資料夾');
    foreach ($files as $f) if (is_file($f)) @copy($f, $dest . '/' . basename($f));
}

function rename_execute(array $cfg, string $old, string $new): array
{
    $state = rtrim($cfg['state_dir'], '/\\');
    $lock = fopen($state . '/rename.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('另一個改名工作正在進行，請稍後再試');
    $oldDir = project_dir($cfg, $old); $newDir = project_dir($cfg, $new);
    $backup = $state . '/rename-backups/' . gmdate('Ymd-His') . '-' . $old . '-to-' . $new;
    $moved = false; $previewMoved = false;
    try {
        // 1. 備份：jsonl、meta.json、perms.json 與 PIN 清單（照片不動，只是改資料夾名）
        $small = array_merge(glob($oldDir . '/*.jsonl') ?: [], glob($oldDir . '/*.json') ?: []);
        rename_copy_dir_files($small, $backup . '/project');
        rename_copy_dir_files([$state . '/pins.json'], $backup);

        // 2. 搬資料夾
        if (!@rename($oldDir, $newDir)) throw new RuntimeException('無法把資料夾改名（權限不足？）');
        $moved = true;

        // 3. 改寫紀錄
        $changed = 0;
        foreach (glob($newDir . '/*.jsonl') ?: [] as $f) { [, $c] = rename_rewrite_jsonl($f, $old, $new, true); $changed += $c; }

        // 4. meta.json：舊代號變別名（舊網址導向新網址）；公開代號若就是新代號就不必再存
        $mf = $newDir . '/meta.json';
        $meta = is_file($mf) ? json_decode((string)file_get_contents($mf), true) : [];
        if (!is_array($meta)) $meta = [];
        $aliases = array_map('strval', (array)($meta['aliases'] ?? []));
        $aliases[] = $old;
        if (!empty($meta['slug']) && $meta['slug'] !== $new) $aliases[] = (string)$meta['slug'];
        unset($meta['slug']);
        $meta['aliases'] = array_values(array_unique(array_filter($aliases, fn($a) => $a !== $new)));
        if (file_put_contents($mf, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n", LOCK_EX) === false) throw new RuntimeException('無法寫入 meta.json');

        // 5. 專案 PIN 清單
        $pinsFile = $state . '/pins.json';
        if (is_file($pinsFile)) {
            $pins = json_decode((string)file_get_contents($pinsFile), true);
            if (is_array($pins) && isset($pins['projects'][$old])) {
                $pins['projects'][$new] = $pins['projects'][$old];
                unset($pins['projects'][$old]);
                if (file_put_contents($pinsFile, json_encode($pins, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false) throw new RuntimeException('無法寫入 pins.json');
            }
        }
        // 6. 社群預覽快取
        $pv = $state . '/social-previews';
        if (is_dir($pv . '/' . $old) && !is_dir($pv . '/' . $new)) $previewMoved = @rename($pv . '/' . $old, $pv . '/' . $new);
        return ['changed' => $changed, 'backup' => $backup];
    } catch (Throwable $e) {
        // 還原：資料夾搬回去，備份的 json／jsonl 蓋回去，PIN 清單還原
        if ($moved) {
            if (@rename($newDir, $oldDir)) {
                foreach (glob($backup . '/project/*') ?: [] as $f) @copy($f, $oldDir . '/' . basename($f));
            }
        }
        if (is_file($backup . '/pins.json')) @copy($backup . '/pins.json', $state . '/pins.json');
        if ($previewMoved) @rename($state . '/social-previews/' . $new, $state . '/social-previews/' . $old);
        throw $e;
    } finally {
        flock($lock, LOCK_UN); fclose($lock);
    }
}

$actor = Auth::require($cfg, null, 'create_project', $_SERVER['REQUEST_METHOD'] === 'POST', '只有主管理者能改專案代號。');
$projects = store_projects($cfg);
$cleanId = fn($raw): string => (string)preg_replace('/[^a-z0-9_-]/', '', (string)$raw);
$old = $cleanId($_POST['project'] ?? ($_GET['project'] ?? ''));
$new = $cleanId($_POST['new_id'] ?? '');
$step = (string)($_POST['step'] ?? '');
$csrf = (string)($actor->csrf(null) ?? '');
$self = Route::base() . 'renameproject';
$notice = '';
$plan = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($step, ['preview', 'rename'], true)) {
    $error = '';
    if (!in_array($old, $projects, true)) $error = '找不到這張地圖。';
    elseif ($new === '' || strlen($new) > 40) $error = '新代號只能用小寫英文字母、數字、底線、減號，最多 40 字。';
    elseif ($new === $old) $error = '新代號跟舊代號一樣。';
    elseif (in_array($new, SOULIONG_RESERVED_SLUGS, true)) $error = '「' . $new . '」是系統保留字。';
    elseif (is_dir(project_dir($cfg, $new)) || isset(souliong_url_registry($cfg, true)['names'][$new])) $error = '「' . $new . '」已經被使用（是另一張地圖的代號或別名）。';
    if ($error !== '') error_page(400, '專案改名', $error, $self);
    try {
        if ($step === 'preview') {
            $plan = rename_plan($cfg, $old, $new);
        } else {
            if (($_POST['confirm_text'] ?? '') !== $new || !isset($_POST['backed_up'])) error_page(400, '專案改名', '請勾選「已備份」，並輸入新代號確認。', $self);
            $res = rename_execute($cfg, $old, $new);
            audit_log($cfg, $actor->audit(), 'project_rename', $new, $old . ' -> ' . $new . '（' . $res['changed'] . ' 處）');
            souliong_url_registry($cfg, true);
            $notice = '已改名：' . $old . ' → ' . $new . '，改寫 ' . $res['changed'] . ' 處紀錄。舊代號已自動變成別名，舊網址會導向新網址。備份在 ' . $res['backup'];
            $old = ''; $new = '';
            $projects = store_projects($cfg);
        }
    } catch (Throwable $e) {
        error_page(500, '專案改名失敗（已還原）', $e->getMessage(), $self);
    }
}
?><!doctype html>
<html lang="zh-Hant"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>專案改名 | Souliong</title>
<style>body{font:16px/1.6 system-ui,sans-serif;max-width:44rem;margin:2rem auto;padding:0 1rem;color:#222}label{display:block;margin:.8rem 0 .2rem;font-weight:600}input,select{font:inherit;padding:.4rem .5rem;width:100%;box-sizing:border-box}button{font:inherit;padding:.5rem 1rem;margin-top:1rem;cursor:pointer}.warn{background:#fff4e0;border:1px solid #d9a441;padding:.8rem 1rem;border-radius:6px}.ok{background:#e8f6ea;border:1px solid #5aa469;padding:.8rem 1rem;border-radius:6px}table{border-collapse:collapse;margin:.6rem 0}td,th{border:1px solid #ccc;padding:.3rem .6rem;text-align:left}code{background:#f1f1f1;padding:0 .25rem}</style></head><body>
<h1>專案改名</h1>
<p><a href="<?= $esc(Route::manager()) ?>">← 回後台</a></p>
<div class="warn">
  <p>只想修正網址拼字，請用後台每張地圖的「網址設定」（公開代號＋別名），不動資料、隨時能改回。這裡是真的把資料夾與資料裡的代號全部改掉。</p>
  <p><strong>請先做後台的「全站備份」。</strong>改名後專案 PIN 的登入狀態與訪客的投稿代碼解鎖狀態會失效，需要重新登入／重新輸入。舊代號會自動變成別名，舊網址與印出去的 QR 仍會導向新網址。</p>
</div>
<?php if ($notice !== ''): ?><p class="ok"><?= $esc($notice) ?></p><?php endif; ?>
<?php if ($plan === null): ?>
<form method="post">
  <input type="hidden" name="csrf" value="<?= $esc($csrf) ?>"><input type="hidden" name="step" value="preview">
  <label for="project">要改名的地圖</label>
  <select id="project" name="project"><?php foreach ($projects as $p): ?><option value="<?= $esc($p) ?>"<?= $p === $old ? ' selected' : '' ?>><?= $esc($p) ?></option><?php endforeach; ?></select>
  <label for="new_id">新代號（小寫英文字母、數字、底線、減號，最多 40 字）</label>
  <input id="new_id" name="new_id" maxlength="40" pattern="[a-z0-9_\-]+" required value="<?= $esc($new) ?>">
  <button>預覽會改哪些東西</button>
</form>
<?php else: ?>
<h2>預覽：<code><?= $esc($old) ?></code> → <code><?= $esc($new) ?></code></h2>
<table><tr><th>檔案</th><th>紀錄數</th><th>要改的欄位數</th></tr>
<?php foreach ($plan['files'] as $name => [$r, $c]): ?><tr><td><?= $esc($name) ?></td><td><?= (int)$r ?></td><td><?= (int)$c ?></td></tr><?php endforeach; ?>
<?php if (!$plan['files']): ?><tr><td colspan="3">沒有 jsonl 資料檔</td></tr><?php endif; ?></table>
<ul><li>資料夾 <code>projects/<?= $esc($old) ?></code> 改名為 <code>projects/<?= $esc($new) ?></code></li>
<li>專案 PIN：<?= (int)$plan['pins'] ?> 筆跟著搬到新代號</li>
<li>社群預覽快取：<?= $plan['previews'] ? '會一起改名' : '沒有' ?></li>
<li>舊代號 <code><?= $esc($old) ?></code> 自動加入別名</li></ul>
<form method="post">
  <input type="hidden" name="csrf" value="<?= $esc($csrf) ?>"><input type="hidden" name="step" value="rename">
  <input type="hidden" name="project" value="<?= $esc($old) ?>"><input type="hidden" name="new_id" value="<?= $esc($new) ?>">
  <label><input type="checkbox" name="backed_up" value="1" style="width:auto"> 我已經做過全站備份</label>
  <label for="confirm_text">輸入新代號 <code><?= $esc($new) ?></code> 確認</label>
  <input id="confirm_text" name="confirm_text" autocomplete="off" required>
  <button>確定改名</button>
</form>
<p><a href="<?= $esc($self . '?project=' . rawurlencode($old)) ?>">取消</a></p>
<?php endif; ?>
</body></html>
