# Homepage content and the seed pipeline

Delivered by LUMN-29. The homepage copy ships **with the deployment**, in all 24 locales, and the
translation API is never called while deploying.

## Why the translations are committed

`HomepageContentSeeder` reads `database/data/homepage-content.php` rather than holding strings of
its own. Putting `cms:translate` on the deployment path would mean a production secret, an API cost
per deploy, several minutes of latency, a half-translated page when a call fails mid-run, and a
different result every time. Committing the output removes all five, and puts every translated
string through code review — the only place the five indexable locales (`fr`, `en`, `de`, `it`,
`nl`) get read by a human before a visitor sees them.

## Regenerating the file

```bash
# 1. edit the French values in database/data/homepage-content.php
php artisan db:seed --class=HomepageContentSeeder   # answer "yes" to overwrite
php artisan cms:translate                           # the only API call
php artisan cms:export-seed                         # freeze all 24 locales back
```

Two traps this sequence exists to avoid:

- **`setTranslations()` merges, it does not replace.** Seeding new French onto an existing row
  leaves the old translations of the *previous* text sitting beside it. `cms:translate` has to run
  before the export, or the file freezes new French next to stale locales.
- **`i18n:translate --force` is currently required** to regenerate `lang/*/home.php`, because the
  checksum defect filed as LUMN-25 makes the command answer `unchanged, skipping` forever.

## `php artisan cms:export-seed`

Reads the single `HomepageContent` row and writes `database/data/homepage-content.php`. One
option: `--dry-run`, which lists what it would export and touches no file.

**It refuses more than it repairs**, and each of the three refusals is deliberate — every one
returns a non-zero exit code and writes nothing at all:

| Refusal | Why |
|---|---|
| No row in the database | Nothing to mirror; run `db:seed` then `cms:translate` first |
| A locale outside `supported_locales` | The file is `require`d at deploy time. A key the app cannot serve has no business in it, and `spatie/laravel-translatable` accepts any key it is given |
| A translatable field with no translations | Every translated column is `NOT NULL`, so a file missing one key fails **when the seeder runs it** — at deploy time, with a constraint violation naming a column and nothing else |

That third rule is the one worth remembering: a partial export is not a smaller export, it is an
unusable one. The command fails in front of a developer rather than in a deployment log.

**Locales are sorted** before writing, so two exports of the same row are byte-identical and a diff
only ever shows a content change.

**It generates executable PHP**, so both the locale key and the value are escaped for single-quoted
strings. Note in passing: the key escaping is unreachable while the validation above stands — no
test can exercise it, and deleting it keeps the suite green at 100% coverage. It is kept as belt to
the validation's braces, and `CmsExportSeed` says so in place.

**Memory is bounded by design.** The command builds the whole file in memory before writing:
acceptable because it exports one row, measured at ~55 KB across five translated columns, for one
SQL query and no relations to lazily load. Covering many records — the blog engine — would mean
writing as a stream.

## What is instrumented, and what deliberately is not

`logging-conventions.md` says "every service, controller, and job — no exceptions". Applied to this
feature that gives three answers, not one:

| Component | Logs | Why |
|---|---|---|
| `HomepageContentSeeder` | **yes**, one `info` per branch | It decides whether production ships with its content or quietly keeps what was there. A deployment's console output is usually discarded; these two lines are what answer the question afterwards |
| `CmsExportSeed` | **yes**, on both refusals and on success | Every one writes or refuses to write a file a deployment will execute |
| `HomepageContentService` | **no**, on purpose | A pure function: no I/O, no failure mode, and it runs on every homepage render. The conventions' decision tree yields nothing to log — splitting a string is neither a failure nor a business event — and one line per render would be noise at request rate |
| `HomeController` | **no** | A successful public page render is not an event, and `GeoController` does the same |

Both seeder branches are asserted by `HomepageContentSeederTest` against the three mandatory
context keys, not merely against "something was logged".

## The seeder never overwrites published content

Its `confirm()` defaults to `false`, so a non-interactive run — every deployment — populates an
empty row and otherwise does nothing. Editing live content is the admin form's job, not a
redeploy's.

## The `fallback_*` keys are not a second copy

