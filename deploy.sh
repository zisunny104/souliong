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
# 資料夾 2775（setgid，新檔繼承群組）、檔案 664、擁有者＝php-fpm 使用者；config.php 640（含機密，不給 other 讀）。
# php-fpm 使用者：DEPLOY_WEB_USER 指定，否則從執行中的 php-fpm／apache／nginx 行程偵測。需要 root 或 sudo。
# 儲存網站對外網址，供自我檢查使用：./deploy.sh --set-check-url https://example.com/project
if [ "${1:-}" = "--set-check-url" ]; then
  case "${2:-}" in https://*|http://*) ;; *) fail "請提供以 https:// 開頭的網址"; exit 2 ;; esac
  [ -d state ] || { fail "找不到 state/"; exit 1; }
  printf '%s
' "${2%/}" > state/deploy_check_url && ok "已儲存檢查網址"
  exit 0
fi

FIX_PERMS=1; FIX_ONLY=0; DRY_RUN=0; AUTO=1; CHECK_ONLY=0
for arg in "$@"; do
  case "$arg" in
    --fix-perms)      FIX_PERMS=1; AUTO=0 ;;
    --no-fix-perms)   FIX_PERMS=0 ;;
    --fix-perms-only) FIX_PERMS=1; FIX_ONLY=1; AUTO=0 ;;
    --dry-run)        DRY_RUN=1 ;;
    --check-only)     CHECK_ONLY=1; FIX_PERMS=0 ;;
    -h|--help)
      echo "用法：./deploy.sh [--fix-perms-only] [--no-fix-perms] [--check-only] [--dry-run]"
      echo "  --check-only  不更新程式碼，只跑「設定與網站自我檢查」"
      echo "  --set-check-url URL  儲存網站對外網址，之後自我檢查自動使用"
      echo "環境變數：DEPLOY_BRANCH、DEPLOY_WEB_USER、DEPLOY_RELOAD_CMD、DEPLOY_CHECK_URL（網站對外網址，例：https://example.com/project）"
      exit 0 ;;
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
      warn "$d/：擁有者不符 ${bad_o}、資料夾權限不符 ${bad_d}、檔案權限不符 ${bad_f}（dry-run，未修改）"
      continue
    fi
    if [ "$((bad_o + bad_d + bad_f))" -eq 0 ]; then ok "$d/：權限已正確"; continue; fi
    $SUDO chown -R "$WEB_USER:$WEB_GROUP" "$d"
    $SUDO find "$d" -type d -exec chmod 2775 {} +
    $SUDO find "$d" -type f -exec chmod 664 {} +
    ok "$d/：已修正（擁有者 ${bad_o}、資料夾 ${bad_d}、檔案 ${bad_f} 項）"
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

# ── 設定與網站自我檢查（部署後執行；也可單獨跑：./deploy.sh --check-only）──────────────
CANARY_TEXT="souliong-selfcheck-canary-do-not-serve"
CRIT=0

# 向 $1 發 GET（不跟隨轉址、8 秒逾時），輸出「HTTP 狀態碼」與內容比對結果：
#   exposed＝200 且內容是金絲雀；redirect＝3xx；unreach＝連不上；其他為狀態碼本身
probe_canary() {
  local tmp code
  tmp="$(mktemp)"
  code="$(curl -sS --max-time 8 --max-redirs 0 -o "$tmp" -w '%{http_code}' "$1" 2>/dev/null)" || code="000"
  if [ "$code" = "000" ]; then echo "unreach"
  elif [ "$code" = "200" ] && grep -qF "$CANARY_TEXT" "$tmp" 2>/dev/null; then echo "exposed"
  else echo "$code"; fi
  rm -f "$tmp"
}

NGINX_SNIPPET='    location ~ /(state|projects)/ { deny all; return 404; }
    location ~ \.jsonl$ { deny all; return 404; }'

