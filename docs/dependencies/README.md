# Dependency updates

Delivered by LUMN-21. Configuration: `renovate.json5` at the repository root — JSON5 rather than
JSON so every rule carries its reason in place.

Renovate runs as the hosted **Mend Renovate GitHub App** (free "Community Cloud" tier), installed on
this repository only. Nothing runs in CI: the app reads the repository on Mend's servers, pushes its
own `renovate/*` branches and opens pull requests like any contributor. Those PRs go through the
same ruleset as every other one — the [required checks](../git-workflow.md#required-checks), squash
only, no bypass.

| Part | What it holds |
|---|---|
| [renovate.md](renovate.md) | Renovate — what it does, and why |
| [renovate-day-to-day.md](renovate-day-to-day.md) | Renovate — installing it, and day to day |
| [frankenphp.md](frankenphp.md) | The FrankenPHP binary |
