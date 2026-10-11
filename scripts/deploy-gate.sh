#!/usr/bin/env bash
# The only thing the pipeline's SSH key can run on the server (LUMN-50, LUMN-51).
#
# The key is listed in ~/.ssh/authorized_keys with `restrict` and a forced command naming this
# file, through the `current` link of the site. sshd then runs it whatever the caller asked for,
# and hands the request over untouched in SSH_ORIGINAL_COMMAND. Three requests are honoured, and
# everything else is refused:
#
#   php-version      report the PHP this account runs: proves the link, changes nothing
#   deploy <sha>     release that commit, provided it is one of origin/main's, carries the
#                    deployment scripts, and is not older than the commit being served
#   rollback <sha>   undo the deployment of that commit, provided it is the one being served and
#                    was put in service less than half an hour ago
#
# A stolen key can therefore move the server forward along main, or take back the step it has just
# made, and do nothing else: no shell, no file transfer, no .env, no way back to a commit whose
# flaw was fixed last week. See docs/deployment.md, "What the pipeline's key can do".
#
# "One of main's" matters as much as the shape of the SHA. The repository is public, and GitHub
# serves the commit of any fork by its SHA from this repository's address: a SHA that merely
# exists says nothing about who wrote it. Only refs/heads/main is fetched, and the commit has to
# be one of its ancestors.
#
# The gate decides and never builds: scripts/release.sh and scripts/rollback.sh do, under the lock
# taken here.

set -euo pipefail

# Every character is listed rather than given as a range: what `a-f` covers depends on the
# locale, and the locale is the one thing an SSH client is commonly allowed to send.
SHA_PATTERN='^[0123456789abcdef]{40}$'
RELEASE_NAME_PATTERN='^[0123456789]{8}-[0123456789]{6}-[0123456789abcdef]{40}$'
# Twelve digits hold every second for thirty thousand years; more would only be there to overflow.
EPOCH_PATTERN='^[0123456789]{1,12}$'
# How long after a deployment the key may still ask for it to be undone, in seconds.
UNDO_WINDOW=1800

# The release this file belongs to, links resolved: <site>/releases/<name>. The site holds the
# repository, the releases, what they share, and the `current` link sshd came through.
RELEASE_DIR=$(cd -P "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
SITE=$(dirname "$(dirname "$RELEASE_DIR")")
REPOSITORY="${SITE}/repo"
JOURNAL="${SITE}/shared/storage/logs/deploy-gate.log"

# One line per request: when, from where, and what was decided. A refused request is never
# written back, neither here nor to the caller: it is whatever a stranger chose to send.
record() {
  local caller="${SSH_CONNECTION:-}"
  printf '%s %s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "${caller%% *}" "$1" >> "$JOURNAL" ||
    printf 'Warning: %s could not be written.\n' "$JOURNAL" >&2
}

refuse() {
  record "refused $1"
  printf 'Refused: %s\n' "$2" >&2
  printf 'This key accepts "php-version", "deploy <sha>" and "rollback <sha>", <sha> being the full SHA of a commit of main.\n' >&2
  exit 1
}

# One deployment at a time, the pipeline's or a human's. The lock is held on a file descriptor and
# inherited by what runs next: it is released when the last process of the deployment ends, however
# it ends. There is no file to remember to delete.
lock() {
  if ! { exec 9>> "${SITE}/shared/deploy.lock"; } 2> /dev/null; then
    refuse "no-lock $1" 'this server cannot take its deployment lock.'
  fi
  if ! flock --nonblock 9; then
    refuse "busy $1" 'a deployment is already running on this server.'
  fi
  export DEPLOY_LOCK_FD=9
}

# A regular file, as git records one. A directory or a link of that name would be found, and
# could not be run: the key would then be answered by nothing.
carries() {
  local entry
  entry=$(git -C "$REPOSITORY" ls-tree "$1" -- "scripts/$2" 2> /dev/null || true)
  [[ "$entry" == '100644 blob '* || "$entry" == '100755 blob '* ]]
}

deploy() {
  local sha="$1" served="$2" script comparison=0

  # "Not older than a commit nobody can read" would let any commit of main through.
  if ! git -C "$REPOSITORY" cat-file -e "${served}^{commit}" 2> /dev/null; then
    refuse "not-ready ${sha}" 'this server cannot say which commit it is serving.'
  fi

  if ! git -C "$REPOSITORY" fetch --quiet origin '+refs/heads/main:refs/remotes/origin/main'; then
    refuse "fetch ${sha}" 'origin could not be fetched, so nothing can be checked against main.'
  fi

  # Exit 1 when the commit is not an ancestor, 128 when it is not a commit this clone holds:
  # both are a refusal.
  if ! git -C "$REPOSITORY" merge-base --is-ancestor "$sha" refs/remotes/origin/main 2> /dev/null; then
    refuse "not-on-main ${sha}" 'that SHA is not a commit of main.'
  fi

  # A commit from before these scripts existed would become the release being served with nothing
  # to answer the key any more: no gate, or a gate with nothing to hand the work to. The pipeline
  # deploys what it can deploy; anything older is a human's decision.
  for script in deploy.sh deploy-gate.sh release.sh rollback.sh; do
    if ! carries "$sha" "$script"; then
      refuse "no-scripts ${sha}" 'that commit does not carry the deployment scripts: this key cannot deploy it.'
    fi
  done

  # Never backwards. A commit of main is not harmless because it was once reviewed: the one from
  # before a fix is the flaw itself, and a stolen key would bring it back. Going back is a decision
  # a human makes, by hand. Deploying again the commit being served is not going back.
  # git answers 0 for "older", 1 for "not older", and anything else when it could not tell: a
  # repository it cannot read is not a reason to let the commit through.
  if [[ "$sha" != "$served" ]]; then
    git -C "$REPOSITORY" merge-base --is-ancestor "$sha" "$served" 2> /dev/null || comparison=$?
    case "$comparison" in
      0) refuse "older ${sha}" 'that commit is older than the one being served: this key does not go back.' ;;
      1) ;;
      *) refuse "unreadable ${sha}" 'the repository of this server cannot compare that commit with the one being served.' ;;
    esac
  fi

  lock "$sha"
  record "accepted deploy ${sha}"
  exec bash "${RELEASE_DIR}/scripts/release.sh" "$sha"
}

