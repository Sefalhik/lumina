# Deployment — The pipeline's access to the server

Part of [Deployment](README.md).

## The pipeline's access to the server

Until LUMN-50 the link ran one way: the server fetches from GitHub with a read-only deploy key.
A pipeline needs the other direction — GitHub Actions opening an SSH session on the hosting
account — and that is the one credential in this project that reaches a machine holding the
`.env`. `.github/workflows/deploy.yml` uses it; LUMN-53 will make it run on every merge.

### What the pipeline's key can do

Left as it is generated, an SSH key opens a full shell on the account: whoever obtains it reads the
`.env`, so the database password and `APP_KEY`. Storing it in the right place is not enough. What
it *allows* is bounded on both sides.

**On the server**, by its line in `~/.ssh/authorized_keys`:

```
command="/usr/bin/env bash /home/cardascia-it/lumina/preprod/current/scripts/deploy-gate.sh",restrict ssh-ed25519 AAAA… github-actions deploy, preprod
```

- `restrict` refuses a terminal, port forwarding and agent forwarding.
- `command="…"` is a **forced command**: sshd runs `scripts/deploy-gate.sh` whatever the caller
  asked for, and hands the request over in `SSH_ORIGINAL_COMMAND`. It is named through `current`:
  the gate that answers is always the one of the release in service. It honours three requests —
  `php-version`, `deploy <sha>` and `rollback <sha>` — and refuses everything else, without ever
  repeating it. It decides and never builds: `release.sh` and `rollback.sh` do.
- `<sha>` has to be the full SHA of **a commit of `main`**. The shape alone would not do: the
  repository is public, and GitHub serves the commit of any fork by its SHA from this repository's
  address. The gate fetches `refs/heads/main` and nothing else, then asks git whether the commit
  is one of its ancestors.
- The commit must **not be older than the one being served**. A commit of `main` is not harmless
  because it was once reviewed: the one from before a fix is the flaw itself, and a stolen key
  would bring it back. Going back is a decision a human makes, by hand. Deploying again the commit
  being served is not going back, and stays possible — a deployment that failed halfway can be
  replayed.
- The commit also has to **carry the four deployment scripts**, each as a regular file — a
  directory or a link of that name would be found and could not be run. A commit that lacks one
  would become the release in service with nothing to answer the key any more: no gate, or a gate
  with nothing to hand the work to. Deploying such a commit is a human's decision. Whether a
  script *works* is not the gate's to judge: that is what the required checks of `main` are for.
- **What git cannot tell is refused.** "Is this commit older than the one being served?" has three
  answers: yes, no, and an error, when the repository has lost an object. The error is a refusal,
  never a "no". This rests on git 2.45 or newer, which reports such a loss; older versions answer
  "no" and say nothing. The server runs 2.45.1 (measured on 2026-10-11).
- All of this is measured against the commit being served, read in `current/RELEASE`. A server
  that cannot name it — no file, something that is not a SHA, a commit its repository does not
  hold — is **refused everything**: "not older than nothing" would let any commit of `main` through.
- **`rollback <sha>` undoes one deployment, the last, for half an hour.** `<sha>` has to be the
  commit being served, put there by a deployment less than thirty minutes ago, and the release it
  replaced has to be still on disk. Asked later, twice, or of anything but the last deployment, it
  would be the way back to an older commit that `deploy` refuses — and it is refused the same. The
  gate never names a release to `rollback.sh`: which one to go back to is recorded by the
  deployment itself. Going back asks nothing of the repository: the day a deployment went wrong
  because the repository did, the way back does not depend on it.
- **One request at a time.** The gate takes the deployment lock last, once it has decided, and
  hands it to the script it runs. A request made while a deployment is running is refused as
  `busy` — unless it would have been refused anyway, in which case it is told why.
- The shape of the SHA is checked against a list of characters, not a range: in a UTF-8 locale
  bash reads `a-f` by collation, `[0-9a-f]` then matches `é` and the digits of other scripts, and
  the locale is the one thing an SSH client is commonly allowed to send.
- One `ed25519` pair per environment. Revoking preprod's does not touch production's, nor any
  human access.

