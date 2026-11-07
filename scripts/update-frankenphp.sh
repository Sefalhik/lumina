#!/usr/bin/env bash
# Update the local FrankenPHP binary to the newest GitHub release that is old enough.
# Usage: bash scripts/update-frankenphp.sh [--force] [--min-release-age=<days>]
#        npm run update:frankenphp [-- --force] [-- --min-release-age=<days>]
#
# --force                   skip the confirmation prompt (CI/CD usage) — never a verification
# --min-release-age=<days>  replace, for this run, the delay read from .npmrc
#
# Three things stand between a release and ./frankenphp since LUMN-74, and the binary in place is
# only replaced once all three have passed:
#   1. the answer comes from $REPO, read without following a redirect      → otherwise exit 1
#   2. the release is at least as old as the project's release age         → otherwise exit 0, held
#   3. the downloaded file has the SHA-256 GitHub publishes for that asset → otherwise exit 1
#
# The second is not a failure: nothing is wrong, the release is waiting its turn. It is applied to
# every release, not to the latest alone (LUMN-98): when the latest is too young, the highest
# version that is old enough is installed, as npm and Renovate do with the same delay.
# See docs/dependency-updates.md, "The FrankenPHP binary".

set -euo pipefail

GREEN='\033[0;32m'
YELLOW='\033[0;33m'
RED='\033[0;31m'
BOLD='\033[1m'
DIM='\033[2m'
RESET='\033[0m'

# Every refusal leaves through here. Nothing has been written to $BINARY when it is called.
# Usage: refuse <what is wrong> [<hint>…]
refuse() {
  printf "${RED}Error:${RESET} %s\n" "$1" >&2
  shift
  for hint in "$@"; do
    printf "  → %s\n" "$hint" >&2
  done
  exit 1
}

FORCE=0
MIN_RELEASE_AGE_SOURCE=""
for arg in "$@"; do
  case "$arg" in
    --force) FORCE=1 ;;
    --min-release-age=*)
      MIN_RELEASE_AGE="${arg#--min-release-age=}"
      MIN_RELEASE_AGE_SOURCE="--min-release-age"
      ;;
    # A mistyped option used to be ignored. Ignoring one that was meant to hold a release back
    # would install it.
    *) refuse "unknown argument: ${arg}" "Usage: npm run update:frankenphp [-- --force] [-- --min-release-age=<days>]" ;;
  esac
done

BINARY="./frankenphp"
NPMRC="./.npmrc"
# The project left dunglas/frankenphp for php/frankenphp. The old name still redirects, which is
# the reason not to use it: a redirect followed in silence works until the day it stops, or until
# someone else owns the old name.
REPO="php/frankenphp"
# One page of a hundred: the project published 60 releases from 2023 to 2026-11, newest first, and
# the highest one old enough is always among the most recent.
API_URL="https://api.github.com/repos/${REPO}/releases?per_page=100"

SECONDS_PER_DAY=86400
# Every character is listed rather than given as a range. In a UTF-8 locale — the one this script
# is run from — bash reads `0-9` and `a-f` by collation, and they then match the digits of other
# scripts, or é. What these patterns let through comes from a release, not from this machine.
DIGIT='[0123456789]'
# Whole days, no sign, no leading zero — bash would read 08 as an invalid octal number.
DAYS_PATTERN="^(0|[123456789]${DIGIT}{0,3})$"
VERSION_PATTERN="^v${DIGIT}+\\.${DIGIT}+\\.${DIGIT}+$"
EPOCH_PATTERN="^${DIGIT}+$"
DIGEST_PATTERN='^sha256:[0123456789abcdef]{64}$'

# ── Current version ───────────────────────────────────────────────────────────

if [[ ! -f "$BINARY" ]]; then
  printf "${RED}Error:${RESET} %s not found in current directory.\n" "$BINARY" >&2
  exit 1
fi

