---
paths:
  - "tests/**"
  - "scripts/__tests__/**"
  - "resources/js/**/__tests__/**"
  - "phpunit.xml"
  - "vitest.config.js"
  - "playwright.config.js"
---

# Rules — tests

Read [the doctrine](../testing/doctrine.md#the-doctrine) before writing or changing any test,
however small the change: three lines of regex are not exempt.

## Write

- Test each thing at its lowest level: the logic in a unit test, the wiring once in a Feature test,
  what the user sees in Playwright. A higher level never repeats a lower one.
- Write the cases from the specification, starting from what the code must **refuse**: the refused
  case, the error, the boundary, the third party that does not answer, the storage that throws, the
  improbable input. One test per acceptance criterion is the floor, not the measure.
- Give a tolerance you add — a second accepted form, a branch of an `||` — a permanent test that
  exercises it on fixed input. A manual run is not a test.
- Ship a fix with the test that fails without it.
- Never let a test depend on the order of execution, on what another test left, or on a date computed
  from now that decides the result.
- Never weaken, skip or comment out a test to get a green run.

## Prove

- Trust a test once breaking the code has turned it red, for the right reason. Run both passes: hand
  mutations chosen from what the code must refuse, then `node scripts/mutate-lines.js <file> -- <command>`.
  Give every survivor a written verdict, and the count.
- Restore from a copy, never with `git checkout --`.
- **Never mutate code that destroys data.** List first what the file executes — a database, the file
  system, the network, a server. If anything reaches outside the test sandbox, mutate it only under a
  replaced executor. In doubt, ask.

## Run

- PHP tests run on PostgreSQL, database `cardascia_it_test`; never SQLite, never `cardascia_it`.
- Never reach the network: `Http::preventStrayRequests()` is on for the whole suite.
- A deprecation fails the suite; so does a test that checks nothing — `failOnRisky` for PHPUnit,
  `expect.requireAssertions` for Vitest.
- Coverage: 80% enforced, 99% targeted, 100% for logic. Never exclude code to pass a threshold: a
  guard that cannot fire is deleted.
- Playwright: import `test` and `expect` from `tests/e2e/helpers/test.js`. The suite tests the
  compiled build — run `npm run build` after a front-end change; `E2E_DEV_SERVER=1` tests the dev
  server, on purpose only.

References: [testing conventions](../testing/README.md).
