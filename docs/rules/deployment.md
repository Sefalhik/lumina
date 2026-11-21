---
paths:
  - "scripts/deploy.sh"
  - "scripts/deploy-gate.sh"
  - "scripts/release.sh"
  - "scripts/rollback.sh"
  - "scripts/check-deploy-key.sh"
  - "scripts/watch-switch.sh"
  - ".github/workflows/deploy.yml"
  - "public/index.php"
  - "public/.htaccess"
  - ".env.example"
---

# Rules — deployment and environments

- Set `APP_ENV=production` on every internet-facing host, preprod included. Any other value publishes
  the `/e2e/admin-auth` backdoor. Name an environment after its exposure, not its role.
- Put no secret in the repository. `.env.example` says which variables exist; where each value comes
  from is in [Environment variables](../environment-variables.md) — a new variable gets its row there.
- Keep one driver everywhere: `database` for session and cache, `sync` for the queue, in every
  environment that serves a browser — [why](../deployment/environments.md#one-driver-everywhere).
- Never edit `current/` in place on the server; going back is `rollback.sh`.
- Leave the first lines of `public/index.php` alone: they are not boilerplate — OPcache does not
  follow the release link.
- `config:cache` freezes `.env`: after it, `env()` outside a configuration file returns `null`.
- Run on CI and preprod the PostgreSQL major production runs; a developer machine may be one ahead,
  never behind. Back up every database of the hosting account before changing it: the host migrates
  a whole account at once — [The PostgreSQL version](../deployment/postgresql-version.md).
- Never touch a server, a secret or a `.env` file without Laurent's explicit go.

References: [deployment](../deployment/README.md), [environment variables](../environment-variables.md),
[smoke tests](../smoke-tests/README.md).