# `|| true`: a binary that prints no version makes grep fail, and with `pipefail` the script used to
# stop on this very line, in silence, before it could say what follows.
CURRENT_VERSION=$("$BINARY" --version 2>/dev/null | grep -oE 'v[0-9]+\.[0-9]+\.[0-9]+' | head -1 || true)
if [[ -z "$CURRENT_VERSION" ]]; then
  printf "${RED}Error:${RESET} could not determine current FrankenPHP version.\n" >&2
  exit 1
fi

# ── Release age ───────────────────────────────────────────────────────────────

# The number of days is the project's, not this script's. .npmrc holds it for every install typed
# by hand, and ReleaseAgeParityTest keeps it equal to what Renovate applies: a third copy here
# would be one more number to forget.
if [[ -z "$MIN_RELEASE_AGE_SOURCE" ]]; then
  if [[ ! -f "$NPMRC" ]]; then
    refuse "${NPMRC} not found: it holds the release age this script applies." \
      "Run from the project root, or pass --min-release-age=<days> for this run."
  fi
  MIN_RELEASE_AGE=$(sed -n 's/^min-release-age=//p' "$NPMRC")
  MIN_RELEASE_AGE_SOURCE="$NPMRC"
fi

if [[ ! "$MIN_RELEASE_AGE" =~ $DAYS_PATTERN ]]; then
  refuse "the release age given by ${MIN_RELEASE_AGE_SOURCE} is not a number of days (read: '${MIN_RELEASE_AGE}')." \
    "Expected exactly one whole number, as in min-release-age=3." \
    "A delay that cannot be read is not a delay of zero."
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

# ── The releases ──────────────────────────────────────────────────────────────

RELEASES_FILE=$(mktemp)
RELEASE_FILE=$(mktemp)
TMPFILE=$(mktemp)
trap 'rm -f "$RELEASES_FILE" "$RELEASE_FILE" "$TMPFILE"' EXIT

printf "  Checking FrankenPHP releases…\n"

# No -L. A redirect here means the repository moved again, and the answer is to change $REPO on
# purpose — not to follow whoever answers for the old name by then.
HTTP_CODE=$(curl -sS -o "$RELEASES_FILE" -w '%{http_code}' "$API_URL") ||
  refuse "GitHub could not be reached (${API_URL})."

case "$HTTP_CODE" in
  200) ;;
  301 | 302 | 307 | 308)
    refuse "GitHub answered ${HTTP_CODE} for ${REPO}: the repository has moved." \
      "Find its new name and change REPO in scripts/update-frankenphp.sh. The redirect is deliberately not followed."
    ;;
  *) refuse "GitHub answered ${HTTP_CODE} for ${API_URL}." ;;
esac

ANSWER_TYPE=$(jq -r 'type' "$RELEASES_FILE" 2>/dev/null) || ANSWER_TYPE=""
if [[ "$ANSWER_TYPE" != "array" ]]; then
  refuse "GitHub's answer is not a list of releases this script can read."
fi

# Drafts and pre-releases are never candidates, nor is a release whose two flags do not both read as
# false — what cannot be read is not "published". Neither is a tag of any other shape than vX.Y.Z:
# v1.0.0-rc.2 to rc.4 were published in 2023 without the pre-release flag.
PUBLISHED='[.[] | select(type == "object" and .draft == false and .prerelease == false)]'
PUBLISHED_COUNT=$(jq -r "${PUBLISHED} | length" "$RELEASES_FILE")
if [[ "$PUBLISHED_COUNT" == "0" ]]; then
  refuse "GitHub lists no published release of ${REPO}."
fi

# The newest release is read before any is skipped: tags of another shape are ignored only while
# the latest one still has the expected shape. Otherwise a change of format on GitHub's side would
# leave this script on old versions, in silence.
LATEST_TAG=$(jq -r "${PUBLISHED} | .[0].tag_name // empty" "$RELEASES_FILE")
if [[ ! "$LATEST_TAG" =~ $VERSION_PATTERN ]]; then
  refuse "the latest release carries no version this script reads (tag_name: '${LATEST_TAG}')."
