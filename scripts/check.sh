#!/usr/bin/env bash
# Full quality audit — run before pushing.
# Usage: bash scripts/check.sh
#        npm run check:full

set -uo pipefail

GREEN='\033[0;32m'
RED='\033[0;31m'
BOLD='\033[1m'
DIM='\033[2m'
RESET='\033[0m'

PASS=0
FAIL=0
FAILED_STEPS=()
TOTAL_START=$(date +%s)

# Run a single check step.
# Usage: step "Label" cmd arg arg...
step() {
    local label="$1"
    shift
    local start
    start=$(date +%s)
    printf "  %-26s" "$label"

    local tmpout
    tmpout=$(mktemp)

    if "$@" >"$tmpout" 2>&1; then
        local elapsed=$(( $(date +%s) - start ))
        printf "${GREEN}✔${RESET}  ${DIM}%ds${RESET}\n" "$elapsed"
        PASS=$(( PASS + 1 ))
    else
        local elapsed=$(( $(date +%s) - start ))
        printf "${RED}✗  FAILED${RESET}  ${DIM}%ds${RESET}\n" "$elapsed"
        FAIL=$(( FAIL + 1 ))
        FAILED_STEPS+=("$label")
        echo ""
        sed 's/^/    /' "$tmpout" | head -40
        echo ""
    fi

    rm -f "$tmpout"
}

# ─── Header ──────────────────────────────────────────────────────────────────

echo ""
printf "${BOLD}  Quality audit — cardascia-it.org${RESET}\n"
printf "  ${DIM}%s${RESET}\n\n" "$(date '+%Y-%m-%d %H:%M:%S')"

# ─── JS ──────────────────────────────────────────────────────────────────────

step "ESLint"          npm run --silent lint:js
step "Stylelint"       npm run --silent lint:scss

# ─── PHP static analysis ─────────────────────────────────────────────────────

step "PHPStan (app)"   bash -c "PHP_INI_SCAN_DIR=/etc/php/8.5/cli/conf.d ./vendor/bin/phpstan analyse"
step "PHPStan (tests)" bash -c "PHP_INI_SCAN_DIR=/etc/php/8.5/cli/conf.d ./vendor/bin/phpstan analyse -c phpstan-tests.neon"

# ─── Test suites ─────────────────────────────────────────────────────────────

step "PHPUnit"         bash -c "php artisan config:clear --ansi --quiet && php artisan test"
step "Vitest"          npm run --silent test:unit:run
step "Playwright E2E"  npm run --silent test:e2e

# ─── Summary ─────────────────────────────────────────────────────────────────

TOTAL_ELAPSED=$(( $(date +%s) - TOTAL_START ))
TOTAL=$(( PASS + FAIL ))

echo ""
printf "  ${DIM}────────────────────────────────────${RESET}\n"

if [ "$FAIL" -eq 0 ]; then
    printf "  ${GREEN}${BOLD}All $TOTAL checks passed${RESET}  ${DIM}in %ds${RESET}\n\n" "$TOTAL_ELAPSED"
    exit 0
else
    printf "  ${RED}${BOLD}$FAIL / $TOTAL checks failed${RESET}  ${DIM}in %ds${RESET}\n" "$TOTAL_ELAPSED"
    echo ""
    for s in "${FAILED_STEPS[@]}"; do
        printf "  ${RED}✗${RESET}  %s\n" "$s"
    done
    echo ""
    exit 1
fi
