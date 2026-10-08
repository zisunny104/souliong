#!/usr/bin/env bash
# 測試 deploy.sh --check-only 的外洩檢查：state／projects／.git 是否可被直接下載。
# 用 PHP 內建伺服器模擬擋住、外洩、非預期內容、轉址等情況，埠號向系統要空閒埠。
# 用法：bash tools/deploycheck.sh　　全部符合預期結束碼 0
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
command -v php >/dev/null 2>&1 || { echo "找不到 php"; exit 1; }
command -v curl >/dev/null 2>&1 || { echo "找不到 curl"; exit 1; }

TMP="$(mktemp -d)"
PIDFILE="$TMP/server.pid"
stop_server() { [ -f "$PIDFILE" ] && kill "$(cat "$PIDFILE")" 2>/dev/null; rm -f "$PIDFILE"; return 0; }
trap 'stop_server; rm -rf "$TMP"' EXIT

# 假站台：只放 deploy.sh 與它檢查會讀的檔案，不碰真正的 state／projects
SITE="$TMP/site"
mkdir -p "$SITE/api" "$SITE/tools" "$SITE/state" "$SITE/projects"
cp "$ROOT/deploy.sh" "$SITE/"
cp "$ROOT/tools/deploy-output.sh" "$SITE/tools/"
for api_file in "$ROOT"/api/*.php; do
  [ "$(basename "$api_file")" = config.php ] || cp "$api_file" "$SITE/api/"
done
cp "$ROOT/api/config.example.php" "$SITE/api/config.php"
cp "$ROOT/tools/admin_setup.php" "$SITE/tools/"
cat > "$TMP/router_block.php" <<'P'
<?php
if (preg_match('#/(state|projects|\.git)/#', $_SERVER['REQUEST_URI'])) { http_response_code(403); echo 'Forbidden'; return true; }
return false;
P
cat > "$TMP/router_block404.php" <<'P'
<?php
if (preg_match('#/(state|projects|\.git)/#', $_SERVER['REQUEST_URI'])) { http_response_code(404); echo 'Not Found'; return true; }
return false;
P
cat > "$TMP/router_open.php" <<'P'
<?php return false;
P
# 只有 .git 外洩：state／projects 擋住
cat > "$TMP/router_gitonly.php" <<'P'
<?php
if (preg_match('#/(state|projects)/#', $_SERVER['REQUEST_URI'])) { http_response_code(403); return true; }
return false;
P
# 全站 200 但內容不是預期（例如檢查網址指到別的站）
cat > "$TMP/router_notgit.php" <<'P'
<?php http_response_code(200); echo '<html>hello</html>'; return true;
P
cat > "$TMP/router_redirect.php" <<'P'
<?php header('Location: https://example.com/', true, 302); return true;
P
mkdir -p "$SITE/.git"
printf 'ref: refs/heads/main\n' > "$SITE/.git/HEAD"

FAILED=0
expect() { # 名稱 條件結果
  if [ "$2" -eq 0 ]; then echo "通過  $1"; else echo "失敗  $1"; FAILED=$((FAILED + 1)); fi
}

free_port() { python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()'; }
command -v python3 >/dev/null 2>&1 || { echo "找不到 python3"; exit 1; }

start_server() { # router，設定 PORT
  PORT="$(free_port)"
  php -S "127.0.0.1:$PORT" -t "$SITE" "$TMP/$1" >/dev/null 2>&1 &
  echo $! > "$PIDFILE"
  local i
  for i in 1 2 3 4 5 6 7 8 9 10; do curl -s -o /dev/null --max-time 1 "http://127.0.0.1:$PORT/" && break; sleep 0.3; done
}

run_case() { # 名稱 router 預期結束碼 預期輸出片段 不應出現的片段
  local name="$1" router="$2" want_code="$3" want_text="$4" deny_text="$5" out code
  start_server "$router"
  out="$(cd "$SITE" && DEPLOY_CHECK_URL="http://127.0.0.1:$PORT" bash ./deploy.sh --check-only 2>&1)"; code=$?
  stop_server
  [ "$code" -eq "$want_code" ]; expect "$name：結束碼 $want_code，實際 $code" $?
  grep -qF "$want_text" <<< "$out"; expect "$name：輸出含「$want_text」" $?
  if [ -n "$deny_text" ]; then ! grep -qF "$deny_text" <<< "$out"; expect "$name：輸出不含「$deny_text」" $?; fi
  [ "${SHOW:-0}" = 1 ] && echo "$out"
  return 0
}

run_case "全沒擋" router_open.php 1 "state/ 可被下載" "state/ 已擋住"
run_case "全沒擋" router_open.php 1 ".git/ 可被下載" ".git/ 已擋住"
run_case "全沒擋，nginx 片段含 .git" router_open.php 1 'location ~ /\.git { deny all; return 404; }' ""
run_case "全沒擋，nginx 片段含 state／projects" router_open.php 1 'location ~ /(state|projects)/ { deny all; return 404; }' ""
run_case "全沒擋，有修法與重測提示" router_open.php 1 "./deploy.sh --check-only 重測" ""
run_case "只漏 .git" router_gitonly.php 1 ".git/ 可被下載" "state/ 可被下載"
run_case "擋住回 403" router_block.php 0 ".git/ 已擋住" "可被下載"
run_case "擋住回 403，顯示碼" router_block.php 0 "回 403" "可被下載"
run_case "擋住回 404" router_block404.php 0 ".git/ 已擋住" "可被下載"
run_case "200 非 git 不誤報" router_notgit.php 0 ".git/ 回 200 但不是 git 內容" "可被下載"
run_case "3xx 轉址不跟隨" router_redirect.php 0 ".git/ 回 302 轉址，不跟隨" "可被下載"
run_case "3xx 提示最終網址" router_redirect.php 0 "請改用最終網址" ""

# 連不上：用剛釋放、沒人聽的空閒埠
DEADPORT="$(free_port)"
out="$(cd "$SITE" && DEPLOY_CHECK_URL="http://127.0.0.1:$DEADPORT" bash ./deploy.sh --check-only 2>&1)"; code=$?
[ "$code" -eq 0 ]; expect "連不上：不失敗" $?
grep -qF ".git/ 連不上，略過" <<< "$out"; expect "連不上：有提醒" $?

# 沒設網址：略過並提醒，不失敗
out="$(cd "$SITE" && env -u DEPLOY_CHECK_URL bash ./deploy.sh --check-only 2>&1)"; code=$?
[ "$code" -eq 0 ]; expect "沒設網址：不失敗" $?
grep -qF "未設定網址，略過網站檢查" <<< "$out"; expect "沒設網址：有提醒" $?
grep -qF "./deploy.sh --set-check-url https://example.com/project" <<< "$out"; expect "沒設網址：提示設定方式" $?

# --set-check-url：存取、去結尾斜線、錯誤輸入
out="$(cd "$SITE" && env -u DEPLOY_CHECK_URL bash ./deploy.sh --set-check-url "http://127.0.0.1:$DEADPORT/proj/" 2>&1)"; code=$?
[ "$code" -eq 0 ]; expect "--set-check-url：成功結束碼 0" $?
grep -qF "已儲存" <<< "$out"; expect "--set-check-url：印出已儲存" $?
[ "$(cat "$SITE/state/deploy_check_url")" = "http://127.0.0.1:$DEADPORT/proj" ]; expect "--set-check-url：去掉結尾斜線並存檔" $?
out="$(cd "$SITE" && env -u DEPLOY_CHECK_URL bash ./deploy.sh --check-only 2>&1)"
grep -qF ".git/ 連不上" <<< "$out"; expect "沒有環境變數：改用儲存檔的網址" $?
out="$(cd "$SITE" && bash ./deploy.sh --set-check-url "ftp://x" 2>&1)"; code=$?
[ "$code" -eq 2 ]; expect "--set-check-url：錯誤輸入結束碼 2" $?
out="$(cd "$SITE" && bash ./deploy.sh --set-check-url 2>&1)"; code=$?
[ "$code" -eq 2 ]; expect "--set-check-url：缺網址結束碼 2" $?
[ "$(cat "$SITE/state/deploy_check_url")" = "http://127.0.0.1:$DEADPORT/proj" ]; expect "--set-check-url：錯誤輸入不改動儲存檔" $?
out="$(cd "$SITE" && bash ./deploy.sh --help 2>&1)"
grep -qF -- "--set-check-url" <<< "$out" && grep -qF -- "--check-only" <<< "$out"; expect "--help：列出兩個參數" $?

# 環境變數優先於儲存檔：儲存檔指向死埠，環境變數指向會擋住的站台
start_server router_block.php
out="$(cd "$SITE" && DEPLOY_CHECK_URL="http://127.0.0.1:$PORT" bash ./deploy.sh --check-only 2>&1)"; code=$?
stop_server
[ "$code" -eq 0 ]; expect "環境變數優先：結束碼 0" $?
grep -qF ".git/ 已擋住" <<< "$out"; expect "環境變數優先：用的是環境變數網址" $?
rm -f "$SITE/state/deploy_check_url"

echo
if [ "$FAILED" -eq 0 ]; then echo "deploycheck 全部通過"; exit 0; fi
echo "deploycheck $FAILED 項失敗"; exit 1
