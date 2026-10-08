#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
source tools/deploy-output.sh

case "${1:-}" in
  -h|--help|help)
    cat <<'EOF'
用法：bash tools/setup-social-preview.sh [--check]
安裝社群預覽所需的 Playwright 與 Chromium，檔案放在 state/social-preview-runtime。
--check 只檢查，不安裝。使用 root 執行時會一併安裝瀏覽器系統依賴。
需要 Node.js 18 以上與 npm；未提供時以官方 Node.js 22 下載並驗證校驗碼。
不修改專案資料、帳號或 api/config.php。
EOF
    exit 0 ;;
  ''|--check) ;;
  *) fail "不支援的選項：$1"; exit 2 ;;
esac

runtime="$(pwd)/state/social-preview-runtime"
step "社群預覽環境"
node_command="$(command -v node || true)"
if [ -x "$runtime/node/bin/node" ]; then node_command="$runtime/node/bin/node"; fi
node_ready() { [ -n "$node_command" ] && "$node_command" -e 'process.exit(Number(process.versions.node.split(".")[0]) >= 18 ? 0 : 1)' >/dev/null 2>&1; }
browser_ready() {
  [ -x /usr/bin/chromium ] || [ -x /usr/bin/chromium-browser ] ||
    compgen -G "$runtime/browsers/chromium-*/chrome-linux*/chrome" >/dev/null
}
font_ready() {
  for font_path in /usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc /usr/share/fonts/truetype/noto/NotoSansTC-Regular.ttf /usr/share/fonts/truetype/noto/NotoSansCJK-Regular.ttc /usr/share/fonts/opentype/noto/NotoSansCJKtc-Regular.otf; do
    [ ! -r "$font_path" ] || return 0
  done
  return 1
}
if [ "${1:-}" = --check ]; then
  ready=1
  if node_ready; then ok "Node.js 版本符合需求"; else warn "尚未提供 Node.js 18 以上"; ready=0; fi
  if [ -f "$runtime/node_modules/playwright-core/package.json" ]; then ok "Playwright 已就緒"; else warn "尚未安裝預覽用 Playwright"; ready=0; fi
  if browser_ready; then ok "Chromium 已就緒"; else warn "尚未安裝 Chromium"; ready=0; fi
  if command -v php >/dev/null && php -r 'exit(function_exists("imagecreatetruecolor") && function_exists("imagettftext") ? 0 : 1);'; then
    ok "PHP GD／FreeType 已就緒"
  else
    warn "尚未確認 PHP GD／FreeType；請安裝對應 PHP 版本的 GD 模組"
    ready=0
  fi
  if command -v php >/dev/null && php -r 'exit(function_exists("proc_open") ? 0 : 1);'; then
    ok "PHP 可啟動地圖預覽程序"
  else
    warn "PHP 尚未提供 proc_open；地圖預覽會使用淡底"
    ready=0
  fi
  if font_ready; then ok "中文字型已就緒"; else warn "尚未提供中文字型"; ready=0; fi
  [ "$ready" -eq 1 ]
  exit
fi

mkdir -p "$runtime"
if ! node_ready; then
  for command in curl sha256sum tar xz; do command -v "$command" >/dev/null || { fail "缺少指令：$command"; exit 1; }; done
  case "$(uname -m)" in x86_64) node_arch=x64 ;; aarch64|arm64) node_arch=arm64 ;; *) fail "此架構請自行安裝 Node.js 18 以上"; exit 1 ;; esac
  node_tmp="$(mktemp -d)"
  trap 'rm -rf "$node_tmp"' EXIT
  curl --connect-timeout 15 --max-time 180 --fail --silent --show-error --location --proto '=https' --proto-redir '=https' \
    https://nodejs.org/dist/latest-v22.x/SHASUMS256.txt -o "$node_tmp/SHASUMS256.txt"
  checksum_row="$(awk -v arch="$node_arch" '$2 ~ ("^node-v22\\.[0-9]+\\.[0-9]+-linux-" arch "\\.tar\\.xz$") {print $1 "  " $2}' "$node_tmp/SHASUMS256.txt")"
  [ "$(printf '%s\n' "$checksum_row" | wc -l)" -eq 1 ] && [[ "$checksum_row" =~ ^[0-9a-f]{64}\ \ node-v22\.[0-9]+\.[0-9]+-linux-(x64|arm64)\.tar\.xz$ ]] || { fail "無法確認 Node.js 官方檔案與校驗碼"; exit 1; }
  node_archive="${checksum_row##*  }"
  curl --connect-timeout 15 --max-time 180 --fail --silent --show-error --location --proto '=https' --proto-redir '=https' \
    "https://nodejs.org/dist/latest-v22.x/$node_archive" -o "$node_tmp/$node_archive"
  printf '%s\n' "$checksum_row" > "$node_tmp/checksum"
  (cd "$node_tmp" && sha256sum --check checksum)
  mkdir -p "$runtime/node"
  tar -xJf "$node_tmp/$node_archive" --strip-components=1 -C "$runtime/node"
  node_command="$runtime/node/bin/node"
  ok "Node.js 已安裝至預覽專用目錄"
fi
node_ready || { fail "Node.js 版本不符合需求"; exit 1; }
export PATH="$(dirname "$node_command"):$PATH"
command -v npm >/dev/null || { fail "缺少 npm，請補裝 Node.js 的 npm"; exit 1; }
if ! npm_config_update_notifier=false npm install --prefix "$runtime" --cache "$runtime/npm-cache" --no-audit --no-fund --save-exact playwright-core@1.63.0 > "$runtime/npm-install.log" 2>&1; then
  cat "$runtime/npm-install.log"
  fail "Playwright 安裝失敗"
  exit 1
fi
export PLAYWRIGHT_BROWSERS_PATH="$runtime/browsers"
if browser_ready; then
  ok "沿用已安裝的 Chromium"
elif [ "$(id -u)" -eq 0 ]; then
  "$node_command" "$runtime/node_modules/playwright-core/cli.js" install --with-deps chromium
else
  "$node_command" "$runtime/node_modules/playwright-core/cli.js" install chromium
  warn "若缺少系統函式庫，請以 root 重新執行此指令"
fi
ok "Playwright 與 Chromium 已安裝"
if font_ready; then
  ok "中文字型已就緒"
elif [ "$(id -u)" -eq 0 ] && command -v apt-get >/dev/null; then
  apt-get update
  apt-get install -y fonts-noto-cjk
  ok "中文字型已安裝"
else
  warn "未確認中文字型；可安裝 fonts-noto-cjk 或設定 social_preview_font"
fi
warn "請確認 PHP 執行身分可讀取預覽環境，並已設定可信網站網址"
