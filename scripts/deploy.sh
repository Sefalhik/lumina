#!/usr/bin/env bash
# Deploy the commit that is checked out: install, build, migrate, seed, name the release, cache.
# Usage: bash scripts/deploy.sh          (on the server, from any directory)
#
# The sequence is the one of docs/deployment.md, "Deploying", and nothing more: it was typed by
# hand from 2026-09-14 until LUMN-50, and every command here is one that was typed.
#
# It deploys what git has checked out and never chooses a commit. Choosing is the job of
# scripts/deploy-gate.sh when the pipeline asks, and of a `git checkout` when a human does.
#
# AdminSeeder is not here on purpose: it belongs to the first deployment of an environment, done
# by hand with the admin's credentials at hand.
#
# Run by bash, never typed into the account's shell: that shell is fish, where a loop or a `[ ]`
# test does not mean what it means here.

set -euo pipefail

if (($# > 0)); then
  printf 'Error: scripts/deploy.sh takes no argument: it deploys the commit that is checked out.\n' >&2
  exit 1
fi

cd "$(dirname "${BASH_SOURCE[0]}")/.."

# Read before anything is changed: outside a git checkout there is no release to name, and that
# has to stop the deployment rather than leave an environment serving one it cannot identify.
RELEASE_SHA=$(git rev-parse HEAD)

step() {
  printf '\n── %s\n' "$*"
  "$@"
}

step composer install --no-dev --optimize-autoloader --no-interaction
step npm ci
step npm run build
step php artisan migrate --force --no-interaction
# --force answers Laravel's production confirmation, without which the command is cancelled in a
# session nobody is typing in. --no-interaction leaves the seeder's own question on its default,
# which is to keep published content as it is.
step php artisan db:seed --class=HomepageContentSeeder --force --no-interaction

# Written before config:cache, which is what freezes it: config/app.php reads this file through
# App\Services\Release, and every response then carries it as X-Release. A step that failed above
# leaves the previous value in place, so the environment keeps announcing the release it serves.
printf '%s\n' "$RELEASE_SHA" > RELEASE

step php artisan config:cache
step php artisan route:cache
step php artisan view:cache

printf '\nDeployed %s\n' "$RELEASE_SHA"