selfcheck_web() {
  local d f base url r exposed=0 notfound=0
  # 金絲雀由腳本建立與維護（已在 .gitignore）；寫不進去就沒辦法測，不當成錯誤
  for d in state projects; do
    f="$d/.canary-selfcheck"
    if [ -d "$d" ] && [ -w "$d" ]; then
      [ "$(cat "$f" 2>/dev/null)" = "$CANARY_TEXT" ] || printf '%s' "$CANARY_TEXT" > "$f"
    fi
  done
  CHECK_URL="${DEPLOY_CHECK_URL:-}"
  [ -n "$CHECK_URL" ] || CHECK_URL="$(head -n1 state/deploy_check_url 2>/dev/null || true)"
  if [ -z "$CHECK_URL" ]; then
    warn "略過「敏感路徑可否被直接下載」檢查：未設定檢查網址，可執行 ./deploy.sh --set-check-url https://example.com/project"
    return 0
  fi
  if ! command -v curl >/dev/null 2>&1; then
    warn "略過「敏感路徑可否被直接下載」檢查：找不到 curl"
    return 0
  fi
  base="${CHECK_URL%/}"
  case "$base" in
    https://*) ok "檢查網址使用 HTTPS" ;;
    http://*)  warn "檢查網址是 http://：定位、相機與刪除判斷（crypto.subtle）都需要安全情境，全站請走 HTTPS" ;;
    *)         warn "檢查網址要以 https:// 或 http:// 開頭：$base"; return 0 ;;
  esac
  for d in state projects; do
    [ -f "$d/.canary-selfcheck" ] || { warn "$d/ 沒有金絲雀檔（資料夾不可寫），略過這一項"; continue; }
    url="$base/$d/.canary-selfcheck"
    r="$(probe_canary "$url")"
    case "$r" in
      exposed)
        exposed=1
        fail "${BOLD}${RED}嚴重：$d/ 可被網頁直接下載${RESET}（$url 回 200 並送出檔案內容）" ;;
      unreach)
        warn "連不上 $url（逾時或網路不通），略過這一項" ;;
      3??)
        warn "$url 回 $r 轉址，腳本不跟隨；請把檢查網址改成最終網址（例如直接用 https://）再測" ;;
      *)
        [ "$r" = 404 ] && notfound=1
        ok "$d/ 無法被直接下載（回 $r）" ;;
    esac
  done
  if [ "$exposed" -eq 1 ]; then
    CRIT=1
    echo "  ${BOLD}${RED}!!! projects/ 內有投稿代碼，state/ 內有明碼的管理 PIN 清單，任何人都能直接下載 !!!${RESET}"
    echo "  ${BOLD}修法：${RESET}把下面兩條貼進 Nginx 的 server { } 區塊（與 listen／root 同一層），再執行 sudo nginx -t && sudo systemctl reload nginx："
    echo "${YELLOW}${NGINX_SNIPPET}${RESET}"
    echo "  ${DIM}完成後重跑 ./deploy.sh --check-only 確認；已外流的 PIN 與投稿代碼請一併更換${RESET}"
  elif [ "$notfound" -eq 1 ]; then
    echo "  ${DIM}（回 404 時，請確認檢查網址指向的是本站，而不是別的站台）${RESET}"
  fi
}

selfcheck_php_ini() {
  [ "$HAS_PHP" -eq 1 ] || return 0
  local out
  # 取各投稿型別上限的最大值（影片 64 MB 等）；max_file_uploads 供切圖磚工具一批 16 張
  out="$(php -r '
    $b = static function ($k) { $v = trim((string)ini_get($k)); $m = ["k"=>1024,"m"=>1048576,"g"=>1073741824][strtolower(substr($v,-1))] ?? 1; $n = (int)((float)$v * $m); return $n > 0 ? $n : PHP_INT_MAX; };
    $cfg = is_file("api/config.php") ? (require "api/config.php") : ["max_bytes" => 12 * 1048576];
    require "api/features.php";
    $need = (int)($cfg["max_bytes"] ?? 0);
    foreach (souliong_kinds() as $d) if (!empty($d["file"])) $need = max($need, (int)($d["max_bytes"] ?? 0));
    $mb = static fn($n) => $n === PHP_INT_MAX ? "不限" : (int)ceil($n / 1048576) . "M";
    $w = [];
    if ($b("upload_max_filesize") < $need) $w[] = "upload_max_filesize=" . $mb($b("upload_max_filesize")) . "（需 ≥ " . $mb($need) . "）";
    if ($b("post_max_size") < $need) $w[] = "post_max_size=" . $mb($b("post_max_size")) . "（需 ≥ " . $mb($need) . "，建議多留幾 MB）";
    if ((int)ini_get("max_file_uploads") < 20) $w[] = "max_file_uploads=" . ini_get("max_file_uploads") . "（需 ≥ 20，否則切圖磚工具一批 16 張會被無聲丟掉）";
    echo implode("; ", $w);
  ' 2>/dev/null || true)"
  if [ -n "$out" ]; then
    warn "PHP CLI 讀到的上傳設定偏小：$out"
    echo "  ${DIM}CLI 與 PHP-FPM 的 php.ini 可能不同，FPM 的 upload_max_filesize／post_max_size 與反向代理的請求大小上限（例如 Nginx 的 client_max_body_size）都需不小於上述需求，post_max_size 與代理上限建議再多留幾 MB${RESET}"
  else
    ok "PHP CLI 的上傳設定足夠（FPM 的 php.ini 與反向代理的請求大小上限請另外確認）"
  fi
}

