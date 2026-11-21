# Dependency updates — The FrankenPHP binary

Part of [Dependency updates](README.md).

## The FrankenPHP binary

`./frankenphp` is the one dependency Renovate cannot see: a standalone binary, gitignored, fetched
from a GitHub release by `scripts/update-frankenphp.sh` (`npm run update:frankenphp`). It never
reaches a server — alwaysdata runs Apache and PHP over FastCGI — but it is the process that serves the site
on a developer's machine, next to the `.env`.

Until LUMN-74, on 2026-10-10, the script installed whatever it received: no checksum, no delay,
and a `curl` without `--fail`, so that an error page would have replaced the binary. It also asked
`dunglas/frankenphp`, a name the project had left for `php/frankenphp`, and worked only because
GitHub redirected. Three things now stand between a release and the binary in place, which is
replaced only once all three have passed:

| Check | What it refuses | Exit |
|---|---|---|
| **Origin** | an answer that is not `php/frankenphp`'s own: any status but 200, redirects included, and a download address that is not exactly `https://github.com/php/frankenphp/releases/download/<tag>/<asset>` | 1 |
| **Release age** | every release younger than the project's release age, or with no readable date — the highest version that has waited is installed instead, and the one that waits is named: see [Which release is installed](#which-release-is-installed) | 0 when no newer release has waited — held, nothing is wrong. 1 when the highest version cannot be dated |
| **Checksum** | a download whose SHA-256 is not the `digest` GitHub publishes for that asset, and a release that publishes none | 1 |

`--force` skips the confirmation prompt and nothing else. An argument the script does not know is
refused: ignoring a mistyped `--min-release-age` would install the release it was meant to hold.
Without `--force`, no answer is a refusal too: at the end of the input, or on a line that never
ends — `printf y` piped in — the script says `Aborted: no answer.` and exits 0, as after `n`. Until
2026-11-07 it stopped on `read` itself, under `set -e`: exit 1, and not a word.

### What each check is worth

**The checksum proves the file is the one GitHub holds, and nothing more.** It catches a truncated
or corrupted download and an error page saved in place of the binary. It does not catch a
compromised release: the digest and the binary come from the same place, GitHub computes the digest
from whatever is uploaded, and a release is not necessarily immutable — v1.13.0 was not.

