# Deployment — Deploying

Part of [Deployment](README.md).

## Deploying

## How a site is laid out

Until LUMN-51 the server was one checkout, modified in place: for a few seconds a deployment
served new code with the previous assets, and nothing was kept to go back to. A release is now
built in a directory nobody is being served from, and put in service by changing one link.

```
/home/cardascia-it/lumina/preprod/
├── repo/                          the repository, bare: what the server knows of git
├── releases/
│   ├── 20261011-050129-794dbbf…/  one directory per release: <UTC moment>-<commit>
│   └── …
├── shared/
│   ├── .env                       linked into every release
│   ├── storage/                   linked into every release
│   ├── deploy.lock                held while a deployment or a rollback runs
│   └── last-deployment            what a rollback undoes
└── current -> releases/…          the release in service — the only thing a switch changes
```

Four scripts do the work. Each is run by `bash`, by name: the account's shell is fish, where a
loop or a `[ ]` test does not mean what it means in a script.

| Script | What it does | Who runs it |
|---|---|---|
| `scripts/deploy-gate.sh` | decides whether a request made with the pipeline's key is honoured | sshd |
| `scripts/release.sh <commit>` | extracts that commit into a new release directory | the gate, or a human |
| `scripts/deploy.sh` | builds the release it sits in, puts it in service, lets the oldest go | `release.sh` |
| `scripts/rollback.sh [<release>]` | puts back in service a release that was served | the gate, or a human |

They are reached through `current`, so they are always those of the release in service — with one
exception that matters: `release.sh` ends by running the `scripts/deploy.sh` **of the commit it has
just extracted**. How a release is built is written in that release.

## Releasing a commit