run_selfcheck() {
  step "設定與網站自我檢查"
  echo "  ${DIM}密鑰與權限需要人判斷，腳本只檢查、不代勞${RESET}"
  if [ ! -f api/config.php ]; then
    fail "api/config.php 不存在——幾乎每一支 api/*.php 都會 require 它，整站目前無法運作"
    echo "  ${DIM}手動執行：cp api/config.example.php api/config.php，再編輯填入 primary_pin／ip_salt，並把 trust_forwarded 設 true（Nginx 反代後）、debug 設 false${RESET}"
    echo "  ${DIM}這一步刻意不自動做——自動產生等於用範本裡的預設密鑰上線，不安全${RESET}"
  else
    ok "api/config.php 存在"
    if [ "$HAS_PHP" -eq 1 ]; then
      CFG_WARN="$(php -r '
        $c = require "api/config.php";
        $w = [];
        if (($c["primary_pin"] ?? "") === "CHANGE-ME") $w[] = "primary_pin 仍是範本預設值 CHANGE-ME，請改成不易猜的 PIN";
        if (($c["ip_salt"] ?? "") === "CHANGE-ME-隨機鹽值") $w[] = "ip_salt 仍是範本預設值，請改成隨機字串";
        if (($c["debug"] ?? false) === true) $w[] = "debug 是 true：錯誤會回傳內部細節，上線穩定後請設 false";
        if (($c["trust_forwarded"] ?? false) === false) $w[] = "trust_forwarded 是 false：若前面有 Nginx 反代請設 true，否則所有訪客共用一個 IP，限流與統計會失準（直接對外、沒有反代則維持 false）";
        foreach ((array)($c["default_layers"] ?? []) as $l) {
          if (strpos((string)$l, "carto-") === 0) { $w[] = "default_layers 含已封存的 carto-*（$l），請改成 [\"paper-ink\"]"; break; }
        }
        echo implode("\n", $w);
      ' 2>/dev/null || true)"
      if [ -n "$CFG_WARN" ]; then
        while IFS= read -r line; do warn "$line"; done <<< "$CFG_WARN"
      else
        ok "primary_pin／ip_salt／debug／trust_forwarded／default_layers 都已調整"
      fi
    fi
  fi
  local d
  for d in projects state; do
    if [ -d "$d" ] && [ -w "$d" ]; then
      ok "$d/ 可寫（以目前執行者身分測試；php-fpm 執行者若是不同帳號，仍請另外確認）"
    else
      warn "$d/ 以目前執行者身分測試為不可寫：可執行 ./deploy.sh --fix-perms 自動修復（需 root／sudo）"
    fi
  done
  selfcheck_php_ini
  selfcheck_web
}

if [ "$CHECK_ONLY" -eq 1 ]; then
  HAS_PHP=0; command -v php >/dev/null 2>&1 && HAS_PHP=1
  run_selfcheck
  [ "$CRIT" -eq 0 ]
  exit $?
fi

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
    fail "PHP 版本太舊，需要 PHP 8.0 以上，請先升級"
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
  # （機密設定，沒進版控），所以複製現有那份進 worktree，用完整個 worktree 一起丟棄，
  # 不會留下任何痕跡、也不會動到正式的 api/config.php。
  if [ "$HAS_PHP" -eq 1 ]; then
    if [ ! -f api/config.php ]; then
      fail "找不到 api/config.php，authcheck／contentcheck 無法啟動沙盒，部署已中止"
      echo "  ${DIM}首次部署請先手動執行 cp api/config.example.php api/config.php 並填入密鑰，再重新執行${RESET}"
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
#   DEPLOY_RELOAD_CMD="systemctl reload php-fpm" ./deploy.sh
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
run_selfcheck
echo
echo "${DIM}------------------------------------------------------------${RESET}"
step "部署完成"
if [ "$HAS_PHP" -eq 1 ]; then
  VERSION="$(php -r '$c = require "config.php"; echo $c["version"] ?? "?";' 2>/dev/null || echo '?')"
  echo "  應用版本：${BOLD}v${VERSION}${RESET}"
fi
echo "  目前 commit：${BOLD}$(git rev-parse --short HEAD)${RESET}"
echo "  完成時間：${DIM}$(date '+%Y-%m-%d %H:%M:%S')${RESET}"
[ "$CRIT" -eq 0 ] || { echo; fail "自我檢查有嚴重問題（見上方 ✗），請先處理"; exit 1; }
