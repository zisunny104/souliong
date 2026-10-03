#!/usr/bin/env bash
# 一次性升級腳本（舊版 → 最新 origin/main）。在 souliong 資料夾執行，順序：
#   1. df81aba  ：data.jsonl 拆成 spots/entries、靜態底稿併入 spots、核對 retirecheck
#   2. 3e85c83  ：精選音訊轉成地點原生 content
#   3. origin/main：content_migrate（說明與內容合併）、修權限、重載 PHP
# 資料夾 projects/、state/、api/config.php 不受 git 影響（未追蹤）。任何一步失敗立刻停下並說明目前停在哪。
#
# 用法（腳本自己會在升級中途切換 git 版本，所以要先複製到 /tmp 再執行）：
#   git fetch origin && git show origin/main:tools/vps_upgrade.sh > /tmp/vps_upgrade.sh
#   bash /tmp/vps_upgrade.sh --preview    # 只預覽 data.jsonl 拆檔，不改任何東西
#   bash /tmp/vps_upgrade.sh              # 正式升級
# 全部完成並確認後，本腳本與 tools/upgrade_data.php 可刪除。
set -euo pipefail

PREVIEW=0
[ "${1:-}" = "--preview" ] && PREVIEW=1
ROOT=$(git rev-parse --show-toplevel)
cd "$ROOT"
W=/tmp/souliong-upgrade
mkdir -p "$W"
if [ -n "${UPGRADE_HELPER_DIR:-}" ]; then cp "$UPGRADE_HELPER_DIR/upgrade_data.php" "$W/"; else git show origin/main:tools/upgrade_data.php > "$W/upgrade_data.php"; fi

cat > "$W/spotmig.php" <<'P'
<?php
// 對每個專案：有靜態底稿還沒併入 spots.jsonl 就補上（等同開頁時的自動遷移）。
$root = $argv[1];
require $root . '/api/spotlib.php';
$cfg = ['projects_dir' => $root . '/projects'];
foreach (array_slice($argv, 2) as $p) {
    if (!spotmigrate_needed($cfg, $p)) { echo "$p: 靜態底稿已併入或沒有，略過\n"; continue; }
    $r = spotmigrate_run($cfg, $p);
    echo "$p: 併入 " . count($r['added']) . " 個地點、改指 " . count($r['repointed']) . " 筆編輯\n";
}
P

PROJECTS=()
for d in projects/*/; do
  p=$(basename "$d")
  [ -f "$d/meta.json" ] || [ -f "$d/data.jsonl" ] || continue
  PROJECTS+=("$p")
done
echo "專案：${PROJECTS[*]}"

if [ $PREVIEW -eq 1 ]; then
  for p in "${PROJECTS[@]}"; do php "$W/upgrade_data.php" split "projects/$p"; done
  exit 0
fi

at() { git reset --hard -q "$1"; echo; echo "=== 切到 $(git log -1 --format='%h %s' HEAD) ==="; }
trap 'echo; echo "!! 升級中斷：目前程式碼停在 $(git log -1 --format=%h HEAD)，資料未被刪除。把上面的輸出貼回來。"' ERR

at df81aba
for p in "${PROJECTS[@]}"; do php "$W/upgrade_data.php" split "projects/$p" --apply; done
php "$W/spotmig.php" "$ROOT" "${PROJECTS[@]}"
for p in "${PROJECTS[@]}"; do php "$W/upgrade_data.php" features "projects/$p" --apply; done
for p in "${PROJECTS[@]}"; do
  echo "-- retirecheck $p"
  php tools/retirecheck.php "projects/$p" | tail -4 || true
  [ "${PIPESTATUS[0]}" -eq 0 ] || { echo "核對未通過：$p"; false; }
done

at 3e85c83
for p in "${PROJECTS[@]}"; do php tools/soundcontent_migrate.php "projects/$p" --apply; done

at origin/main
for p in "${PROJECTS[@]}"; do echo "-- content_migrate $p"; php tools/content_migrate.php "projects/$p" --apply; done
./deploy.sh --fix-perms-only || echo "權限修復未完成：請另外執行 DEPLOY_WEB_USER=<網頁使用者> ./deploy.sh --fix-perms-only"
systemctl reload php*-fpm 2>/dev/null || true
trap - ERR
echo
echo "完成。目前版本：$(git log -1 --format='%h %s' HEAD)"
echo "請開地圖頁確認；data.jsonl 已保留未刪，確認一切正常後再自行決定是否刪除。"
