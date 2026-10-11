#!/usr/bin/env bash
# Watch a page while a release is put in service, and say whether a visitor would have noticed
# (LUMN-51).
# Usage: bash scripts/watch-switch.sh <page URL> [<number of requests> [<release expected>]]
#        SMOKE_BASIC_USER and SMOKE_BASIC_PASSWORD are sent when they are set.
#
# Run it from your own machine, start a deployment or a rollback, and let it finish. Over and over
# it asks for the page, reads which release answered in X-Release, asks for every compiled file
# the page names, and waits a fifth of a second: about one round a second against a real site. One
# answer that is not 200 is a visitor who got a broken page.
#
# That is the measurement scripts/deploy.sh cannot make on itself. The link changes in one rename;
# what PHP answers with afterwards is another matter, and Apache already serves the files of the
# new release. Measured on 2026-10-11, before public/index.php asked which release is in service:
# ten minutes after a switch, every page still came from the release that had been replaced.
#
# So "nothing was broken" is not the whole answer. Given the release a switch should end on — the
# full SHA its X-Release carries — the script also fails when that release never answered.
#
# It prints one line each time the release changes or something is missing, and a count at the end.
# Exit 1 when anything was missing, or when the release expected was never seen.

set -euo pipefail

if (($# < 1 || $# > 3)); then
  printf 'Usage: bash scripts/watch-switch.sh <page URL> [<number of requests> [<release expected>]]\n' >&2
  exit 2
fi

PAGE="$1"
REQUESTS="${2-300}"
EXPECTED="${3-}"
SHA_PATTERN='^[0123456789abcdef]{40}$'
URL_PATTERN='^(https?://[^/]+)(/.*)?$'
NUMBER_PATTERN='^[123456789][0123456789]*$'

if [[ ! "$PAGE" =~ $URL_PATTERN ]]; then
  printf 'Error: %s is not the URL of a page.\n' "$PAGE" >&2
  exit 2
fi
ORIGIN="${BASH_REMATCH[1]}"

if [[ ! "$REQUESTS" =~ $NUMBER_PATTERN ]]; then
  printf 'Error: the number of requests has to be a number above zero.\n' >&2
  exit 2
fi

if (($# == 3)) && [[ ! "$EXPECTED" =~ $SHA_PATTERN ]]; then
  printf 'Error: the release expected has to be the full SHA an X-Release header carries.\n' >&2
  exit 2
fi

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

# Credentials go to curl through a file, in a directory mktemp made for this user alone: on a
# command line, they would be shown to anyone listing the processes of the machine.
: > "${WORK}/curl.conf"
if [[ -n "${SMOKE_BASIC_USER:-}" ]]; then
  printf 'user = "%s:%s"\n' "$SMOKE_BASIC_USER" "${SMOKE_BASIC_PASSWORD:-}" > "${WORK}/curl.conf"
fi

status_of() {
  curl --silent --config "${WORK}/curl.conf" --max-time 10 --output /dev/null --write-out '%{http_code}' "$1" || true
}

broken=0
files=0
arrived=''
# Not a value a header can hold: the first answer is always a change, even when it announces nothing.
last=$'\n'

for ((request = 1; request <= REQUESTS; request++)); do
  wrong=''
  code=$(curl --silent --config "${WORK}/curl.conf" --max-time 10 --dump-header "${WORK}/headers" \
    --output "${WORK}/page" --write-out '%{http_code}' "$PAGE" || true)
  release=$(tr -d '\r' < "${WORK}/headers" 2> /dev/null | sed -n 's/^[Xx]-[Rr]elease: *//p' | tail -n 1)

  if [[ "$code" != '200' ]]; then
    wrong+=" page ${code:-unanswered}"
  else
    files=0
    while IFS= read -r file; do
      files=$((files + 1))
      answer=$(status_of "${ORIGIN}${file}")
      if [[ "$answer" != '200' ]]; then
        wrong+=" ${file} ${answer:-unanswered}"
      fi
    done < <(grep --only-matching --extended-regexp '/build/assets/[A-Za-z0-9._-]+' "${WORK}/page" | sort --unique)
  fi

  if [[ "$release" != "$last" ]]; then
    printf '%s  request %s  release %s\n' "$(date +%H:%M:%S)" "$request" "${release:-none announced}"
    seen+="${seen:+ → }${release:-none}"
    last="$release"
  fi
  if [[ -n "$EXPECTED" && "$release" == "$EXPECTED" ]]; then
    arrived=1
  fi
  if [[ -n "$wrong" ]]; then
    printf '%s  request %s  BROKEN, served by %s:%s\n' "$(date +%H:%M:%S)" "$request" "${release:-no release}" "$wrong"
    broken=$((broken + 1))
  fi

  sleep 0.2
done

# How many files the last page that answered named: "nothing was missing" says little of a page
# that named none.
printf '\n%s requests, releases seen: %s\n' "$REQUESTS" "$seen"
printf 'The last page that answered named %s compiled file(s).\n' "$files"

failed=0
if ((broken > 0)); then
  printf '%s of them would have shown a visitor a broken page.\n' "$broken" >&2
  failed=1
else
  printf 'None of them would have shown a visitor a broken page.\n'
fi
if [[ -n "$EXPECTED" && -z "$arrived" ]]; then
  printf 'The release expected, %s, never answered: the switch did not take effect.\n' "$EXPECTED" >&2
  failed=1
fi
exit "$failed"
