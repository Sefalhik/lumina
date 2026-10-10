#!/usr/bin/env bash
# Measure, from the outside, what the pipeline's SSH key can do on a server (LUMN-50).
# Usage: bash scripts/check-deploy-key.sh <user@host> <private key file> <known hosts file>
#
# Run it from your own machine with the key about to be handed to the pipeline, and again after
# every rotation — docs/deployment.md, "Setting it up".
#
# The tests of scripts/deploy-gate.sh prove what the gate does with a request. They cannot prove
# that sshd hands it every request: that is the work of `restrict` and of the forced command in
# ~/.ssh/authorized_keys, a line typed by hand on the server, and the only place to measure it is
# the server. One thing has to work, and seven must not:
#
#   1. php-version answers                    the key is accepted, and reaches the gate
#   2. asking for a shell is refused          by the gate
#   3. a command is refused                   by the gate
#   4. a terminal is not granted              by `restrict`
#   5. the sftp subsystem is refused          by the gate, which runs in its place
#   6. a file copy is refused                 by the gate
#   7. a tunnel through the server is refused by `restrict`
#   8. a port opened on the server is refused by `restrict`
#
# Nothing a probe receives is ever printed: a key that is not restricted would show what it reached.
# If the first probe fails, the others are not tried — a key the server rejects would "pass" every
# one of them, for the wrong reason.

set -euo pipefail

GREEN='\033[0;32m'
RED='\033[0;31m'
RESET='\033[0m'

if (($# != 3)); then
  printf 'Usage: bash scripts/check-deploy-key.sh <user@host> <private key file> <known hosts file>\n' >&2
  exit 2
fi

TARGET="$1"
KEY_FILE="$2"
KNOWN_HOSTS_FILE="$3"
# How long one attempt may last. A refusal comes back at once; what reaches this limit is a
# session the server agreed to keep open.
SESSION_LIMIT="${CHECK_DEPLOY_KEY_TIMEOUT:-25}"

for file in "$KEY_FILE" "$KNOWN_HOSTS_FILE"; do
  if [[ ! -f "$file" ]]; then
    printf 'Error: %s is not a file.\n' "$file" >&2
    exit 2
  fi
done

# Typed after a refused command: seeing it come back means a shell ran it.
MARKER="check-deploy-key-reached-a-shell-$$"

# Runs ssh towards the server with the pinned host key and this key alone, and keeps what came
# back without showing it. `timeout` ends a session that stays open: a tunnel that was granted.
attempt() {
  if OUTPUT=$(timeout "$SESSION_LIMIT" ssh -F /dev/null \
    -i "$KEY_FILE" \
    -o IdentitiesOnly=yes \
    -o BatchMode=yes \
    -o StrictHostKeyChecking=yes \
    -o UserKnownHostsFile="$KNOWN_HOSTS_FILE" \
    -o GlobalKnownHostsFile=/dev/null \
    -o ConnectTimeout=15 \
    "$@" < /dev/null 2>&1); then
    STATUS=0
  else
    STATUS=$?
  fi
}

FAILED=0

verdict() {
  if [[ "$1" == 'ok' ]]; then
    printf "  ${GREEN}✔${RESET}  %s\n" "$2"
  else
    printf "  ${RED}✘${RESET}  %s\n" "$3"
    FAILED=$((FAILED + 1))
  fi
}

# The gate's signature: exit 1, its own word, and no trace of anything having been executed.
refused_by_the_gate() {
  [[ "$STATUS" -eq 1 && "$OUTPUT" == *'Refused:'* && "$OUTPUT" != *"$MARKER"* ]]
}

printf 'Checking what this key can do on %s\n\n' "$TARGET"

attempt -T "$TARGET" php-version
if [[ "$STATUS" -ne 0 || ! "$OUTPUT" =~ ^PHP\ [0123456789] ]]; then
  printf "  ${RED}✘${RESET}  php-version did not answer: the key is not accepted, or does not reach the gate.\n\n" >&2
  printf 'Nothing else was tried: every refusal that follows would prove nothing.\n' >&2
  exit 1
fi
verdict ok 'php-version answers' ''

attempt -T "$TARGET"
verdict "$(refused_by_the_gate && echo ok || echo no)" \
  'a shell is refused' 'a shell is NOT refused'

attempt -T "$TARGET" "echo ${MARKER}"
verdict "$(refused_by_the_gate && echo ok || echo no)" \
  'a command is refused' 'a command is NOT refused'

# With `restrict`, sshd turns the terminal down and says so, then runs the forced command anyway.
attempt -tt "$TARGET" "echo ${MARKER}"
verdict "$([[ "$OUTPUT" == *'PTY allocation request failed'* ]] && refused_by_the_gate && echo ok || echo no)" \
  'a terminal is not granted' 'a terminal IS granted, or the command was not refused'

attempt -s "$TARGET" sftp
verdict "$(refused_by_the_gate && echo ok || echo no)" \
  'the sftp subsystem is refused' 'the sftp subsystem is NOT refused'

attempt -T "$TARGET" 'scp -t .'
verdict "$(refused_by_the_gate && echo ok || echo no)" \
  'a file copy is refused' 'a file copy is NOT refused'

# A failure is not a refusal: a tunnel that is allowed also fails when nothing listens at its far
# end. Only sshd saying it is prohibited counts, and the banner of the server's own sshd, reached
# through the server, is the proof of the contrary.
attempt -W 127.0.0.1:22 "$TARGET"
verdict "$([[ "$OUTPUT" == *'administratively prohibited'* && "$OUTPUT" != *'SSH-2.0'* ]] && echo ok || echo no)" \
  'a tunnel through the server is refused' 'a tunnel through the server is NOT refused'

# Same rule: ssh has to say the forwarding failed. Granted, the session stays open until `timeout`
# ends it with 124; dropped for another reason, it proves nothing either way.
attempt -N -o ExitOnForwardFailure=yes -R 127.0.0.1:0:127.0.0.1:22 "$TARGET"
verdict "$([[ "$STATUS" -ne 124 && "$OUTPUT" == *'remote port forwarding failed'* ]] && echo ok || echo no)" \
  'a port opened on the server is refused' 'a port opened on the server is NOT refused'

if ((FAILED > 0)); then
  printf "\n${RED}%s restriction(s) missing.${RESET} Do not give this key to the pipeline: check its line in ~/.ssh/authorized_keys.\n" "$FAILED" >&2
  exit 1
fi
printf "\n${GREEN}The key can ask for php-version and a deployment, and nothing else.${RESET}\n"