The pipeline asks through the gate — see
[The pipeline's access to the server](pipeline.md#the-pipelines-access-to-the-server). By hand:

```bash
# On the server
cd ~/lumina/preprod
git -C repo fetch origin main
bash current/scripts/release.sh FETCH_HEAD
php artisan db:seed --class=AdminSeeder          # first deployment of an environment only, from current/
```

`release.sh` fetches nothing and judges nothing: it releases a commit the repository already holds.
Deciding which commits may be deployed is the gate's work when the pipeline asks, and yours when
you type this.

`scripts/deploy.sh` runs, in this order:

```bash
composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build
php artisan migrate --force --no-interaction
php artisan db:seed --class=HomepageContentSeeder --force --no-interaction
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

`scripts/__tests__/deploy.test.js` fails the day that listing and the script stop agreeing. Around
it, the script does four things the listing does not show:

- **Before the first command**, it links `shared/.env` and `shared/storage` into the release.
- **After the build**, it adds to its compiled files those the release in service had built. Their
  names carry a hash and never collide. The pages the previous release is still sending, and the
  ones already open in a browser, name those files: they have to answer after the switch too.
- **After the last command**, it changes `current` in one rename. A request finds the previous
  release or the new one, never half of each — and the previous one stays on disk, untouched.
  The switch takes effect at the next request because `public/index.php` asks for it: see
  [OPcache does not follow the link](history.md#opcache-does-not-follow-the-link).
- **Then** it records the deployment in `shared/last-deployment`, and removes old releases: five
  served releases are kept, the one in service included.

What follows from running in a directory of its own:

- **A step that fails removes the release it was building**, and the site goes on exactly as it
  was: same link, same files, same `X-Release`. So does a deployment that is interrupted — a
  connection that drops, a workflow that is cancelled. One that is killed outright tidies nothing,
  and nothing has to be: what it was building was never served, a rollback refuses it, and the next
  deployment removes it.
- **The release in service is never the one removed.** Whether a release was put in service is
  asked of the link, not remembered by the script: interrupted the instant after the switch, it
  leaves the new release where it is.
- **A disk that fills up changes nothing that is served.** Every write the script makes itself —
  the two links, the list and the copies of the compiled files, the marker, the new link, its
  rename — is failed in turn by the tests, as a full disk fails it. Before the switch, the site
  stays entirely on the release it was serving and the deployment fails; after it, the release is
  in service and the deployment succeeds, saying what it could not write.
- **A release keeps its commit in the repository.** `release.sh` records it under
  `refs/releases/<name>` for as long as the directory exists. A candidate is fetched without a
  branch to hold it, and git removes in time what nothing refers to: the gate could then no longer
  read the commit it serves, and would refuse everything.
- **Migrations run before the switch.** For a few seconds the release in service runs against the
  new schema, and after a rollback an older release does for good: a migration has to stay
  compatible with the release before it.
- **One deployment at a time.** The lock is held on a file descriptor, so it is released when the
  last process of the deployment ends, however it ends: there is no file to delete after a crash.
  A second deployment is refused at once — `a deployment is already running on this server`.
  A script that is told the lock is held checks that it is before it believes it.
- **`--force` on `db:seed`.** In production Laravel asks *"Are you sure you want to run this
  command?"*, and a session nobody is typing in answers no: `Command cancelled`, exit 1.
  `--no-interaction` leaves the seeder's own question on its default, which is to keep published
  content.
- **The release names itself.** `release.sh` writes the commit SHA into `RELEASE` when it extracts
  it; `config:cache` freezes it, and every response of that release carries it as `X-Release` —
  see [Smoke tests](../smoke-tests/README.md).

Then, **from your own machine and not from the server** — it is the outside view that
matters:

```bash
SMOKE_BASIC_USER=… SMOKE_BASIC_PASSWORD=… \
  php artisan deploy:smoke --url=https://preprod.cardascia-it.org --expect-release=<the SHA deployed>
```

Nineteen probes, under a minute, read-only: it replays what the first deployment checked by
hand in `docs/smoke-tests/README.md`. Exit code 1 means the release must not be promoted. Until the
pipeline runs it (LUMN-53), running it by hand is the last step of a deployment, not an
optional extra.

`npm ci` and `npm run build` run **on the server**: `/public/build` is gitignored, so the
assets exist nowhere else. Measured: 6 seconds, on a host with 32 GB of memory and 2 TB
free. A release weighs about 260 MB with its `vendor/` and `node_modules/` (measured on
2026-10-11), so five of them are not a question either.

`HomepageContentSeeder` is safe to run on every deployment: its `confirm()` defaults to
`false`, so a non-interactive run populates an empty row and otherwise changes nothing.
Editing live content is the admin form's job.

**`config:cache` freezes `.env`.** After it runs, `env()` outside a config file returns
`null`. A change made to `shared/.env` takes effect at the next release or the next rollback:
both cache the configuration of the release they put in service.

## When a deployment fails

A deployment nobody watches has to say by itself what went wrong, what state it left, and where
the rest is written.

**What the scripts say.** The command that failed speaks first, in its own words. The script then
adds what that command cannot know:

```
Deployment of <sha> FAILED while running "npm run build" (exit status 1).
The site serves what it served before: <sha>, release <name>.
The release that was being built was removed: nothing is left to clean up.
```

"While …" names the step — one of the eight commands, or `preparing the release`, `keeping the
compiled files of the release in service`, `putting the release in service`. An interruption is
said as one (`exit status 143, interrupted by a signal`) and is a failure: left to itself, bash
would have ended with the status of the command it was waiting for, often 0. `release.sh` and
`rollback.sh` end the same way, and a script stopped the instant *after* a switch says that the
release is in service instead of announcing a failure that did not happen.

**What the server keeps**, in `shared/storage/logs/`, whoever asked and wherever their own log went:

| File | Written by | One line per |
|---|---|---|
| `deploy-gate.log` | the gate | request made with the pipeline's key: when, from which address, accepted or refused and why |
| `deployments.log` | `release.sh`, `deploy.sh`, `rollback.sh` | thing that happened to the site: `deployed <sha> as <release>, replacing <release>`, `failed <sha> while <step> (exit <n>)`, `rolled back to <sha> as <release>, leaving <release>` |
| `deployments/<release>.log` | `release.sh` | deployment: **everything it said**, from the name of the release to its last word. The last twenty are kept, failed ones included |

None of them is ever a reason to fail: a journal that cannot be written is said on the spot, and
the deployment stays what it was.

The third is the one to open when the log of the pipeline stops in the middle of a sentence. A
connection that drops takes that log with it; the file on the server goes on being written, by two
copies of the output — what was said, what went wrong — that ignore the signal a terminal sends
to everything it started when it hangs up. Each copy writes as it receives: where two steps meet,
the last lines a command sent to one stream can land under the heading of the next step. A
rollback says three lines and keeps no such file: its line in `deployments.log` is the whole of it.

**What the page of the run shows**, without opening a log: the request, the commit, and the
outcome — with an error annotation when it is not `done`.

| Exit status of the step | It means |
|---|---|
| `0` | done |
| `1` | refused by the gate, or failed on the server: the reason is in the log of the step |
| `75` | refused: another deployment was running |
| `129`, `130`, `141`, `143` | a script on the server was interrupted |
| `255` | nothing was asked: the connection to the server failed |

**What none of this says**: whether the site answers. That is `deploy:smoke --expect-release`,
which fails when the release expected is not the one serving — a switch that did not take effect
looks exactly like a success from the server's side.

## Rolling back

```bash
# On the server
bash ~/lumina/preprod/current/scripts/rollback.sh                    # undo the last deployment
bash ~/lumina/preprod/current/scripts/rollback.sh <release name>     # or go to a release of releases/
```

Going back is one link changing, towards a directory that was built and served before: no network,
no build, a few seconds. `rollback.sh` first runs `config:cache` in the release it goes to —
`.env` may have changed since that release was built — and then switches.

- **Without a name, it undoes the last deployment, and only that.** Once undone, or once another
  release has been put in service by hand, there is no "last deployment" left: going further back
  is a release to name, among those of `releases/`.
- **A release is named, never pointed at.** A path is refused, and so is a directory that was
  never served.
- **The database does not go back.** Migrations only go forward; the script says so every time.
- **Nothing is removed.** The release that was left can be put back the same way, by name.
- **When the link itself leads nowhere** — the directory of the release in service was removed by
  mistake — `current/scripts/rollback.sh` is gone with it, and every page answers an error. Run
  the script of any release that is left, and name the one to put in service:
  `bash ~/lumina/preprod/releases/<a release>/scripts/rollback.sh <release name>`.

Then run `deploy:smoke --expect-release=<the SHA the script printed>`: a rollback is a switch like
any other, and is verified like one.

The pipeline can undo too, within limits: `gh workflow run deploy.yml --ref main -f request=rollback`
asks the gate to undo the deployment of that commit, and the gate only agrees for half an hour —
see [What the pipeline's key can do](pipeline.md#what-the-pipelines-key-can-do).
