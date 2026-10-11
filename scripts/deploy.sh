#!/usr/bin/env bash
# Build the release this file sits in, put it in service, and let the oldest ones go (LUMN-51).
# Usage: none by hand — scripts/release.sh <commit> makes the directory and ends here.
#
# The sequence is the one of docs/deployment.md, "Deploying": it was typed by hand from 2026-09-14
# until LUMN-50, and every command here is one that was typed. What LUMN-51 changed is where it
# runs: in a directory nobody is being served from. The site moves to it at the very end, when one
# link changes, and a release that fails before that is removed as if it had never been started.
#
# AdminSeeder is not here on purpose: it belongs to the first deployment of an environment, done
# by hand with the admin's credentials at hand.

set -euo pipefail

# Served releases kept on the server, the one in service included: what a rollback can go to.
KEEP=5
RELEASE_NAME_PATTERN='^[0123456789]{8}-[0123456789]{6}-[0123456789abcdef]{40}$'

if (($# > 0)); then
  printf 'Error: scripts/deploy.sh takes no argument: it builds the release it sits in.\n' >&2
  exit 1
fi

RELEASE_DIR=$(cd -P "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
SITE=$(dirname "$(dirname "$RELEASE_DIR")")
NAME=$(basename "$RELEASE_DIR")

# This script deletes the directory it sits in when it fails. It therefore runs nowhere but in a
# directory scripts/release.sh has just made: named like one, inside releases/, never served, and
# holding the commit its name announces.
if [[ ! "$NAME" =~ $RELEASE_NAME_PATTERN || "$(dirname "$RELEASE_DIR")" != "${SITE}/releases" ||
  -z "${DEPLOY_LOCK_FD:-}" || -e "${RELEASE_DIR}/.served" ||
  "$(cat "${RELEASE_DIR}/RELEASE" 2> /dev/null || true)" != "${NAME##*-}" ]]; then
  printf 'Error: scripts/deploy.sh builds a release that scripts/release.sh <commit> has just made, and nothing else.\n' >&2
  exit 1
fi

if [[ ! -f "${SITE}/shared/.env" || ! -d "${SITE}/shared/storage" ]]; then
  printf 'Error: %s/shared has no .env or no storage/: see docs/deployment.md, "Setting up a site".\n' "$SITE" >&2
  exit 1
fi

# The lock is announced by whoever started this script. It is believed once it is measured: a
# second lock on the same file is only granted when nobody holds one.
if ( exec 8>> "${SITE}/shared/deploy.lock" && flock --nonblock 8 ) 2> /dev/null; then
  printf 'Error: the deployment lock is announced as held and is not: scripts/release.sh <commit> is the way in.\n' >&2
  exit 1
fi

RELEASE_SHA="${NAME##*-}"

# A release that was not put in service leaves nothing behind: every directory of releases/ is
# then one that was served, and one a rollback can go to. Whether this one is in service is asked
# of the site, not remembered: wherever this script is interrupted, the directory the link points
# to is never the one removed.
abandon() {
  if [[ "$(cd -P "${SITE}/current" 2> /dev/null && pwd)" != "$RELEASE_DIR" ]]; then
    # The link that was about to replace `current`, when the script stopped between the two.
    if [[ "$(readlink "${SITE}/current.next" 2> /dev/null)" == "releases/${NAME}" ]]; then
      rm -f "${SITE}/current.next"
    fi
    rm -rf "$RELEASE_DIR"
  fi
}
trap abandon EXIT

cd "$RELEASE_DIR"

step() {
  printf '\n── %s\n' "$*"
  "$@"
}

# The pages the previous release is still sending, and the ones already open in a browser, name
# its compiled files. They have to answer after the switch too: the files this release built
# itself are listed, and the ones the release in service had built are added next to them. Their
# names carry a hash and never collide.
keep_previous_assets() {
  local previous="${SITE}/current" file

  if [[ -d public/build/assets ]]; then
    ls -A public/build/assets > .built-assets
  else
    : > .built-assets
  fi

  if [[ -f "${previous}/.built-assets" ]]; then
    while IFS= read -r file; do
      # A name, never a path: the list is one this script wrote, and is read as if it were not.
      if [[ "$file" != */* && -f "${previous}/public/build/assets/${file}" && ! -e "public/build/assets/${file}" ]]; then
        mkdir -p public/build/assets
        cp -p "${previous}/public/build/assets/${file}" "public/build/assets/${file}"
      fi
    done < "${previous}/.built-assets"
  fi
}

# One link changes, in one rename: a request finds the previous release or this one, never half of
# each. What was in service stays on disk, untouched.
put_in_service() {
  local previous="$1"

  touch .served
  ln -sfn "releases/${NAME}" "${SITE}/current.next"
  mv -T "${SITE}/current.next" "${SITE}/current"

  # What scripts/rollback.sh undoes, and what the gate lets the pipeline undo for half an hour.
  printf '%s %s %s\n' "$(date -u +%s)" "$NAME" "$previous" > "${SITE}/shared/last-deployment" ||
    printf 'Warning: %s/shared/last-deployment could not be written: this deployment cannot be undone by name.\n' "$SITE" >&2
}

# Newest first: the release in service and the one it replaced always stay, then served releases
# up to KEEP. Whatever was never served is what a deployment that was killed left behind.
prune() {
  local previous="$1" kept=0 failed=0 index directory name
  local -a releases=("${SITE}/releases"/*/)

  for ((index = ${#releases[@]} - 1; index >= 0; index--)); do
    directory="${releases[index]%/}"
    name="${directory##*/}"
    if [[ "$name" == "$NAME" || "$name" == "$previous" ]]; then
      kept=$((kept + 1))
    elif [[ -e "${directory}/.served" ]] && ((kept < KEEP)); then
      kept=$((kept + 1))
    else
      rm -rf "$directory" 2> /dev/null || failed=1
    fi
  done

  return "$failed"
}

# What every release shares: the directory the repository ships is only a skeleton.
rm -rf storage
ln -s "${SITE}/shared/storage" storage
ln -s "${SITE}/shared/.env" .env

step composer install --no-dev --optimize-autoloader --no-interaction
step npm ci
step npm run build
keep_previous_assets
step php artisan migrate --force --no-interaction
# --force answers Laravel's production confirmation, without which the command is cancelled in a
# session nobody is typing in. --no-interaction leaves the seeder's own question on its default,
# which is to keep published content as it is.
step php artisan db:seed --class=HomepageContentSeeder --force --no-interaction

# RELEASE was written by scripts/release.sh, and config:cache freezes it here: config/app.php reads
# the file through App\Services\Release, and every response of this release carries it as X-Release.
step php artisan config:cache
step php artisan route:cache
step php artisan view:cache

# Empty on the first release of a site: there is then nothing to go back to.
REPLACED=''
if [[ -e "${SITE}/current" ]]; then
  REPLACED=$(basename "$(cd -P "${SITE}/current" && pwd)")
fi
put_in_service "$REPLACED"
# The release is in service: nothing below can make this deployment a failed one.
prune "$REPLACED" || printf 'Warning: some old releases could not be removed.\n' >&2

printf '\nDeployed %s\n' "$RELEASE_SHA"