A stolen key can therefore move the server forward along `main`, or take back the step it has
just made, and do nothing else. The gate keeps one line per request in
`shared/storage/logs/deploy-gate.log`: when, from which address, what was decided.

**In GitHub**, by where the key lives and by what the workflow is allowed to be:

| | |
|---|---|
| The key | secret `DEPLOY_SSH_KEY` of the **environment** `preprod` — a job reads it only if it names the environment, and only `main` may deploy to it |
| The server's identity | variable `DEPLOY_SSH_KNOWN_HOSTS`: the host key, **pinned**. The connection uses `StrictHostKeyChecking=yes`; the workflow never runs `ssh-keyscan` and never accepts a key it meets |
| Where to connect | variables `DEPLOY_SSH_HOST` and `DEPLOY_SSH_USER` — not secrets, both are in this file |
| Third-party code | **none**. The workflow uses no action, not even one of GitHub's: `ssh` is already on the runner |
| What a caller controls | one input, a closed list of three words, read through an environment variable. The SHA is `github.sha` |
| Token | `permissions: {}` |

`scripts/__tests__/deploy-workflow.test.js` holds each line of that table, and runs the step of the
workflow against a stand-in for `ssh`: the key file is `600` while it exists and gone when the step
ends, whether the server answered or not.

**The pipeline cannot deploy a candidate branch**, on purpose: the gate only knows `main`.
[Deploying a candidate](candidates-and-switches.md#deploying-a-candidate-before-it-reaches-main) stays a gesture made by
hand until LUMN-53 decides otherwise.

### Setting it up

**1. The gate has to be on the server before the key is tied to it.** The site has to be serving
a release that contains the four scripts — see [Setting up a site](server.md#setting-up-a-site).

**2. Generate the pair**, on your own machine:

```bash
ssh-keygen -t ed25519 -N "" -C "github-actions deploy, preprod" -f ~/.ssh/lumina-deploy-preprod
```

**3. Pin the host key.** Fetch it once, and compare its fingerprint **by eye** with the one the
alwaysdata panel displays under *Remote access → SSH* before storing it:

```bash
ssh-keyscan -t ed25519 ssh-cardascia-it.alwaysdata.net > /tmp/lumina-known-hosts
ssh-keygen -lf /tmp/lumina-known-hosts
```

`ssh-keyscan` is acceptable here and nowhere else: its answer is checked against a source the
network cannot forge before it is trusted. Run by the workflow, it would ask an attacker who the
server is and believe the reply.

**4. Authorise the public half on the server**, with its restrictions. Through your own access,
keep a copy of `~/.ssh/authorized_keys`, then append the line shown above, the key being the
content of `~/.ssh/lumina-deploy-preprod.pub`:

```bash
ssh <your access> 'cp ~/.ssh/authorized_keys ~/.ssh/authorized_keys.before-deploy-key'
printf '\ncommand="/usr/bin/env bash /home/cardascia-it/lumina/preprod/current/scripts/deploy-gate.sh",restrict %s\n' \
  "$(cat ~/.ssh/lumina-deploy-preprod.pub)" | ssh <your access> 'cat >> ~/.ssh/authorized_keys'
```

Appended, never edited in place: your own access is another line of that file, and the copy is the
way back. The leading `\n` is for a file whose last line does not end with one — the new entry
would otherwise be glued to the previous key and neither would work.

Then measure what the key can do, from your own machine, before GitHub ever sees it:

```bash
bash scripts/check-deploy-key.sh <user>@ssh-cardascia-it.alwaysdata.net ~/.ssh/lumina-deploy-preprod /tmp/lumina-known-hosts
```

One thing has to work and seven must not: `php-version` answers; a shell, a command, a terminal,
the sftp subsystem, a file copy, a tunnel through the server and a port opened on it are refused.
The script exits 1 as soon as one restriction is missing, and never prints what a probe received.

**This is the only test of the line typed in `authorized_keys`.** The gate's own tests prove what
it does with a request; they cannot prove that sshd hands it every request. A key that answers
`php-version` and also opens a tunnel to the database has `command="…"` and no `restrict`, and
nothing but this measurement would say so. A refusal only counts when it is one: the script
wants the gate's own word, or sshd's, because an allowed tunnel also fails when nothing listens
at its far end.

Two probes never hear the gate, and are judged on what does come back. A terminal turned down
ends the session: `ssh -tt` takes the refusal for fatal, says so and leaves with 255 before the
command is sent. And sshd discards the standard error of a subsystem: the gate refusing `sftp`
comes back as its exit status, 1, without a word — where a real sftp server, its input ending at
once, leaves with 0 just as silently. Both were measured on preprod on 2026-10-10 and 11, after a
first version of the probe had waited for the gate's word on each and failed a key that was
restricted as it should be. Under a ✘ the script now says what it wanted and what it observed: an
exit status and the names of the sentences it looks for, never the answer.

**Measured on preprod on 2026-10-11**, OpenSSH 9.2 on the server and fish as the account's shell:
the eight probes answer as described, and `php-version` finds PHP 8.5.11 in a session with no
terminal.

**5. Create the environment and fill it:**

```bash
gh api -X PUT repos/Sefalhik/lumina/environments/preprod \
  -F 'deployment_branch_policy[protected_branches]=false' \
  -F 'deployment_branch_policy[custom_branch_policies]=true'
gh api -X POST repos/Sefalhik/lumina/environments/preprod/deployment-branch-policies -f name=main -f type=branch

gh secret set DEPLOY_SSH_KEY --env preprod < ~/.ssh/lumina-deploy-preprod
gh variable set DEPLOY_SSH_KNOWN_HOSTS --env preprod < /tmp/lumina-known-hosts
gh variable set DEPLOY_SSH_HOST --env preprod --body ssh-cardascia-it.alwaysdata.net
gh variable set DEPLOY_SSH_USER --env preprod --body <user>
```

`< file` rather than a paste: a key pasted through a terminal or a web form can pick up Windows
line endings, and ssh then rejects it as `invalid format`.

**6. Delete the private half from your machine**: `rm ~/.ssh/lumina-deploy-preprod`. It now exists
in one place, where nobody can read it back. Losing it costs a rotation, nothing more.

**7. Ask from GitHub:** `gh workflow run deploy.yml --ref main -f request=php-version`.

### Rotating or revoking the key

**Revoking** is one line: delete the key's entry from `~/.ssh/authorized_keys` on the server. It
takes effect at once, and no human access goes through that entry. Then remove the secret:
`gh secret delete DEPLOY_SSH_KEY --env preprod`.

**Rotating** is steps 2, 4, 5 (the secret alone) and 6 again — `check-deploy-key.sh` included,
since a new line in `authorized_keys` is a line typed again. The old line is removed once the new
key has answered `php-version` from GitHub. Rotate after any doubt, and when a machine that ever
held the private half is retired.

When alwaysdata changes a server's host key, the connection fails — which is the pinning doing its
job. Read the new fingerprint in the panel and redo step 3, then the `DEPLOY_SSH_KNOWN_HOSTS`
line of step 5.

### What this does not cover

- **No short-lived credential.** The reference practice is to store no durable secret at all —
  OIDC federation, or SSH certificates valid for minutes. Shared hosting offers neither: the key
  lives long, and it is what the key may do that is bounded.
- **No filtering by address.** GitHub-hosted runners leave from a range too wide and too changing
  for a `from=` option.
- **The GitHub account is the root of trust.** Whoever controls it approves deployments and
  replaces secrets. Its two-factor authentication is on (read through the API on 2026-10-10); the
  hosting account's has to be checked in its own panel.
- **The database.** A rollback puts the code back, never the schema: that is the twin ticket on
  reversible migrations, and until it exists a migration that an older release cannot live with
  makes that release impossible to go back to.
- **Half an hour is a judgement.** It is long enough for a smoke test to fail and ask for the
  rollback, short enough that a key stolen later finds nothing left to undo.

Measured on 2026-10-11, the day of the first deployment asked from GitHub: a session opened by the
pipeline's key finds the PHP and the Node the panel selects — `php-version` answered 8.5.11, and
`npm ci` and `npm run build` ran in the `deploy` request that followed.

---