**Waiting is what covers a compromised release**, exactly as for npm and for actions — see
[Release age](renovate.md#release-age). The number of days is not repeated in the script: it reads
`min-release-age` from `.npmrc`, which `ReleaseAgeParityTest` already keeps equal to Renovate's. A
`.npmrc` that is missing, or whose value is anything but one whole number, is a refusal — a delay
that cannot be read is not a delay of zero. A held release is reported with the date it becomes
installable, and every release waits its own delay — see
[Which release is installed](#which-release-is-installed).

To install a release before its time once its notes have been read — a security release, like
v1.13.0 and its five fixes — replace the delay for that one run, the same way as for npm:

```bash
npm run update:frankenphp -- --min-release-age=0
```

**The redirect is refused rather than followed.** The request to the GitHub API is made without
`-L`: a 301 stops the script with the instruction to change `REPO`. Following it worked, which is
the problem — it works until the redirect ends, or until someone else owns the old name. The
download itself does follow redirects, because GitHub serves every release asset from another
host; that is what the address comparison before it and the checksum after it are for.

**Not covered**: build provenance. Whether FrankenPHP publishes artifact attestations was not
established, and the script verifies none. The API is also queried without a token, so its limit
of 60 requests an hour applies: past it, GitHub answers 403 or 429 and the script says so.

### Which release is installed

**The highest version that has waited the release age, if it is above the one installed.** That is
what npm and Renovate do with the same delay. Until LUMN-98 the script read the latest release
alone, and a young latest held back an older one that had waited: on 2026-11-07, v1.13.5, two days
old, held back v1.13.4, eleven days old, which had to be installed by hand. A project that publishes
more often than the delay would never have been updated.

The script reads one page of a hundred releases — FrankenPHP had published 60 by then, newest first,
and the highest version old enough is always among the most recent — and decides in this order:

| Rule | Why |
|---|---|
| Drafts, pre-releases, and every tag of another shape than `vX.Y.Z` are not candidates; a release counts as published only when `draft` and `prerelease` both read `false` | `v1.0.0-rc.2` to `rc.4` were published in 2023 **without** the pre-release flag: the flag alone lets them through. A flag that cannot be read is not "published" |
| The latest published release must carry a tag of that shape, or the script refuses | ignoring odd tags must not become ignoring a change of format on GitHub's side, which would leave the script on old versions in silence |
| Versions are compared as numbers, not as text and not by date | `v1.13.10` is above `v1.13.9`, and a fix of an older line can be published after a higher version |
| Already at or above the highest version: up to date | nothing to install, so nothing to date — as before |
| The highest version must carry a readable date, or the script refuses | nobody could say when it becomes installable |
| Only versions above the installed one are candidates | a binary installed early with `--min-release-age=0` is never replaced by an older one that has waited |
| A release with no readable date is never old enough | as Renovate decides with `timestamp-required` |

When something is installed while a higher version waits, the summary names that version and the
date it becomes installable. When nothing can be installed yet, the held message names the highest
version, and — when an older one between the two comes of age first — that one too, with its date.

The origin and checksum checks apply to the release chosen, unchanged: its download address must be
its own, and the file must carry its digest — not the latest release's.

### A second way in: `octane:start`

**`php artisan octane:start` can replace `./frankenphp` on its own, past every check above.**
Before starting, Octane reads the binary's version (`frankenphp build-info`) and compares it with
the minimum it requires — `$requiredFrankenPhpVersion` in
`vendor/laravel/octane/src/Commands/Concerns/InstallsFrankenPhpDependencies.php`. Below it, Octane
asks *"Should Octane download the latest FrankenPHP binary version for your operating system?"*,
with **yes as the default**. Started without a terminal — in the background, from a script, by an
agent — nobody answers, and the default is taken.

Measured on 2026-11-07: Octane required 1.13.2, the machine ran 1.13.0, and an `octane:start` run
in the background replaced it with 1.13.5. Against the script, that download:

| | `npm run update:frankenphp` | `octane:start` |
|---|---|---|
| Release | the highest one old enough | the latest, two days old that day |
| Asset | `frankenphp-linux-x86_64` | `frankenphp-linux-x86_64-gnu`, wherever glibc answers |
| Origin, checksum | verified | neither |
| The binary it replaces | left in place on any refusal | renamed `.backup`, deleted once the download succeeds |
| `cap_net_bind_service` | restored | lost |

The last line is what shows first: `npm start` then fails on
`listen tcp :443: bind: permission denied`. The capability is attached to the file, and **any**
replacement of the binary other than the script's loses it — a copy by hand included. Restore it
with `sudo setcap cap_net_bind_service=+ep ./frankenphp`.

**Before `octane:start`, compare the two versions**, and run `npm run update:frankenphp` first if
the binary is below the minimum:

```bash
./frankenphp version
grep requiredFrankenPhpVersion vendor/laravel/octane/src/Commands/Concerns/InstallsFrankenPhpDependencies.php
```

`npm start`, the reference command, runs `./frankenphp` directly and never asks Octane for a
download.

### How it is tested

`scripts/__tests__/update-frankenphp.test.js` runs the real script in a temporary directory, with
a `PATH` that holds nothing but the tools it needs and stand-ins for `curl`, `uname`, `date` and
`sudo`. No request leaves the machine. The stand-in `curl` follows a redirect only with `-L` and
fails on an HTTP error only with `--fail`, so the tests can tell which one the script passed.

Mutated as [the doctrine](../testing/doctrine.md#a-test-is-trusted-once-it-has-bitten) asks, in
two passes.

**By hand: 97 mutations, none survived.** The first round left four alive, all on the check of the
release tag. Its tests passed for the wrong reason — the fixture changed the tag and left the
download address on another version, so the address check refused first and the tag check was
never reached.

**That was not enough.** [The mechanical pass](../testing/doctrine.md#the-mechanical-pass), run the
same day, deleted each of the script's 193 lines in turn, and 36 deletions went unnoticed — an
untested `exit` after "already up to date" among them, and an error message no run could reach.
Eight remain unnoticed, each for a reason: six blank lines of output, a `;;` before `esac`, and an
`exit` that `set -u` makes redundant. The line nobody tested was also wrong: "Bundled PHP" printed
FrankenPHP's own version, having matched the end of that word. It is fixed, and tested.

**LUMN-98, the choice among releases, the same way.** The 35 cases were written from what the
script must refuse or ignore — the 2023 candidates without the pre-release flag among them, and a
binary installed early that must not be replaced by an older one. Two holes showed before any
mutation ran: no case told the age of the release installed from the latest's, and none held the
order "up to date" before "dated".

**Here the mechanical pass proves less than it seems.** The choice is one `jq` program spread over
lines: deleting one breaks its syntax, and the deletion is noticed for that reason alone. The
mutations by hand are what test it, and a first list of 29, all killed, still had four blind
spots — found by reviewing the list, not by running it:

- the "sooner" release could have been an undated one, announced as installable on 1970-01-04;
- a `draft` or `prerelease` flag written as text, `null` or absent passed for "published" — a
  release is now published only when both flags read `false`;
- the filter that says "published" was written twice, and the two copies could drift apart — it is
  written once, and the choice reads it;
- an older release whose tag is not a string made `jq` fail, and the script exit 1 without a word.

Each now has its case, seen red first. In the end, 34 mutations by hand, none survived; the
mechanical pass, 233 lines deleted in turn, left the same eight as above unnoticed.

**The patterns list their characters instead of using ranges.** Run from a UTF-8 locale, which is
how this script is run, bash reads `0-9` and `a-f` by collation: they matched the digits of other
scripts, and `é`. A digest or a version written that way was let through by its shape and refused
further on, so nothing was ever installed — but "refused before any download" was not true there.
The tests run the script under `en_US.UTF-8`, and fail outright on a machine where that locale
changes nothing rather than pass for no reason.
