#!/usr/bin/env bash
# Update the local FrankenPHP binary to the latest GitHub release.
# Usage: bash scripts/update-frankenphp.sh [--force]
#        npm run update:frankenphp [-- --force]
#
# --force  skip the confirmation prompt (CI/CD usage)

set -euo pipefail

GREEN='\033[0;32m'
YELLOW='\033[0;33m'
RED='\033[0;31m'
BOLD='\033[1m'
DIM='\033[2m'
RESET='\033[0m'

FORCE=0
for arg in "$@"; do
  [[ "$arg" == "--force" ]] && FORCE=1
done

BINARY="./frankenphp"
REPO="dunglas/frankenphp"

# ── Current version ───────────────────────────────────────────────────────────

if [[ ! -f "$BINARY" ]]; then
  printf "${RED}Error:${RESET} %s not found in current directory.\n" "$BINARY" >&2
  exit 1
fi

CURRENT_VERSION=$("$BINARY" --version 2>/dev/null | grep -oE 'v[0-9]+\.[0-9]+\.[0-9]+' | head -1)
if [[ -z "$CURRENT_VERSION" ]]; then
  printf "${RED}Error:${RESET} could not determine current FrankenPHP version.\n" >&2
  exit 1
fi

# ── Platform detection ────────────────────────────────────────────────────────

OS=$(uname -s)
ARCH=$(uname -m)

case "$OS" in
  Linux)
    OS_NAME="linux"
    case "$ARCH" in
      x86_64)  ARCH_NAME="x86_64" ;;
      aarch64) ARCH_NAME="aarch64" ;;
      *) printf "${RED}Error:${RESET} unsupported architecture: %s\n" "$ARCH" >&2; exit 1 ;;
    esac
    ;;
  Darwin)
    OS_NAME="mac"
    case "$ARCH" in
      x86_64) ARCH_NAME="x86_64" ;;
      arm64)  ARCH_NAME="arm64" ;;
      *) printf "${RED}Error:${RESET} unsupported architecture: %s\n" "$ARCH" >&2; exit 1 ;;
    esac
    ;;
  *)
    printf "${RED}Error:${RESET} unsupported OS: %s\n" "$OS" >&2
    exit 1
    ;;
esac

ASSET_NAME="frankenphp-${OS_NAME}-${ARCH_NAME}"

# ── Latest release ────────────────────────────────────────────────────────────

printf "  Checking latest FrankenPHP release…\n"
RELEASE_JSON=$(curl -sL "https://api.github.com/repos/${REPO}/releases/latest")
LATEST_VERSION=$(echo "$RELEASE_JSON" | jq -r '.tag_name')
DOWNLOAD_URL=$(echo "$RELEASE_JSON" | jq -r ".assets[] | select(.name == \"${ASSET_NAME}\") | .browser_download_url")

if [[ -z "$DOWNLOAD_URL" || "$DOWNLOAD_URL" == "null" ]]; then
  printf "${RED}Error:${RESET} no binary found for %s in release %s.\n" "$ASSET_NAME" "$LATEST_VERSION" >&2
  exit 1
fi

# ── Already up to date? ───────────────────────────────────────────────────────

if [[ "$CURRENT_VERSION" == "$LATEST_VERSION" ]]; then
  printf "  ${GREEN}✔${RESET}  FrankenPHP is already up to date ${DIM}(%s)${RESET}\n\n" "$CURRENT_VERSION"
  exit 0
fi

# ── Summary + confirmation ────────────────────────────────────────────────────

echo ""
printf "  ${BOLD}FrankenPHP update available${RESET}\n"
printf "  Current  ${DIM}%s${RESET}\n" "$CURRENT_VERSION"
printf "  Latest   ${GREEN}${BOLD}%s${RESET}\n" "$LATEST_VERSION"
printf "  Binary   ${DIM}%s${RESET}\n" "$ASSET_NAME"
echo ""

if [[ "$FORCE" -eq 0 ]]; then
  printf "  Proceed? ${DIM}[y/N]${RESET} "
  read -r CONFIRM
  case "$CONFIRM" in
    [yY]|[yY][eE][sS]) echo "" ;;
    *) printf "  Aborted.\n\n"; exit 0 ;;
  esac
fi

# ── Download & replace ────────────────────────────────────────────────────────

TMPFILE=$(mktemp)
trap 'rm -f "$TMPFILE"' EXIT

printf "  Downloading %s…\n" "$LATEST_VERSION"
curl -L --progress-bar -o "$TMPFILE" "$DOWNLOAD_URL"
chmod +x "$TMPFILE"
mv "$TMPFILE" "$BINARY"

echo ""
printf "  ${GREEN}✔${RESET}  Updated ${DIM}%s${RESET} → ${GREEN}${BOLD}%s${RESET}\n" "$CURRENT_VERSION" "$LATEST_VERSION"
BUNDLED_PHP=$("$BINARY" --version 2>/dev/null | grep -oE 'PHP v[0-9]+\.[0-9]+\.[0-9]+' | head -1 || true)
[[ -n "$BUNDLED_PHP" ]] && printf "  ${DIM}Bundled %s${RESET}\n" "$BUNDLED_PHP"

# Restore capability to bind privileged ports (lost when binary is replaced)
if sudo setcap cap_net_bind_service=+ep "$BINARY" 2>/dev/null; then
  printf "  ${DIM}cap_net_bind_service restored${RESET}\n"
else
  printf "  ${YELLOW}Warning:${RESET} could not restore cap_net_bind_service — run: sudo setcap cap_net_bind_service=+ep %s\n" "$BINARY"
fi
echo ""
