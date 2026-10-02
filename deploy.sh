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


# ── 選用：修復路徑權限（./deploy.sh --fix-perms[-only] [--dry-run]）──────────────────
# 預設只檢查不代勞；明確加旗標才動檔案。只動 projects/、state/、api/config.php：
# 目錄 2775（setgid，新檔繼承群組）、檔案 664、擁有者＝php-fpm 使用者；config.php 640（含機密，不給 other 讀）。
# php-fpm 使用者：DEPLOY_WEB_USER 指定，否則從執行中的 php-fpm／apache／nginx 行程偵測。需要 root 或 sudo。
FIX_PERMS=1; FIX_ONLY=0; DRY_RUN=0; AUTO=1
for arg in "$@"; do
  case "$arg" in
    --fix-perms)      FIX_PERMS=1; AUTO=0 ;;
    --no-fix-perms)   FIX_PERMS=0 ;;
    --fix-perms-only) FIX_PERMS=1; FIX_ONLY=1; AUTO=0 ;;
    --dry-run)        DRY_RUN=1 ;;
    -h|--help) echo "用法：./deploy.sh [--fix-perms-only] [--no-fix-perms] [--dry-run]"; exit 0 ;;
    *) fail "未知參數：$arg"; exit 2 ;;
  esac
done

detect_web_user() {
  local u=""
  if [ -n "${DEPLOY_WEB_USER:-}" ]; then echo "$DEPLOY_WEB_USER"; return; fi
  u="$(ps -eo user=,comm= 2>/dev/null | awk '$2 ~ /^(php-fpm|php-cgi|apache2|httpd|nginx)/ && $1 != "root" {print $1; exit}')"
  if [ -z "$u" ]; then
    for c in www-data nginx apache http; do id "$c" >/dev/null 2>&1 && { u="$c"; break; }; done
  fi
  echo "$u"
}

fix_perms() {
  step "修復路徑權限"
  local WEB_USER WEB_GROUP SUDO=""
  WEB_USER="$(detect_web_user)"
  if [ -z "$WEB_USER" ] || ! id "$WEB_USER" >/dev/null 2>&1; then
    fail "找不到 php-fpm 使用者；請指定：DEPLOY_WEB_USER=www-data ./deploy.sh --fix-perms"
    return 1
  fi
  WEB_GROUP="$(id -gn "$WEB_USER" 2>/dev/null || id -g "$WEB_USER")"
  echo "  ${DIM}目標：${WEB_USER}:${WEB_GROUP}　範圍：projects/ state/ api/config.php${RESET}"
  if [ "$(id -u)" -ne 0 ]; then
    if [ "$AUTO" -eq 1 ] && ! { command -v sudo >/dev/null 2>&1 && sudo -n true 2>/dev/null; }; then
      warn "非 root 且無免密碼 sudo，略過自動修復；需要時用 root／sudo 執行 ./deploy.sh --fix-perms-only"; return 0
    fi
    if command -v sudo >/dev/null 2>&1; then SUDO="sudo"; else
      fail "需要 root 才能 chown；請用 root 或安裝 sudo 後重跑"; return 1
    fi
  fi
  local d bad_o bad_d bad_f
  for d in projects state; do
    [ -d "$d" ] || { warn "$d/ 不存在，略過"; continue; }
    bad_o="$(find "$d" ! -user "$WEB_USER" 2>/dev/null | wc -l | tr -d ' ')"
    bad_d="$(find "$d" -type d ! -perm 2775 2>/dev/null | wc -l | tr -d ' ')"
    bad_f="$(find "$d" -type f ! -perm 664 2>/dev/null | wc -l | tr -d ' ')"
    if [ "$DRY_RUN" -eq 1 ]; then
      warn "$d/：擁有者不符 ${bad_o}、目錄權限不符 ${bad_d}、檔案權限不符 ${bad_f}（dry-run，未修改）"
      continue
    fi
    if [ "$((bad_o + bad_d + bad_f))" -eq 0 ]; then ok "$d/：權限已正確"; continue; fi
    $SUDO chown -R "$WEB_USER:$WEB_GROUP" "$d"
    $SUDO find "$d" -type d -exec chmod 2775 {} +
    $SUDO find "$d" -type f -exec chmod 664 {} +
    ok "$d/：已修正（擁有者 ${bad_o}、目錄 ${bad_d}、檔案 ${bad_f} 項）"
  done
  if [ -f api/config.php ]; then
    if [ "$DRY_RUN" -eq 1 ]; then
      warn "api/config.php：目前 $(stat -c '%a %U:%G' api/config.php 2>/dev/null || echo '?')，將設為 640（dry-run，未修改）"
    else
      if [ "$(stat -c %a api/config.php 2>/dev/null)" = "640" ]; then ok "api/config.php：已是 640"; else
      $SUDO chgrp "$WEB_GROUP" api/config.php
      $SUDO chmod 640 api/config.php
      ok "api/config.php：640，群組 ${WEB_GROUP}（機密不給 other 讀）"; fi
    fi
  fi
  echo
}

if [ "$FIX_ONLY" -eq 1 ]; then
  fix_perms
  exit $?
fi

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
  if ! php -r 'exit(PHP_VERSION_ID >= 80000 ? 0 : 1);'; then
    fail "PHP 版本太舊：souliong 需要 PHP 8.0 以上（建議 8.2／8.3），舊版會在載入 api/auth.php 時直接 500。請先升級或另裝 PHP-FPM 8.x"
    exit 1
  fi
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
if [ "$FIX_PERMS" -eq 1 ]; then fix_perms || true; fi
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
    warn "$d/ 以目前執行者身分測試為不可寫：可執行 ./deploy.sh --fix-perms 自動修復（需 root／sudo）"
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