# The one step back the key may take: undoing the deployment that has just been made, while the
# release it replaced is still there. Asked later, or of anything but the last deployment, it is
# the way back to an older commit that `deploy` refuses — and it is refused the same.
undo() {
  local sha="$1" served="$2" when='' name='' previous='' now

  if [[ "$sha" != "$served" ]]; then
    refuse "not-served ${sha}" 'that commit is not the one being served: there is nothing of it to undo.'
  fi

  # Written by scripts/deploy.sh when it puts a release in service, removed by scripts/rollback.sh:
  # when it was, the name of the release, and the name of the one it replaced.
  { read -r when name previous < "${SITE}/shared/last-deployment"; } 2> /dev/null || true
  if [[ "$name" != "$(basename "$(cd -P "${SITE}/current" && pwd)")" || ! "$when" =~ $EPOCH_PATTERN ]]; then
    refuse "nothing-to-undo ${sha}" 'the release being served is not one a deployment has just put there.'
  fi

  # 10#: a number written with a leading zero is still a number of seconds, not an octal one.
  now=$(date -u +%s)
  if ((now - 10#$when > UNDO_WINDOW || now < 10#$when)); then
    refuse "too-late ${sha}" 'that deployment is more than half an hour old: going back is now a human decision.'
  fi

  if [[ ! "$previous" =~ $RELEASE_NAME_PATTERN || ! -f "${SITE}/releases/${previous}/.served" ]]; then
    refuse "no-previous ${sha}" 'the release that was served before that deployment is no longer there.'
  fi

  lock "$sha"
  record "accepted rollback ${sha}"
  exec bash "${RELEASE_DIR}/scripts/rollback.sh"
}

main() {
  local request="${SSH_ORIGINAL_COMMAND:-}" verb sha served

  if [[ "$request" == 'php-version' ]]; then
    record 'accepted php-version'
    exec php -r 'echo "PHP ", PHP_VERSION, PHP_EOL;'
  fi

  case "$request" in
    'deploy '*) verb='deploy' ;;
    'rollback '*) verb='rollback' ;;
    *) refuse 'request' 'this is not a request the key accepts.' ;;
  esac

  sha="${request#"${verb} "}"
  if [[ ! "$sha" =~ $SHA_PATTERN ]]; then
    refuse 'sha-shape' "what follows \"${verb}\" is not the full SHA of a commit."
  fi

  # Everything below is measured against the commit being served. A server that cannot name it is
  # refused everything: "not older than nothing" would let any commit of main through, and
  # "the one being served" would be whatever the caller says.
  served=$(cat "${SITE}/current/RELEASE" 2> /dev/null || true)
  if [[ ! "$served" =~ $SHA_PATTERN ]]; then
    refuse "not-ready ${sha}" 'this server cannot say which commit it is serving.'
  fi

  if [[ "$verb" == 'deploy' ]]; then
    deploy "$sha" "$served"
  fi
  undo "$sha" "$served"
}

main