`lang/*/home.php` still carries `fallback_tagline`, `fallback_subtitle` and `fallback_bio`, but
they are **deliberately neutral placeholders**, not the real copy. The view calls
`getTranslation($field, $locale, true)`, and spatie's fallback returns the French value for a
missing locale — **never `null`** — so `?? __('home.fallback_bio')` only ever fires when the row is
absent entirely. Duplicating the real text there would give it a second home and let the two drift.

## The bio is split, and why

`HomepageContentService::prose()` returns the first paragraph as the hero's `lead` and the rest as
`body`, which the About section renders as real `<p>` elements. Two defects made that necessary:

- **The hero held the whole bio.** At 960 characters that is roughly 435px of text, which pushed
  the buttons about 350px below the fold. Identity, hook and call to action now fit above it; the
  prose gets a section where it is allowed to be long.
- **Paragraphs were not rendering at all.** HTML collapses the blank lines the admin textarea
  produces, and the `<p>` carried no `whitespace-pre-line`, so five paragraphs came out as one
  unbroken block. Real elements were chosen over that class: screen readers announce them, and the
  spacing is controllable.

Shrinking the font was considered and rejected. `text-lg` to `text-base` buys about 11% of height —
two lines out of fifteen, with the buttons still below the fold — and the bio is
`text-base-content/70`, which [the contrast table](../CLAUDE.md#color-contrast--safe-opacity-thresholds) already places near its limit at that size.
A content-length problem is not a typography problem.

`HomepageProseTest` asserts the **ordering**, not the presence: hook before buttons, buttons before
biography. Asserting presence alone stays green with the whole bio back in the hero, which is
exactly the regression worth preventing.

## `HomepageContentService::prose()` — the paragraph rules

One method, no model, no request, no locale lookup: it takes a string and returns
`['lead' => string, 'body' => list<string>]`. That purity is why the fallback resolution stays in
`HomeController` — see the comment there.

| Input | Result |
|---|---|
| Blank line between paragraphs | the separator — what the admin textarea produces |
| Several blank lines in a row | still one separator, not an empty paragraph |
| A single newline | kept inside the paragraph, not a split |
| `\r\n` or a lone `\r` | normalised to `\n` first, so both split and neither leaves a stray CR inside the prose |
| Leading and trailing whitespace | trimmed, per paragraph |
| `null`, `''`, whitespace only | `['lead' => '', 'body' => []]` — the About section then renders nothing |

A one-paragraph bio yields an empty `body`, and the About section is skipped entirely: a heading
over nothing is worse than no section.

**The separator's greediness is load-bearing.** Because `\s*\n+` swallows a whole run of blank
lines, `preg_split` cannot return an empty segment in the middle, and the leading `trim()` rules out
one at either end. The method therefore filters nothing and guards nothing — it splits, trims and
returns. Narrowing the pattern to `/\n\n/` makes empty paragraphs possible again and the About
section would render blank `<p>` elements.

That coupling is guarded rather than merely written down:
`HomepageContentServiceTest::test_no_output_paragraph_is_ever_empty()` states it as an invariant
over nine blank-line arrangements and fails on exactly that change, naming the offending input.

**Cost**: measured at 0.002 ms for the 960-character bio, with nothing retained across calls. Peak
memory runs about 3× the input — normalised copy, split array, mapped array — which on a field the
admin form caps at 1000 characters is 3 KB. A 1 MB input splits in 9.6 ms. The separator does not
backtrack: 20 000 consecutive whitespace characters return in under a millisecond with no PCRE
error.

## What the tests pin, and what they cannot

`HomepageContentSeederTest` asserts on the shipped data file, not on a fixture: the file is what a
deployment reads, so the file is what has to be right. It checks locale coverage, JSON validity of
`skills`, that seeding sends no HTTP request, and that a second run preserves edited content.

Two deliberate limits, both measured rather than assumed:

- **Duplicate detection covers `bio` and `meta_description` only.** `tagline` is identical in seven
  locales because "Neuromatrix online — biocortex actif" is a coined phrase translators correctly
  left alone. Asserting on it would fail a good decision.
- **Only the French `bio` is length-checked**, at the `max:1000` the admin form enforces on
  `bio.fr`. Translations run longer — German measures 1062 — which is harmless while the form edits
  the source locale alone, and stops being harmless the day per-locale editing ships.
