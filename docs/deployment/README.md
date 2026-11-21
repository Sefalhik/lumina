# Deployment

Reference for running this application somewhere other than a developer's machine.
Hosting is **alwaysdata**; the site is served by **Apache, with PHP over FastCGI** (`cgi-fcgi`, measured on 2026-10-11 — not PHP-FPM, as this file said until then), behind a
TLS-terminating front proxy.

**This file contains no secret values and never will.** It documents where each
value *comes from*, so that a new environment can be rebuilt without hunting.

---

| Part | What it holds |
|---|---|
| [environments.md](environments.md) | Environments, secrets and drivers |
| [server.md](server.md) | The server |
| [deploying.md](deploying.md) | Deploying |
| [candidates-and-switches.md](candidates-and-switches.md) | Candidates and switches |
| [pipeline.md](pipeline.md) | The pipeline's access to the server |
| [postgresql-version.md](postgresql-version.md) | The PostgreSQL version: production is the reference, and how a major changes |
| [history.md](history.md) | What the first deployments taught |


## Not done yet

- **No deployment on merge.** The pipeline can release a commit and undo it on request (LUMN-50,
  LUMN-51), but someone still has to ask. Deploying every merge is LUMN-53.
- **Nameserver delegation to alwaysdata** is not done (LUMN-96); `cardascia-it.org` still
  resolves through one.com, which redirects to the previous site, untouched. It no longer waits for
  the mail, which moved first — see [The domain and its mail](environments.md#the-domain-and-its-mail).
- **DMARC only observes** (`p=none`). Moving to `quarantine` is a decision to take once the
  aggregate reports show nothing but alwaysdata sending for the domain.
- **No backup of the preprod database.**
- **Nothing distinguishes preprod from production in the logs**, since both run
  `APP_ENV=production` deliberately. A dedicated variable is needed. (LUMN-40)
