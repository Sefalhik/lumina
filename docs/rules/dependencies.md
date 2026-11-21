---
paths:
  - "renovate.json5"
  - "composer.json"
  - "package.json"
  - ".npmrc"
  - ".nvmrc"
  - "scripts/update-frankenphp.sh"
---

# Rules — dependencies

- Change `composer.json` or `package.json` only with Laurent's explicit go.
- Keep every npm constraint on `^`; never pin an exact version. The committed lockfile and `npm ci`
  already decide what is installed.
- Treat a runtime major — PHP, Node, PostgreSQL — as a coordinated change across every environment,
  under a ticket: never a Renovate PR. The version in force is the one production runs.
- Never merge a Renovate **config migration** PR: it rewrites `renovate.json5` and drops every
  comment. Port the renamed option by hand, keeping its comment, and close the PR.
- Keep the release age the same in `renovate.json5` and `.npmrc`: `ReleaseAgeParityTest` fails when
  they disagree, and the document quotes both.
- Validate a configuration change before merging it:
  `npx --yes --package renovate -- renovate-config-validator --strict`.
- Install FrankenPHP only through `update:frankenphp`, which verifies what it installs.

References: [dependency updates](../dependencies/README.md).
