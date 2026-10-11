#!/usr/bin/env bash
# Make a release directory out of a commit, and hand it to its own deployment script (LUMN-51).
# Usage: bash scripts/release.sh <commit>          (on the server, from any directory)
#
# A site is laid out like this, and this file is reached through `current`:
#
#   <site>/repo/                  the repository, with no working copy: what the server knows of git
#   <site>/releases/<name>/       one directory per release, built away from the one being served
#   <site>/shared/                .env, storage/ and the lock: what outlives a release
#   <site>/current -> releases/…  the only thing that changes when a release is put in service
#
# The pipeline comes here through scripts/deploy-gate.sh, which has already decided that the commit
# may be deployed. A human comes here directly, for a candidate branch or a first release, after
# fetching the commit into <site>/repo: nothing is fetched and nothing is judged here.
#
# The work is split on purpose. This file belongs to the release being served: it only extracts the
# new one. How the new one is installed, built and put in service is written in *its own*
# scripts/deploy.sh, which takes over on the last line.
#
# Run by bash, never typed into the account's shell: that shell is fish.

set -euo pipefail

if (($# != 1)); then
  printf 'Usage: bash scripts/release.sh <commit>\n' >&2
  exit 2
fi

RELEASE_DIR=$(cd -P "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
SITE=$(dirname "$(dirname "$RELEASE_DIR")")
REPOSITORY="${SITE}/repo"

if [[ ! -d "$REPOSITORY" || ! -d "${SITE}/shared" || "$(dirname "$RELEASE_DIR")" != "${SITE}/releases" ]]; then
  printf 'Error: %s is not a release of a site: there is no repo/ and shared/ two levels above it.\n' "$RELEASE_DIR" >&2
  exit 1
fi

# One deployment at a time. The gate takes the lock before it comes here and says so; a human
# takes it now. It is held on a file descriptor: released when the last process of the deployment
# ends, however it ends.
if [[ -z "${DEPLOY_LOCK_FD:-}" ]]; then
  if ! { exec 9>> "${SITE}/shared/deploy.lock"; } 2> /dev/null; then
    printf 'Error: the deployment lock %s/shared/deploy.lock cannot be opened.\n' "$SITE" >&2
    exit 1
  fi
  if ! flock --nonblock 9; then
    printf 'Error: a deployment is already running on this server.\n' >&2
    exit 75
  fi
  export DEPLOY_LOCK_FD=9
elif ( exec 8>> "${SITE}/shared/deploy.lock" && flock --nonblock 8 ) 2> /dev/null; then
  # Announced by the caller, and believed once measured: a second lock is only granted when
  # nobody holds one.
  printf 'Error: the deployment lock is announced as held and is not.\n' >&2
  exit 1
fi

if ! SHA=$(git -C "$REPOSITORY" rev-parse --verify --quiet "${1}^{commit}"); then
  printf 'Error: that is not a commit %s holds. Fetch it first.\n' "$REPOSITORY" >&2
  exit 1
fi

# A regular file, as git records one: a directory or a link of that name could not be run.
ENTRY=$(git -C "$REPOSITORY" ls-tree "$SHA" -- scripts/deploy.sh 2> /dev/null || true)
if [[ "$ENTRY" != '100644 blob '* && "$ENTRY" != '100755 blob '* ]]; then
  printf 'Error: commit %s has no scripts/deploy.sh: it cannot build itself.\n' "$SHA" >&2
  exit 1
fi

# Named by the moment and by the commit: the same commit released twice makes two directories, so
# a release being served is never rebuilt in place, and the names sort in the order they were made.
NAME="$(date -u +%Y%m%d-%H%M%S)-${SHA}"
TARGET="${SITE}/releases/${NAME}"

# A release keeps its commit in the repository for as long as its directory is there. A candidate
# is fetched without a branch to hold it, and git removes in time what nothing refers to: the gate
# would then be unable to read the commit it serves, and would refuse everything. The names of
# releases that are gone are let go first.
while IFS= read -r REFERENCE; do
  if [[ ! -d "${SITE}/releases/${REFERENCE#refs/releases/}" ]]; then
    git -C "$REPOSITORY" update-ref -d "$REFERENCE"
  fi
done < <(git -C "$REPOSITORY" for-each-ref --format='%(refname)' refs/releases/)

mkdir "$TARGET"
# Until the new release has taken over, a failure leaves nothing behind.
trap 'rm -rf "$TARGET"' EXIT
git -C "$REPOSITORY" update-ref "refs/releases/${NAME}" "$SHA"

git -C "$REPOSITORY" archive "$SHA" | tar -x -C "$TARGET"
# Before anything is built: config:cache freezes this name into the configuration, and every
# response of the release then carries it as X-Release. Whatever the commit ships under that name
# is removed first — a file would be replaced anyway, and a link would be written through.
rm -f "${TARGET}/RELEASE"
printf '%s\n' "$SHA" > "${TARGET}/RELEASE"

printf 'Release %s\n' "$NAME"
exec bash "${TARGET}/scripts/deploy.sh"
