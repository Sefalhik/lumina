#!/usr/bin/env bash
# Watch a page while a release is put in service, and say whether a visitor would have noticed
# (LUMN-51).
# Usage: bash scripts/watch-switch.sh <page URL> [<number of requests>]
#        SMOKE_BASIC_USER and SMOKE_BASIC_PASSWORD are sent when they are set.
#
# Run it from your own machine, start a deployment or a rollback, and let it finish. Five times a
# second it asks for the page, reads which release answered in X-Release, and asks for every
# compiled file the page names. One answer that is not 200 is a visitor who got a broken page.
#
# That is the measurement scripts/deploy.sh cannot make on itself: the link changes in one rename,
# but PHP remembers for a while where a path led, and may go on answering from the release that
# was just replaced while Apache already serves the files of the new one.
#
# It prints one line each time the release changes or something is missing, and a count at the end.
# Exit 1 when anything was missing.

set -euo pipefail

if (($# < 1 || $# > 2)); then
  printf 'Usage: bash scripts/watch-switch.sh <page URL> [<number of requests>]\n' >&2
  exit 2
fi

PAGE="$1"
REQUESTS="${2-300}"
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
    while IFS= read -r file; do
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
  if [[ -n "$wrong" ]]; then
    printf '%s  request %s  BROKEN, served by %s:%s\n' "$(date +%H:%M:%S)" "$request" "${release:-no release}" "$wrong"
    broken=$((broken + 1))
  fi

  sleep 0.2
done

printf '\n%s requests, releases seen: %s\n' "$REQUESTS" "$seen"
if ((broken > 0)); then
  printf '%s of them would have shown a visitor a broken page.\n' "$broken" >&2
  exit 1
fi
printf 'None of them would have shown a visitor a broken page.\n'
