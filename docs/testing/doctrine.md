# Testing conventions — The doctrine

Part of [Testing conventions](README.md).

## The doctrine

How tests are written here, before any mechanism. Everything after this section is mechanisms and
traps; this is what they serve.

### Every level, and each thing at its lowest

What can be tested at a lower level is tested there. The higher levels do not repeat it: they prove
what only they can.

| Level | What it proves | What it leaves to the level below |
|---|---|---|
| Unit — PHPUnit `tests/Unit`, Vitest | **the logic**: a service, a rule, a pure function, in every case it can meet | — |
| Feature — HTTP requests, Artisan commands | **the assembly**: that the rule is really wired, the middleware really fires. One case per rule wired is the proof it is called | every edge case of that rule, again |
| Playwright | **what the user sees**: the rendering and the assembly of components, at the end of the chain | the data — it has been proven by then |

"Rendering" does not mean "superficial". The data is tested in full, at the level where that is
cheapest, and that is what lets the browser suite stay fast and stable.

The corollary is architectural, and already a rule:
[no logic in a controller, a model or a Vue component](../rules/backend.md#where-logic-lives).
Logic buried there can only be reached from a higher level, where a test costs more and protects
less.

### Past the happy path

A feature is tested where it works, where it refuses, and where nobody expected it to be:

- the accepted case and the **refused** one — access control on writing as much as on reading;
- the **error** it handles, and the **exception nobody planned**;
- the **boundary values**;
- a **third party that does not answer**, or answers something else;
- a **browser that refuses storage**, on reading as on writing — a private window, blocked site
  data, a full quota. Reading `localStorage` alone can throw. On LUMN-11 this case found a boot
  sequence that could lock the screen for good, and two islands throwing at the page (LUMN-124);
- **improbable input**. Nobody knows what a direct request, a file or another program's output will
  contain: what the code cannot read must fail, not pass for empty.

**One test per acceptance criterion is the floor, not the measure.** Every criterion has a test —
LUMN-10 shipped with one implemented and untested, and the configuration it relied on could have
been removed without a test noticing. But criteria describe what was asked for, and most of what
breaks was not asked for.

**Write the cases from the specification, not from the code.** A test derived from the
implementation agrees with it, omissions included: a rule with three conditions of which two were
coded gets perfect tests of the two. Start from what the code must *refuse*.

### A test is trusted once it has bitten

A green test proves nothing until it has been seen red for the right reason. **Break the code the
test claims to cover, one line at a time, and check that this test fails.** Never call something
covered without having done it.

LUMN-73 is the measured case. Its tests, written one per acceptance criterion, covered 87% of the
script's lines; 11 of 29 single-line mutations left them all green — the script exiting 0 while
printing a failure among them. Rewritten from what the script must refuse, then mutated again:
78 mutations, none survived. Three defects of the script itself surfaced on the way, none of which
a passing test would have found.

Two rules of practice:

- **Restore from a copy, never with `git checkout --`.** On a file that carries uncommitted work,
  the checkout erases it silently.
- **A fix ships with the test that fails without it.** Checked the same way: undo the fix, the test
  must turn red. Otherwise another guard is doing the refusing, and the test passes with or without
  the fix.

### The mechanical pass

Mutations chosen by hand are chosen by whoever wrote the tests, and share their blind spots.
`scripts/mutate-lines.js` chooses nothing: it deletes each line of a file in turn, reruns a command,
and names the deletions that command did not notice.

```bash
node scripts/mutate-lines.js scripts/update-frankenphp.sh -- \
    npx vitest run scripts/__tests__/update-frankenphp.test.js --bail=1
```

It exits 0 when every deletion is noticed, 1 when at least one is not, and 2 when the pass could
not run. It works on anything read line by line — a shell script, a workflow, JavaScript, PHP —
and leaves blank lines and comments alone.

**The measured case is the day it was written, 2026-10-10.** `update-frankenphp.sh` had just been
declared tested: 97 mutations chosen by hand, none surviving. The mechanical pass then deleted each
of its 193 lines, and 36 deletions went unnoticed. Most were lines of display. Four were not:

- the `exit` after "already up to date". The test meant to cover it used a release that could not
  have been installed anyway, "to show neither matters" — so the script went on, held the release
  back, and the test stayed green;
- a binary that prints no version, which no test covered — and whose error message turned out to
  be **unreachable**: the pipeline that reads the version failed first, `pipefail` ended the script
  on that line, and it exited 1 without a word;
- the macOS and unsupported-platform branches;
- `set -euo pipefail` itself.

Four rules come with the tool:

- **Both passes, never one.** The mechanical pass is a floor. It finds the line nobody tests, never
  the rule that was coded wrong: a `<` where a `<=` was meant is not a deleted line. Mutations
  written from what the code must refuse remain the method.
- **Every survivor gets a verdict, written down.** A line to test; a line to delete, because it is
  a guard that cannot fire; or a line whose absence changes nothing — a `;;` before `esac`, a blank
  line of output. "Eight are left" is not a result. "Eight are left, and here is why each" is.
- **A command that already fails is refused.** Every deletion would then look noticed, and the pass
  would report a perfect score from a suite that cannot pass at all.
- **The file is put back, whatever happens** — a command that blows up, `Ctrl-C` — and a copy named
  `<file>.before-mutation` sits beside it meanwhile. If the process is killed outright, that copy
  is the original: the tool refuses to start again until it has been dealt with.

**On the end-to-end infrastructure, targeted mutations first.** A line of a Playwright fixture,
setup or configuration is only noticed by a browser run: about ten seconds a deletion on a subset of
specs, and a subset cannot see what the specs outside it would — remove the seeder only the CV's
accessibility scan needs, and a subset that never opens the CV stays green. The practice, adopted on
LUMN-111 (2026-11-12):

- the **mechanical pass on the fixtures every test goes through** (`tests/e2e/helpers/test.js`,
  `auth.setup.js`): cheap enough, and that is where LUMN-111's first defect lived — 12 lines out of
  12 noticed, once the fixture was fixed;
- **targeted mutations, chosen from what the file must refuse**, for the rest — the setup, the
  configuration, the restore routes — each run on a subset that can actually see it;
- **every survivor of a subset gets a verdict naming the spec that covers it**, rather than a full
  run per survivor. A survivor no spec covers is a test to write, like anywhere else.

The doctrine does not change: both passes, and every survivor judged. What changes is where the
cheaper one is spent.

**Never run a mutation of code that destroys data.** A mutation removes a line, and on a file that
drops, migrates or deletes, the line removed may be the one that chose the target. On 2026-11-12
the mechanical pass ran `tests/e2e/global-setup.js` for real, through Playwright: deleting
`env: { ...process.env, ...env }` let `php artisan migrate:fresh --force` read `.env`, and the
**developer's database was wiped** — while the suite stayed green, on the template the previous run
had left. No backup existed.

- Before any pass, list what the file **executes**: a database, the file system, the network, a
  remote server. If any of it reaches outside the test sandbox, the file is mutated **only under a
  replaced executor** — `vi.mock('child_process')` for `global-setup.js`, in
  `scripts/__tests__/e2e-global-setup.test.js`, which is the only place that file may be mutated.
- The destructive code itself carries an allowlist of what it may touch, checked on every call
  (`artisanEnv()` in `global-setup.js`): a guard a single deleted line cannot route around silently.
- In doubt, ask before running.

### What 100% does not see

The bar for logic is 100%. The threshold enforced today is lower — see
[Coverage thresholds](phpunit-and-vitest.md#coverage-thresholds) — and raising it is LUMN-57. Code is never excluded from
coverage to pass a threshold, and **a guard that cannot fire is deleted, not excluded**: it claims
to check something that is already guaranteed.

A covered line is one that ran, not one that is tested. Line coverage is blind to:

- **several rules on one line.** A validation array is covered by any request and exercised by
  none: one case per rule;
- **a fake that looks like coverage.** A test that reaches an endpoint through a faked response
  never reached the endpoint;
- **the seam between two files** — a file name, a cache key, a path written on both sides. Each
  side is at 100% and nothing checks they agree. A seam gets a test of its own;
- **a tolerance that stops halfway.** What one layer accepts must hold all the way down. Assert the
  effect the accepted value produces, not the status code;
- **a condition taken one way only.** A ternary, a `??`, an `&&`: the line runs, one side never
  does. Measure it with [Branches — Xdebug, on demand](#branches--xdebug-on-demand).

### Branches — Xdebug, on demand

PCOV, which the hook and CI use, measures lines only. Xdebug measures branches. Both are installed
for PHP 8.5 CLI; **Xdebug stays `off`** (`xdebug.mode=off` in
`/etc/php/8.5/mods-available/xdebug.ini`) and is switched on for one run:

```bash
XDEBUG_MODE=coverage php -d memory_limit=6G vendor/bin/phpunit --path-coverage \
    --coverage-text=/tmp/branches.txt tests/Unit/Services/Translation tests/Feature/Console/I18nTranslateTest.php
```

- **Nothing else changes.** When PCOV is loaded, php-code-coverage takes it for line coverage and
  Xdebug only for `--path-coverage`: `composer test:coverage`, the hook and CI are unaffected.
- **`off`, because it was measured** on 2026-11-15: in `develop`, the mode the package installs,
  the suite took 68% longer (49 s against 29 s); in `off`, no difference.
- **Measure the files a ticket touches**, not the whole suite: about 100 s for 160 tests, and the
  default 512 MB runs out.
- **Read the Branches column.** Paths count every combination of branches and grow exponentially:
  they are not a target.

Two kinds of unseen branch are not paths through the code. They get the verdict "unreachable", and
no test:

- **`JMP_FRAMELESS`.** Since PHP 8.4, and so on our 8.5, a call to some built-in functions without a
  leading `\` — `in_array`, `trim`, `dirname`, `implode`, `substr`, `str_replace`… — compiles to a
  jump taken only if a function of the same name exists in the current namespace. None does. To
  check a file:
  `php -d opcache.enable_cli=1 -d opcache.opt_debug_level=0x10000 -r 'opcache_compile_file("app/….php");' 2>&1 | grep JMP_FRAMELESS`.
- **A method passed as a first-class callable** (`$this->showTask(...)`) is recorded under another
  name, and its own entry looks never called.

**The measured case is LUMN-110**: 413 lines out of 413, after both mutation passes. Branches: 15
unseen out of 361, fourteen of the two kinds above, and one real — a `null` in a French file, read
as empty, that no test passed. A covered branch is one that ran, not one that is checked: the
measure points at the conditions to mutate, it does not replace the mutations.

### Never

- **Weaken, skip or comment out a test to get a green run.** A test that passes without checking
  what it names is worse than no test. A red test is a regression or a test to change for a stated
  reason — never one to silence.
- **Ship a feature in one pull request and its tests in another.**
- **Depend on a floating reference**: the order of execution, the state another test left, or a
  date computed from now when that date decides the result. Using the clock is fine; an outcome
  that changes with the day it runs is not. Anchor on absolute dates.
- **Let a test reach the network.** Every HTTP call is faked explicitly, and
  `Http::preventStrayRequests()`, called for the whole suite by `Tests\TestCase` since LUMN-110,
  makes a forgotten one throw — `Http::fake()` limited to one URL used to let every other one
  through. `StrayRequestsAreRefusedTest` fails the day that call is removed.
