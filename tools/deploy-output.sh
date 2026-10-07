#!/usr/bin/env bash
# 各專案保留同一份輸出 helper，可獨立部署，不依賴母專案路徑。
if [ -t 1 ] && [ -z "${NO_COLOR+x}" ] && [ "${TERM:-}" != dumb ]; then
    BOLD=$'\033[1m'; DIM=$'\033[2m'
    RED=$'\033[31m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; CYAN=$'\033[36m'
    RESET=$'\033[0m'
else
    BOLD=''; DIM=''; RED=''; GREEN=''; YELLOW=''; CYAN=''; RESET=''
fi

DEPLOY_OUTPUT_STARTED=0
separator() {
    if [ "$DEPLOY_OUTPUT_STARTED" -eq 0 ]; then
        printf '%s────────────────────────────%s\n' "$DIM" "$RESET"
        DEPLOY_OUTPUT_STARTED=1
    fi
}
step() { separator; printf '%s%s%s\n' "${BOLD}${CYAN}" "$1" "$RESET"; }
status_line() {
    local color="$1" fallback="$2" text="$3" indent="${4-  }" marker
    marker="$fallback"
    printf '%s%s%s%s %s\n' "$indent" "$color" "$marker" "$RESET" "$text"
}
ok() { status_line "$GREEN" '✓' "$1"; }
warn() { status_line "$YELLOW" '!' "$1"; }
fail() { status_line "$RED" '✗' "$1"; }
install_hint() { printf '  %sDebian／Ubuntu 安裝：sudo apt install %s%s\n' "$CYAN" "$1" "$RESET"; }
require_cmd() {
    command -v "$1" >/dev/null 2>&1 && return 0
    fail "缺少 $1，部署已中止"
    install_hint "$2"
    return 1
}
deployment_summary() {
    local version="${1:-}" critical="${2:-0}"
    if [ "$critical" -eq 0 ]; then
        step "部署完成"
    else
        step "部署未完成"
    fi
    [ -z "$version" ] || printf '  專案版本：%sv%s%s\n' "$BOLD" "$version" "$RESET"
    printf '  目前提交：%s%s%s\n' "$BOLD" "$(git rev-parse --short HEAD)" "$RESET"
    printf '  部署時間：%s\n' "$(date '+%Y-%m-%d %H:%M:%S %Z (%z)')"
}
