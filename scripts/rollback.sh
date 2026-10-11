#!/usr/bin/env bash
# Put a release that was already served back in service (LUMN-51).
# Usage: bash scripts/rollback.sh                     undo the last deployment
#        bash scripts/rollback.sh <release name>      go back to that release — see <site>/releases
#
# Going back is one link changing, towards a directory that was built and served before: no
# network, no build, a few seconds. It is not "deploying an older commit", which the pipeline's key
# is refused on purpose — see scripts/deploy-gate.sh.
#
# Without a name it undoes the last deployment, and only that: once undone, or once another
# release has been put in service by hand, there is no "last deployment" left and the release has
# to be named. The gate lets the pipeline ask for the first form, for half an hour.
#
# The code goes back. The database does not: migrations only go forward, which is why they have to
# stay compatible with the release before them.
#
# Run by bash, never typed into the account's shell: that shell is fish.

set -euo pipefail

RELEASE_NAME_PATTERN='^[0123456789]{8}-[0123456789]{6}-[0123456789abcdef]{40}$'

if (($# > 1)); then
  printf 'Usage: bash scripts/rollback.sh [<release name>]\n' >&2
  exit 2
fi

RELEASE_DIR=$(cd -P "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
SITE=$(dirname "$(dirname "$RELEASE_DIR")")

fail() {
  printf 'Error: %s\n' "$1" >&2
  exit 1
}

if [[ ! -d "${SITE}/shared" || "$(dirname "$RELEASE_DIR")" != "${SITE}/releases" ]]; then
  fail "${RELEASE_DIR} is not a release of a site."
fi

# One deployment at a time, and going back is one. The gate takes the lock before it comes here; a
# human takes it now.
if [[ -z "${DEPLOY_LOCK_FD:-}" ]]; then
  if ! { exec 9>> "${SITE}/shared/deploy.lock"; } 2> /dev/null; then
    fail "the deployment lock ${SITE}/shared/deploy.lock cannot be opened."
  fi
  if ! flock --nonblock 9; then
    printf 'Error: a deployment is already running on this server.\n' >&2
    exit 75
  fi
elif ( exec 8>> "${SITE}/shared/deploy.lock" && flock --nonblock 8 ) 2> /dev/null; then
  # Announced by the caller, and believed once measured.
  fail 'the deployment lock is announced as held and is not.'
fi

# Empty when the link is missing or leads nowhere — a release directory removed by mistake. That
# is the day this script is needed most: run from any release left, with the name of the one to
# put in service.
CURRENT=''
if [[ -e "${SITE}/current" ]]; then
  CURRENT=$(basename "$(cd -P "${SITE}/current" && pwd)")
fi

if (($# == 1)); then
  TARGET="$1"
else
  LAST='' TARGET=''
  # When it was made, the release it put in service, and the one that release replaced.
  { read -r _ LAST TARGET < "${SITE}/shared/last-deployment"; } 2> /dev/null || true
  if [[ -z "$CURRENT" || "$LAST" != "$CURRENT" ]]; then
    fail "the release being served is not one a deployment has just put there: name the release to go back to, among those of ${SITE}/releases."
  fi
  if [[ -z "$TARGET" ]]; then
    fail 'that deployment was the first of this site: there is no release before it.'
  fi
fi

# A name, never a path: what is put in service is a directory of releases/ that was served.
if [[ ! "$TARGET" =~ $RELEASE_NAME_PATTERN || ! -f "${SITE}/releases/${TARGET}/.served" ||
  ! -f "${SITE}/releases/${TARGET}/RELEASE" ]]; then
  fail "${TARGET} is not a release that was served on this site."
fi
if [[ "$TARGET" == "$CURRENT" ]]; then
  fail "${TARGET} is the release being served."
fi

TARGET_SHA=$(cat "${SITE}/releases/${TARGET}/RELEASE")
JOURNAL="${SITE}/shared/storage/logs/deployments.log"
DOING='caching the configuration of the release to go back to'

journal() {
  { printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$1" >> "$JOURNAL"; } 2> /dev/null ||
    printf 'Warning: %s could not be written.\n' "$JOURNAL" >&2
}

# The last thing this script does when it does not end well. Whether the release is back in
# service is asked of the link, not remembered: stopped the instant after the switch, the script
# must not announce a failure that did not happen. A rollback that did fail says where it stopped
# and what the site serves — the command that failed has already said why, just above.
finish() {
  local status=$?
  if ((status == 0)); then
    return
  fi
  if [[ "$(readlink "${SITE}/current" 2> /dev/null)" == "releases/${TARGET}" ]]; then
    printf '\nRelease %s is back in service, and the script stopped before it had finished (exit status %s).\n' "$TARGET" "$status" >&2
    journal "rolled back to ${TARGET_SHA} as ${TARGET}, then stopped (exit ${status})"
    return
  fi
  printf '\nRollback to %s FAILED while %s (exit status %s).\n' "$TARGET_SHA" "$DOING" "$status" >&2
  printf 'The site serves what it served before: release %s.\n' "${CURRENT:-none}" >&2
  journal "failed to roll back to ${TARGET_SHA} while ${DOING} (exit ${status})"
}
trap finish EXIT
# A signal is an end like any other, and has to pass through the last words of this script: left
# to itself, bash stops with the status of the command it was waiting for — often 0 — and the
# report would call an interruption a success. Each one becomes the exit status a shell gives it.
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 141' PIPE
trap 'exit 143' TERM

# The configuration of that release was cached when it was built, and .env may have changed since:
# what is put back in service has to read today's.
printf '\n── php artisan config:cache\n'
(cd "${SITE}/releases/${TARGET}" && php artisan config:cache)

DOING='putting that release back in service'
ln -sfn "releases/${TARGET}" "${SITE}/current.next"
mv -T "${SITE}/current.next" "${SITE}/current"
journal "rolled back to ${TARGET_SHA} as ${TARGET}, leaving ${CURRENT:-nothing}"

# What was undone is no longer "the last deployment": going further back is a release to name.
# The release is in service: nothing below can make this rollback a failed one.
rm -f "${SITE}/shared/last-deployment" 2> /dev/null ||
  printf 'Warning: %s/shared/last-deployment could not be removed: remove it by hand before the pipeline is asked to undo anything.\n' "$SITE" >&2

printf '\nRolled back to %s\n' "$TARGET_SHA"
printf 'Release %s is in service; it replaced %s.\n' "$TARGET" "${CURRENT:-nothing}"
printf 'The database was not rolled back: migrations only go forward.\n'
