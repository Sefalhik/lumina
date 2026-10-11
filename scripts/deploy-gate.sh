#!/usr/bin/env bash
# The only thing the pipeline's SSH key can run on the server (LUMN-50).
#
# The key is listed in ~/.ssh/authorized_keys with `restrict` and a forced command naming this
# file. sshd then runs it whatever the caller asked for, and hands the request over untouched in
# SSH_ORIGINAL_COMMAND. Two requests are honoured, and everything else is refused:
#
#   php-version     report the PHP this account runs: proves the link, changes nothing
#   deploy <sha>    deploy that commit, provided it is one of origin/main's, carries the
#                   deployment scripts, and is not older than the commit being served
#
# A stolen key can therefore move the server forward along main and do nothing else: no shell, no
# file transfer, no .env, no way back to a commit whose flaw has since been fixed. See
# docs/deployment.md, "What the pipeline's key can do".
#
# "One of main's" matters as much as the shape of the SHA. The repository is public, and GitHub
# serves the commit of any fork by its SHA from this repository's address: a SHA that merely
# exists says nothing about who wrote it. Only refs/heads/main is fetched, and the commit has to
# be one of its ancestors.
#
# Everything lives in functions, called from the last line: the checkout below replaces this very
# file, and bash would otherwise go on reading its next command from whatever took its place.

set -euo pipefail

# Every character is listed rather than given as a range: what `a-f` covers depends on the
# locale, and the locale is the one thing an SSH client is commonly allowed to send.
SHA_PATTERN='^[0123456789abcdef]{40}$'
JOURNAL='storage/logs/deploy-gate.log'

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
  printf 'This key accepts "php-version" and "deploy <sha>", <sha> being the full SHA of a commit of main.\n' >&2
  exit 1
}

main() {
  local request="${SSH_ORIGINAL_COMMAND:-}"

  cd "$(dirname "${BASH_SOURCE[0]}")/.."

  if [[ "$request" == 'php-version' ]]; then
    record 'accepted php-version'
    exec php -r 'echo "PHP ", PHP_VERSION, PHP_EOL;'
  fi

  if [[ "$request" != 'deploy '* ]]; then
    refuse 'request' 'this is not a request the key accepts.'
  fi

  local sha="${request#deploy }"
  if [[ ! "$sha" =~ $SHA_PATTERN ]]; then
    refuse 'sha-shape' 'what follows "deploy" is not the full SHA of a commit.'
  fi

  if ! git fetch --quiet origin '+refs/heads/main:refs/remotes/origin/main'; then
    refuse "fetch ${sha}" 'origin could not be fetched, so nothing can be checked against main.'
  fi

  # Exit 1 when the commit is not an ancestor, 128 when it is not a commit this clone holds:
  # both are a refusal.
  if ! git merge-base --is-ancestor "$sha" refs/remotes/origin/main 2> /dev/null; then
    refuse "not-on-main ${sha}" 'that SHA is not a commit of main.'
  fi

  # A commit from before these scripts existed would be checked out, find no deployment script to
  # run, and leave the server on code nothing installed — with this file gone, and the key with
  # it. The pipeline deploys what it can deploy; anything older is a human's decision.
  if ! git cat-file -e "${sha}:scripts/deploy.sh" 2> /dev/null ||
    ! git cat-file -e "${sha}:scripts/deploy-gate.sh" 2> /dev/null; then
    refuse "no-scripts ${sha}" 'that commit does not carry the deployment scripts: this key cannot deploy it.'
  fi

  # Never backwards. A commit of main is not harmless because it was once reviewed: the one from
  # before a fix is the flaw itself, and a stolen key would bring it back. Going back is a decision
  # a human makes, by hand. Deploying again the commit being served is not going back.
  local served
  served=$(git rev-parse HEAD)
  if [[ "$sha" != "$served" ]] && git merge-base --is-ancestor "$sha" "$served" 2> /dev/null; then
    refuse "older ${sha}" 'that commit is older than the one being served: this key does not go back.'
  fi

  record "accepted deploy ${sha}"
  git checkout --quiet --detach "$sha"
  exec bash scripts/deploy.sh
}

main
