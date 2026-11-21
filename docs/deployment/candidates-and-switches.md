# Deployment — Candidates and switches

Part of [Deployment](README.md).

## Deploying a candidate, before it reaches `main`

The sequence above deploys what is already released. A change that can only be *validated*
on a server — anything about proxies, TLS, paths, or interpreter versions — has to be
deployed before it is merged, or the pull request waits on a measurement the merge is a
precondition for. The pipeline's key cannot do it, on purpose: this is a gesture made by hand.

```bash
# 1. release the candidate
cd ~/lumina/preprod
git -C repo fetch origin <branch>
bash current/scripts/release.sh FETCH_HEAD

# 2. measure. Anything found goes back onto the same branch — the squash merge
#    still produces one commit, so the ticket keeps its single commit on main.

# 3. once merged, let main take the site back
git -C repo fetch origin main
bash current/scripts/release.sh FETCH_HEAD
# …and verify again on what is actually released.
```

**Step 3 is not optional.** A server left on a candidate serves code that is not `main` until
something deploys over it — and until LUMN-53, nothing does so by itself. The pipeline can do
step 3 as well: the merged commit is not older than the candidate, so the gate accepts it.

This is safe here because preprod sits behind HTTP Basic authentication: a candidate
carrying a known defect is unreachable while it is being measured.

## Measuring a switch

```bash
SMOKE_BASIC_USER=… SMOKE_BASIC_PASSWORD=… \
  bash scripts/watch-switch.sh https://preprod.cardascia-it.org/fr
```

Run from your own machine, started just before a deployment or a rollback. Over and over it asks
for the page, reads which release answered in `X-Release`, asks for every compiled file the page
names, and waits a fifth of a second — about one round a second against preprod, 300 rounds by
default. It prints a line each time the release changes or something is missing, and exits 1 if a
single answer would have shown a visitor a broken page.

Give it the SHA the switch should end on as a third argument, and it also fails when that release
never answered:

```bash
bash scripts/watch-switch.sh https://preprod.cardascia-it.org/fr 300 <the SHA deployed>
```

That second question is not a refinement. The first time this script watched a real switch, it
answered "no broken page" in all honesty while the deployment had changed nothing a visitor could
see: [OPcache does not follow the link](history.md#opcache-does-not-follow-the-link).
