# Smoke tests

Delivered by LUMN-49. `php artisan deploy:smoke` answers one question: **does this deployment, in
this environment, work?** It runs right after a deployment, and its exit code decides whether the
release is promoted.

| Part | What it holds |
|---|---|
| [overview.md](overview.md) | What they are, and how to run them |
| [probes.md](probes.md) | The probes |