fi

# Every candidate with its version as numbers, compared as such — v1.13.10 is above v1.13.9 — and
# its publication date. A date that cannot be read is null: such a release is never old enough, as
# Renovate decides with timestamp-required. The date is written back and compared: parsing alone
# reads 2026-02-30 as the 2nd of March. Versions are chosen by number, not by date, since a fix of
# an older line can be published after a higher version. What "published" means is ${PUBLISHED},
# read once: a second copy of that filter here was free to drift from the first.
CHOICE=$(jq -r \
  --arg current "$CURRENT_VERSION" \
  --argjson now "$(date +%s)" \
  --argjson required "$((MIN_RELEASE_AGE * SECONDS_PER_DAY))" '
  def version: ltrimstr("v") | split(".") | map(tonumber);
  def epoch: try (. as $date | fromdateiso8601 as $at
    | if ($at | todateiso8601) == $date then $at else null end) catch null;
  '"${PUBLISHED}"'
  | map(select((.tag_name | type) == "string" and (.tag_name | test("^v[0-9]+[.][0-9]+[.][0-9]+$")))
    | {tag: .tag_name, number: (.tag_name | version), at: (.published_at | epoch),
       published: (.published_at // "" | tostring)}) as $candidates
  | ($candidates | max_by(.number)) as $newest
  | [$candidates[] | select(.number > ($current | version))] as $upgrades
  | ($upgrades | map(select(.at != null and $now - .at >= $required)) | max_by(.number)) as $target
  | ($upgrades | map(select(.at != null)) | min_by(.at)) as $next
  | [$newest.tag, ($newest.at // ""), $newest.published,
     ($target.tag // ""), ($target.at // ""), ($target.published // ""),
     ($next.tag // ""), ($next.at // ""),
     (if ($current | version) >= $newest.number then "current" else "behind" end)]
  | join("\u001f")' "$RELEASES_FILE")
IFS=$'\x1f' read -r NEWEST_VERSION NEWEST_EPOCH NEWEST_PUBLISHED \
  TARGET_VERSION TARGET_EPOCH PUBLISHED_AT NEXT_VERSION NEXT_EPOCH STANDING <<<"$CHOICE"

# ── Already up to date? ───────────────────────────────────────────────────────

if [[ "$STANDING" == "current" ]]; then
  printf "  ${GREEN}✔${RESET}  FrankenPHP is already up to date ${DIM}(%s)${RESET}\n\n" "$CURRENT_VERSION"
  exit 0
fi

# ── Old enough? ───────────────────────────────────────────────────────────────

# The highest version has to be dated, as the latest had to be when it was the only one read: a
# release nobody can date says nothing about when it will be installable.
if [[ ! "$NEWEST_EPOCH" =~ $EPOCH_PATTERN ]]; then
  refuse "release ${NEWEST_VERSION} carries no publication date this script reads (published_at: '${NEWEST_PUBLISHED}')." \
    "A release that cannot be dated cannot be shown to be old enough."
fi

REQUIRED_AGE=$((MIN_RELEASE_AGE * SECONDS_PER_DAY))
held_until() { jq -rn --argjson at "$(($1 + REQUIRED_AGE))" '$at | todateiso8601'; }

if [[ -z "$TARGET_VERSION" ]]; then
  echo ""
  printf "  ${BOLD}FrankenPHP %s is held back${RESET}\n" "$NEWEST_VERSION"
  printf "  Current     ${DIM}%s${RESET}\n" "$CURRENT_VERSION"
  printf "  Published   ${DIM}%s${RESET}\n" "$NEWEST_PUBLISHED"
  printf "  Held until  ${YELLOW}%s${RESET} ${DIM}— a release waits %s days (%s)${RESET}\n" \
    "$(held_until "$NEWEST_EPOCH")" "$MIN_RELEASE_AGE" "$MIN_RELEASE_AGE_SOURCE"
  # An older release between the two can come of age first: say which, and when.
  if [[ "$NEXT_VERSION" != "$NEWEST_VERSION" ]]; then
    printf "  Sooner      ${DIM}%s, installable from %s${RESET}\n" "$NEXT_VERSION" "$(held_until "$NEXT_EPOCH")"
  fi
  echo ""
  printf "  ${DIM}A compromised release is usually withdrawn within hours: waiting is what protects from\n"
  printf "  one, the checksum cannot. Once its release notes are read, to install it regardless:\n"
  printf "  npm run update:frankenphp -- --min-release-age=0${RESET}\n\n"
  exit 0
fi

# Exactly one published release carries that tag: its assets are the ones read from here on.
TARGET_COUNT=$(jq -r --arg tag "$TARGET_VERSION" "[${PUBLISHED}[] | select(.tag_name == \$tag)] | length" "$RELEASES_FILE")
if [[ "$TARGET_COUNT" != "1" ]]; then
  refuse "GitHub lists ${TARGET_COUNT} published releases tagged ${TARGET_VERSION}, where one was expected."
fi
jq --arg tag "$TARGET_VERSION" "${PUBLISHED}[] | select(.tag_name == \$tag)" "$RELEASES_FILE" >"$RELEASE_FILE"
AGE=$(($(date +%s) - TARGET_EPOCH))

# ── The binary of this platform ───────────────────────────────────────────────

ASSET_COUNT=$(jq -r --arg name "$ASSET_NAME" '[.assets[]? | select(.name == $name)] | length' "$RELEASE_FILE")
if [[ "$ASSET_COUNT" != "1" ]]; then
  refuse "expected exactly one binary named ${ASSET_NAME} in release ${TARGET_VERSION}, found ${ASSET_COUNT}."
fi

DOWNLOAD_URL=$(jq -r --arg name "$ASSET_NAME" '.assets[] | select(.name == $name) | .browser_download_url // empty | tostring' "$RELEASE_FILE")
EXPECTED_DIGEST=$(jq -r --arg name "$ASSET_NAME" '.assets[] | select(.name == $name) | .digest // empty | tostring' "$RELEASE_FILE")

# The address is known in advance, so it is compared rather than trusted: an answer that sends the
# download to another repository, another host or another tag is not followed there.
EXPECTED_URL="https://github.com/${REPO}/releases/download/${TARGET_VERSION}/${ASSET_NAME}"
if [[ "$DOWNLOAD_URL" != "$EXPECTED_URL" ]]; then
  refuse "release ${TARGET_VERSION} does not serve ${ASSET_NAME} from where it is expected." \
    "expected:  ${EXPECTED_URL}" \
    "announced: ${DOWNLOAD_URL}"
fi

if [[ ! "$EXPECTED_DIGEST" =~ $DIGEST_PATTERN ]]; then
  refuse "release ${TARGET_VERSION} publishes no SHA-256 this script reads for ${ASSET_NAME} (digest: '${EXPECTED_DIGEST}')." \
    "Nothing was downloaded: a binary that cannot be verified is not installed."
fi
EXPECTED_SHA256="${EXPECTED_DIGEST#sha256:}"

if command -v sha256sum >/dev/null 2>&1; then
  sha256_of() { sha256sum "$1" | cut -d ' ' -f 1; }
elif command -v shasum >/dev/null 2>&1; then
  sha256_of() { shasum -a 256 "$1" | cut -d ' ' -f 1; }
else
  refuse "neither sha256sum nor shasum is installed." \
    "Nothing was downloaded: a binary that cannot be verified is not installed."
fi

# ── Summary + confirmation ────────────────────────────────────────────────────

echo ""
printf "  ${BOLD}FrankenPHP update available${RESET}\n"
printf "  Current    ${DIM}%s${RESET}\n" "$CURRENT_VERSION"
printf "  Install    ${GREEN}${BOLD}%s${RESET}\n" "$TARGET_VERSION"
printf "  Published  ${DIM}%s (%s days ago)${RESET}\n" "$PUBLISHED_AT" "$((AGE / SECONDS_PER_DAY))"
printf "  Binary     ${DIM}%s${RESET}\n" "$ASSET_NAME"
if [[ "$NEWEST_VERSION" != "$TARGET_VERSION" ]]; then
  printf "  Held back  ${YELLOW}%s${RESET} ${DIM}until %s — a release waits %s days (%s)${RESET}\n" \
    "$NEWEST_VERSION" "$(held_until "$NEWEST_EPOCH")" "$MIN_RELEASE_AGE" "$MIN_RELEASE_AGE_SOURCE"
fi
echo ""

if [[ "$FORCE" -eq 0 ]]; then
  printf "  Proceed? ${DIM}[y/N]${RESET} "
  # At the end of the input, `read` fails — under `set -e` the script used to stop right here,
  # exit 1, without a word. It fails too on a line that never ends, whatever it holds: an answer
  # nobody finished typing is not a confirmation.
  if ! read -r CONFIRM; then
    printf "\n  Aborted: no answer.\n\n"
    exit 0
  fi
  case "$CONFIRM" in
    [yY]|[yY][eE][sS]) echo "" ;;
    *) printf "  Aborted.\n\n"; exit 0 ;;
  esac
fi

# ── Download, verify, replace ─────────────────────────────────────────────────

printf "  Downloading %s…\n" "$TARGET_VERSION"
# --fail: without it, an error page is saved as if it were the binary.
curl -L --fail --progress-bar -o "$TMPFILE" "$DOWNLOAD_URL" ||
  refuse "the download of ${DOWNLOAD_URL} failed." "${BINARY} was left untouched."

# Verified before it is made executable or moved: until this passes, the file is only a download.
ACTUAL_SHA256=$(sha256_of "$TMPFILE")
if [[ "$ACTUAL_SHA256" != "$EXPECTED_SHA256" ]]; then
  refuse "the downloaded file is not the ${ASSET_NAME} that release ${TARGET_VERSION} publishes." \
    "expected SHA-256: ${EXPECTED_SHA256}" \
    "computed SHA-256: ${ACTUAL_SHA256}" \
    "${BINARY} was left untouched."
fi
printf "  ${GREEN}✔${RESET}  SHA-256 verified ${DIM}%s${RESET}\n" "$EXPECTED_SHA256"

chmod +x "$TMPFILE"
mv "$TMPFILE" "$BINARY"

echo ""
printf "  ${GREEN}✔${RESET}  Updated ${DIM}%s${RESET} → ${GREEN}${BOLD}%s${RESET}\n" "$CURRENT_VERSION" "$TARGET_VERSION"
# The space before PHP is what tells it from the end of "FrankenPHP", whose own version this
# line printed until 2026-10-10.
BUNDLED_PHP=$("$BINARY" --version 2>/dev/null | grep -oE ' PHP [0-9]+\.[0-9]+\.[0-9]+' | head -1 || true)
[[ -n "$BUNDLED_PHP" ]] && printf "  ${DIM}Bundled%s${RESET}\n" "$BUNDLED_PHP"

# Restore capability to bind privileged ports (lost when binary is replaced)
if sudo setcap cap_net_bind_service=+ep "$BINARY" 2>/dev/null; then
  printf "  ${DIM}cap_net_bind_service restored${RESET}\n"
else
  printf "  ${YELLOW}Warning:${RESET} could not restore cap_net_bind_service — run: sudo setcap cap_net_bind_service=+ep %s\n" "$BINARY"
fi
echo ""
