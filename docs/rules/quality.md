---
paths:
  - "phpstan.neon"
  - "phpstan-tests.neon"
  - "phpstan-stubs.php"
  - "eslint.config.js"
  - ".stylelintrc.json"
  - "scripts/check.sh"
  - "scripts/check-coverage.php"
  - "scripts/audit-advisories.js"
  - ".github/workflows/ci.yml"
  - ".github/workflows/security-audit.yml"
---

# Rules — quality tooling

- `npm run check:full` runs the eight checks, and is the verdict before a pull request.
- PHPStan: level 8 on `app/`, level 5 on `tests/`; deprecated code is an error; never `@phpstan-ignore`
  without a written justification. Never bend the analysis to make it pass.
- Size and complexity thresholds are ratchets, measured before being set: code over one is split,
  never the threshold raised — [Size and complexity](../quality/audit-and-analysis.md#size-and-complexity).
- Never hide an alert. An accepted advisory is written down with its reason and a review date, and
  stays visible.
- A check that cannot decide refuses rather than passes: an audit that declines to answer is worth
  more than one that answers wrong.

References: [quality tooling](../quality/README.md).
