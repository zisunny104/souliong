#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"

# 有顏色的終端機才上色，避免 log 檔案裡混進一堆 ANSI 逃脫碼
if [ -t 1 ]; then
  BOLD=$'\033[1m'; DIM=$'\033[2m'
  RED=$'\033[31m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; CYAN=$'\033[36m'
  RESET=$'\033[0m'
else
  BOLD=''; DIM=''; RED=''; GREEN=''; YELLOW=''; CYAN=''; RESET=''
fi

step() { echo "${BOLD}${CYAN}$1${RESET}"; }
ok()   { echo "  ${GREEN}✓${RESET} $1"; }
warn() { echo "  ${YELLOW}!${RESET} $1"; }
fail() { echo "  ${RED}✗${RESET} $1"; }

BRANCH="${DEPLOY_BRANCH:-main}"

# souliong 是純 PHP＋檔案儲存，沒有資料庫、沒有編譯步驟。
# 流程：fetch → 用獨立 worktree 跑過新版本的 tools/checkall.php → fast-forward 合併 → 選用重載 PHP。

step "檢查 working tree"
# 伺服器上的檔案被手動改過時，git fast-forward merge 會中途失敗；先擋下來，講清楚是哪些檔案。
DIRTY="$(git status --porcelain --untracked-files=no)"
if [ -n "$DIRTY" ]; then
  fail "有尚未 commit 的修改，部署已中止（怕蓋掉伺服器上的手動修改）："
  sed 's/^/    /' <<< "$DIRTY"
  echo "  ${DIM}確認不需要之後，用 git checkout -- <檔案> 還原，再重新執行 ./deploy.sh${RESET}"
  exit 1
fi
ok "沒有未 commit 的修改"

HAS_PHP=0
if command -v php >/dev/null 2>&1; then
  HAS_PHP=1
  ok "PHP CLI：$(php -r 'echo PHP_VERSION;')"
else
  warn "找不到 php 指令，會略過部署前檢查：有問題的程式碼不會被擋在部署之前（網頁的 PHP-FPM 不受影響）"
fi

echo
step "Fetch 最新程式碼"
BEFORE=$(git rev-parse --short HEAD)
git fetch --quiet origin "$BRANCH"
AFTER=$(git rev-parse --short FETCH_HEAD)
ok "remote ${BRANCH}：${AFTER}"

if [ "$(git rev-parse HEAD)" != "$(git rev-parse FETCH_HEAD)" ] && ! git merge-base --is-ancestor HEAD FETCH_HEAD; then
  # local 有 remote 沒有的 commit（或兩邊 diverge）：fast-forward 做不到，硬 merge 會在伺服器上產生 merge commit，都不是預期的部署結果
  fail "local（${BEFORE}）不是 remote（${AFTER}）的 ancestor，無法 fast-forward，部署已中止"
  echo "  ${DIM}伺服器上不該有 remote 沒有的 commit；請確認後再處理（例如 git log ${AFTER}..HEAD 看多出什麼）${RESET}"
  exit 1
fi

if [ "$BEFORE" = "$AFTER" ]; then
  echo
  warn "已經是最新版本（${AFTER}），沒有新的變更"
else
  echo
  step "部署前完整檢查新版本"
  echo "  ${DIM}git worktree 跑 tools/checkall.php（php -l／authlint／authcheck／contentcheck）${RESET}"
  # 在 merge「之前」就檢查：把 FETCH_HEAD 的內容放到旁邊一個獨立 worktree 去跑 checkall，
  # 有錯就中止，線上的檔案完全沒動。merge 之後才發現，網站已經是壞的了。
  # authcheck／contentcheck 會自己另開臨時沙盒跑，但啟動時仍需要讀到 api/config.php
  # （機密設定，没進版控），所以複製現有那份進 worktree，用完整個 worktree 一起丟棄，
  # 不會留下任何痕跡、也不會動到正式的 api/config.php。
  if [ "$HAS_PHP" -eq 1 ]; then
    if [ ! -f api/config.php ]; then
      fail "找不到 api/config.php，authcheck／contentcheck 無法啟動沙盒，部署已中止"
      echo "  ${DIM}首次部署請先依下方「機密設定」的說明手動建立 api/config.php，再重新執行${RESET}"
      exit 1
    fi
    WT="$(mktemp -d)"
    rmdir "$WT"
    cleanup_wt() { git worktree remove --force "$WT" >/dev/null 2>&1 || true; }
    trap cleanup_wt EXIT
    git worktree add --quiet --detach "$WT" FETCH_HEAD
    cp api/config.php "$WT/api/config.php"
    if (cd "$WT" && php tools/checkall.php); then
      ok "checkall 全部通過（新版本 ${AFTER}）"
    else
      fail "checkall 在新版本（${AFTER}）上失敗（細節見上方輸出），部署已中止，線上檔案沒有變動"
      exit 1
    fi
    trap - EXIT
    cleanup_wt
  else
    warn "略過（沒有 php 指令）"
  fi

  echo
  step "更新程式碼"
  git merge --ff-only --quiet FETCH_HEAD
  ok "已更新：${DIM}${BEFORE}${RESET} → ${GREEN}${BOLD}${AFTER}${RESET}"
  echo "  ${DIM}此次更新的變更：${RESET}"
  git log --oneline "${BEFORE}..${AFTER}" | sed 's/^/    /'
fi

# 選用：PHP 開了 opcache 且不檢查檔案時間戳（validate_timestamps=0）的伺服器，
# 換了檔案要重載 PHP-FPM 才會生效；用環境變數帶進來，例如
#   DEPLOY_RELOAD_CMD="systemctl reload php8.3-fpm" ./deploy.sh
if [ -n "${DEPLOY_RELOAD_CMD:-}" ] && [ "$BEFORE" != "$AFTER" ]; then
  echo
  step "重載 PHP"
  if bash -c "$DEPLOY_RELOAD_CMD"; then
    ok "${DEPLOY_RELOAD_CMD}"
  else
    fail "重載失敗：${DEPLOY_RELOAD_CMD}（程式碼已更新，請手動重載 PHP-FPM）"
    exit 1
  fi
fi

echo
step "機密設定與可寫目錄"
echo "  ${DIM}改密鑰、改權限都需要人判斷，腳本只檢查、不代勞${RESET}"
if [ ! -f api/config.php ]; then
  fail "api/config.php 不存在——幾乎每一支 api/*.php 都會 require 它，整站目前無法運作"
  echo "  ${DIM}手動執行：cp api/config.example.php api/config.php，再編輯填入 primary_pin／ip_salt 等機密值${RESET}"
  echo "  ${DIM}這一步刻意不自動做——自動產生等於用範本裡的預設密鑰上線，不安全${RESET}"
else
  ok "api/config.php 存在"
  if [ "$HAS_PHP" -eq 1 ]; then
    CFG_WARN="$(php -r '
      $c = require "api/config.php";
      $w = [];
      if (($c["primary_pin"] ?? "") === "CHANGE-ME") $w[] = "primary_pin 仍是範本預設值 CHANGE-ME";
      if (($c["ip_salt"] ?? "") === "CHANGE-ME-隨機鹽值") $w[] = "ip_salt 仍是範本預設值";
      if (($c["debug"] ?? false) === true) $w[] = "debug 仍是 true，上線穩定後建議設 false（否則錯誤會回傳內部細節）";
      if (($c["trust_forwarded"] ?? false) === false) $w[] = "trust_forwarded 是 false——若這台伺服器前面有 Nginx 反代請設 true，否則所有訪客會共用一個 IP（限流、統計都會失準）";
      foreach ((array)($c["default_layers"] ?? []) as $l) {
        if (strpos((string)$l, "carto-") === 0) { $w[] = "default_layers 含已封存的 carto-*（$l），建議改用 paper-ink 或 openfreemap-liberty"; break; }
      }
      echo implode("\n", $w);
    ' 2>/dev/null || true)"
    if [ -n "$CFG_WARN" ]; then
      while IFS= read -r line; do warn "$line"; done <<< "$CFG_WARN"
    else
      ok "primary_pin／ip_salt／debug／trust_forwarded／default_layers 都不是預設值"
    fi
  fi
fi
for d in projects state; do
  if [ -d "$d" ] && [ -w "$d" ]; then
    ok "$d/ 可寫（以目前執行者身分測試；php-fpm 執行者若是不同帳號，仍請另外確認）"
  else
    warn "$d/ 以目前執行者身分測試為不可寫：chown -R <php-fpm 使用者> $d && chmod -R 775 $d"
  fi
done
echo "  ${DIM}Nginx 封鎖 projects/、state/ 直接存取／HTTPS／上傳大小限制等系統層級設定不在這支腳本涵蓋範圍，請對照 docs/DEPLOY.md「一、上線前必做」在首次部署或變更伺服器環境時逐項確認${RESET}"

echo
echo "${DIM}------------------------------------------------------------${RESET}"
step "部署完成"
if [ "$HAS_PHP" -eq 1 ]; then
  VERSION="$(php -r '$c = require "config.php"; echo $c["version"] ?? "?";' 2>/dev/null || echo '?')"
  echo "  應用版本：${BOLD}v${VERSION}${RESET}"
fi
echo "  目前 commit：${BOLD}$(git rev-parse --short HEAD)${RESET}"
echo "  完成時間：${DIM}$(date '+%Y-%m-%d %H:%M:%S')${RESET}"
