# Deployment — The PostgreSQL version

Part of [Deployment](README.md).

## The rule

**Production is the reference** (LUMN-55, decided on 2026-11-21):

- CI and preprod run the PostgreSQL major production runs. A green CI then says something about
  what is deployed.
- Production takes the **latest major its host offers**, under a ticket — never through a Renovate
  pull request: Renovate would move CI alone, and CI would test a runtime production does not run.
- A developer machine may be **one major ahead**, never behind: CI, aligned with production, refuses
  whatever would depend on the newer version.

It replaces the first rule of LUMN-55, of 2026-09-19 — "the version of the latest Ubuntu LTS" —
which assumed a preprod hosted on an Ubuntu machine. Preprod lives at alwaysdata.

## Where each environment stands

Measured on 2026-11-21.

| Environment | Version | How it is known |
|---|---|---|
| Production | **no database yet** — created with the switch to production (LUMN-96) | — |
| Preprod | **18.7** | `php artisan db:show`, on the server |
| CI | **18**, `postgres:18@sha256:eb63372c…` in `.github/workflows/ci.yml`, two services | the image; Renovate brings its new digests, never a new major |
| Developer machine | **19.0**, PGDG cluster `19 main`, port 5433 | `pg_lsclusters` |

alwaysdata offered 16, 17 and 18 that day; 19 was neither offered nor announced.

## Changing the major

The host **migrates a whole account at once** — its documentation says the migration "upgrades your
databases". A production database created in the same account as preprod migrates with it: preprod
can then never rehearse a migration. What rehearses it is CI, first, and the host's own test.

1. **A ticket**, naming the target major and checking the host offers it.
2. **CI first**: move the image — tag and digest — in a pull request, and get the suite green on it.
3. **Back up every database of the account** with `pg_dump`, using the credentials of the release's
   `shared/.env`. On 2026-11-21 preprod was migrated without one; nothing was lost, and the step now
   comes before anything else.
4. **Test, then migrate**, from the host's panel: some migrations offer a test first. The account may
   be unavailable for a few seconds to a few minutes; the sites need not be stopped.
5. **Verify**: `php artisan db:show` on the server for the version, then the smoke tests —
   [Smoke tests](../smoke-tests/README.md).
6. **Going back** is possible for seven days after the migration, from the host's panel.

The developer machine follows when it likes, as long as it stays at or ahead of production.
