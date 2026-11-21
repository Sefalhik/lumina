# Internationalisation — The translation commands

Part of [Internationalisation](README.md).

## Translation commands — shared infrastructure

Both `i18n:translate` and `cms:translate` share the same service layer, rewritten by LUMN-110 and
LUMN-33:

| Piece | Where | What it does |
|---|---|---|
| `AnthropicTranslator` | `app/Services/` | The API call. Splits a source into requests whose answer fits in `max_tokens`, and returns **every** key asked for or nothing: a request that fails, an answer cut short (`stop_reason: max_tokens`), an answer missing a key, or a translation that broke its format fails the whole call |
| `TranslationPrompt` | `app/Services/Translation/` | Builds the system prompt from `resources/prompts/translation/` — see [Two trades, two prompts, two models](#two-trades-two-prompts-two-models) |
| `TranslationContext` | `app/Services/Translation/` | The two trades: `Interface` (labels) and `Prose` |
| `TextFormat` | `app/Services/Translation/` | The syntax a text lives in — Laravel, vue-i18n, plain — and the check that a translation kept it |
| `TranslationModel` | `app/Services/Translation/` | One context's model, effort, `max_tokens` and price, read from `config/i18n.php` |
| `TranslationPlanner` | `app/Services/Translation/` | Decides which keys a target needs — a pure function, see below |
| `TranslationSources` | `app/Services/Translation/` | The registry of where each translation comes from |
| `LangFiles` | `app/Services/Translation/` | Reads and writes `lang/` PHP files and the islands' JSON, as flat dot-notation maps |
| `LangFileTranslation` | `app/Services/Translation/` | The work of `i18n:translate`: plans, prices, then applies |
| `CmsTranslation` | `app/Services/Translation/` | The work of `cms:translate`, the same way: plans (`CmsPlan`, one `CmsTask` per record and locale), prices, then applies |
| `TranslationEstimate` | `app/Services/Translation/` | Requests, tokens and cost of a run, added up task by task, and the line both commands show before asking |
| `TranslatesFromFrench` | `app/Console/Concerns/` | What the two commands share as commands: the locales of a run, and the one question asked before spending |

The key exists on a developer's machine only — see
[Deliberately absent from preprod and production](../environment-variables.md#deliberately-absent-from-preprod-and-production).

### The translations are the reference — never a cache

Until LUMN-110 a cache in `storage/app/i18n/` held every translation, expired them after 180 days,
and rewrote whole target files from itself. Three defects followed, measured on 2026-11-12 while
adding two labels: a run outside a terminal declared translated what it had not written; expired
labels were translated again, a Danish `å` and a Maltese letter lost on the way; and a correction
made by hand in a target file was overwritten by the next run. 138 lines had to be undone by hand.

Now the target files — and, for CMS content, the database — are the reference. A key is translated
when, and only when:

- the target lacks it, or holds an empty string;
- its French changed since it was translated;
- a previous run failed for that locale, which still owes it;
- `--force` asks for everything.

Every other value is written back as it was. **Nothing expires**: no key is ever translated because
of its age. A key removed from the French is removed from every target.

### The registry — `lang/.translation-sources.json`

For each source (`php:nav`, `js:ui` — names, not paths) and each key, the registry keeps the
fingerprint of the French value its translations were made from, and the keys a locale still owes
after a failed run. It answers one question, *has the French changed?*, and holds no translation.

It is **committed**: a worktree, another clone or another machine reads the same history the files
were written from. Commit it with the files a run changed — a run that changes `lang/` and not the
registry leaves the next run to believe nothing happened.

**A translation with no recorded fingerprint is adopted**, not translated: the target is the
reference, and the registry starts from what it finds. That is how the registry was created, on
2026-11-15, without a single request.

A run on one locale (`--locale=de`) moves the fingerprints forward for all of them: the other
locales are marked as owing every key whose French changed, so they get it on their next run.

## Two trades, two prompts, two models

The site translates two kinds of text, and they are two trades (LUMN-33): the **labels of its
interface** — short, repetitive, read in a button — and **the author's prose** — a voice, a
register, images. Until LUMN-33 one prompt, written for labels, served both, with one model. The
review of 2026-09-13 found nine defects in the four languages read, and the cause was in the
prompt: its rule "keep technical terms untranslated", left to the model's judgement, kept
"biocortex actif" in French in Dutch — and, on 2026-11-15, still in six of the nineteen languages
nobody reads.

| Context | Command | Prompt | Model | Effort |
|---|---|---|---|---|
| `Interface` | `i18n:translate` | `interface.md`, then the syntax of the file's format | `claude-haiku-5-5` | `low` |
| `Prose` | `cms:translate` | `prose.md` | `claude-opus-5-5` | `medium` |

### The prompts are files

`resources/prompts/translation/` holds them, read as text and changed in a diff of their own.
`TranslationPrompt` assembles the system prompt of each request from three parts:

1. **The trade** — `interface.md` or `prose.md`: who the site belongs to (one person — "About",
   never "About us"), what the texts are, and what a good translation of them is. For prose: the
   voice, the register as part of the content, the idiom before the calque.
2. **The syntax of the format**, when it has one — `formats/laravel.md` (`:attribute`, no `|`) or
   `formats/vue-i18n.md` (`{theme}`, no `{ } @ $ |`). Prose has none, and gets none.
3. **The rules of every text** — `rules.md`: every word translated, the glossary, the names of
   people, software and standards kept, the typography of the target language, the JSON to answer.

Two values are filled in, both from `config/i18n.php`:

- `{{language}}` — `translation_languages`, which **names the variant**: British English (a CV is a
  "CV", not a "Resume"), European Portuguese, the Spanish of Spain;
- `{{glossary}}` — `glossary`, **a closed list** of the terms written exactly as they are in every
  language (`Tech Lead`, `Neuromatrix`, `biocortex`, `online`, the theme names). A word that is not
  in it is translated: "biocortex" stays, "actif" does not.

The user turn is the French as a JSON object, slashes and accents unescaped.

### The models are a decision of the project

`config/i18n.php` → `translation` gives each context its model, its effort, its `max_tokens`, and —
for the estimate shown before a run — its price and its measured output ratio. They are **not**
environment variables: a model chosen by a measure is the project's decision, not a machine's
setting (`ANTHROPIC_MODEL` was removed by LUMN-33).

Both models think before they answer, and thinking counts toward `max_tokens`: the translator
refuses a configuration under `AnthropicTranslator::MIN_MAX_TOKENS` (12 000 — the longest answer a
6 000-character chunk can take in Greek, and as much again to think), and refuses an answer cut
short, even one cut before any text was written.

### A translation that breaks its format is refused

A model that follows its instructions scrupulously still slips. `TextFormat::keeps()` checks every
answer: a placeholder lost, added, translated or recased, or a character the format reads as syntax
written where the French had none, and the whole answer is refused and logged
(`AnthropicTranslator API answer broke the format`, with the keys). The keys stay owed, and the next
run asks again. Placeholders may move — grammar needs them elsewhere.

### The bench — how the models were chosen

Run on 2026-11-15, before the choice, by a script kept outside the repository and attached to
LUMN-33 (`lumn33-translation-bench.zip`, with its README): it reads the prompts, the glossary and the
French from the repository, so it measures what ships. Results and reasoning are in LUMN-33.

**Prose** — the homepage copy, translated from scratch (never from the hand-corrected targets) into
the four languages a human reads (`en`, `de`, `it`, `nl`) and three where the defect lived
(`el`, `fi`, `sv`), with five configurations:

| Configuration | Defects of 2026-09-13 back | Cost per language |
|---|---|---|
| Old prompt, Haiku 4.5 — the state before | 3 | $0.003 |
| New prompt, Haiku 4.5 — the prompt alone | 2, "biocortex actif" among them; English words in Italian and Swedish | $0.003 |
| New prompt, Haiku 5.5 | 0 | $0.001 |
| New prompt, Sonnet 5.5 | 0; one misreading in English | $0.008 |
| New prompt, Opus 5.5 | 0 | $0.039 |

The old configuration bringing the defects back is what shows the bench measures the chain, not
chance. Between the three that passed, a blind reading decided — English by Laurent, Italian by a
native reader of the family — for Opus: the most precise, the closest to the register.

**Effort** — a second run: Opus at `low` kept the meaning but lost some of the turns the readers had
chosen; Haiku 5.5 at `medium` gained nothing constant on labels over `low`, for twice the cost.
Hence `medium` for prose and `low` for labels.

**To run it again** — on a change of model or of prompt: the same seven languages, the old
configuration as the control (it must still show its defects, or the bench sees nothing), every
candidate with the new prompt, a mechanical check (the defects of 2026-09-13, French words left in
the translation) and a blind reading by the people who read the language. Its cost is printed
before anything is sent; the 2026-11-15 run cost $0.45.

## `php artisan i18n:translate`

Translates `lang/fr/*.php` and `resources/js/i18n/fr.json` into the other locales — the keys that
need it, and no others.

PHP source files may nest arrays (`validation.php` → `attributes.email`): they are flattened to dot
notation for the planner and the API, and nested again on writing.

**It shows the plan and asks once before spending anything:**

```
  lang/de/nav.php — 2 to translate, 0 to remove
  lang/en/nav.php — 2 to translate, 0 to remove

4 keys in 2 files: 2 API requests to claude-haiku-5-5, about 1450 input and 32 output tokens, about $0.00.

 Translate these keys? (yes/no) [no]
```

The figures are an order of magnitude, not a bill: the output counts the model's thinking at the
ratio the bench measured. Translating the whole interface again, every key in 23 languages, is
estimated at about $0.15.

| Option | Effect |
|---|---|
| `--yes` | Accepts the plan without asking. **Required outside a terminal**: without it, the command stops in error before writing anything — never the silent "no" of 2026-11-12 |
| `--force` | Translates every key again, not only those that need it — after a change of prompt or model (LUMN-33). Still asks |
| `--locale=de` | One locale |
| `--dry-run` | The plan only: no request, no file, no registry. Needs no API key |

The command fails — exit code 1 — when a file cannot be read, or a locale could not be translated:
an answer refused (cut short, declined by a safety classifier, a placeholder broken), or a request
that never reached the API — a timeout is a failed request like any other, and the run goes on
with the next file.
That locale's target is left untouched and owes its keys to the next run. An unreadable target is
never overwritten: read as empty, it would be translated from scratch and every value it held lost.

### Adding a key

1. Add it in French: `lang/fr/<file>.php`, or `resources/js/i18n/fr.json` for an island.
2. `php artisan i18n:translate --dry-run` — the plan names that key in every target, and nothing else.
3. `php artisan i18n:translate` and answer `yes` — or `--yes` from a script.
4. Commit the French, the 23 targets and `lang/.translation-sources.json` together, and read the
   five indexable locales (`fr`, `en`, `de`, `it`, `nl`) in the diff.

## `php artisan cms:translate`

Translates the French of every record of the models listed in `config('i18n.cms_models')`. Each
must implement `App\Models\Contracts\TranslatableContent` — `spatie/laravel-translatable`'s
`HasTranslations` fulfils it — and the command refuses any other: before LUMN-110 a model that
could not store a translation was translated, billed, and the result thrown away.

The same planner decides, field by field, from **the database** — the reference for CMS content —
and a registry kept beside it, in `storage/app/i18n/cms-sources.json` (gitignored), one entry per
record (`cms_HomepageContent_1`). It is local because the database it describes is.

**The trap of a machine without that registry.** A field with no recorded fingerprint is adopted,
like a key of `lang/`. Content seeded on a fresh machine is therefore not translated again — which
is what is wanted. But French changed *before* the first run on that machine would be taken for
translated. **Run `cms:translate` once on a new machine before editing any French**: with the
content in sync it sends nothing and only writes the registry. Making the record travel with the
exported content belongs to LUMN-138.

Its text is the author's prose: it is sent with the prose prompt, to the prose model —
[Two trades, two prompts, two models](#two-trades-two-prompts-two-models).

**It shows the plan and asks once before spending anything**, like `i18n:translate` — the prose
model is the dearest, and since LUMN-33 nothing reaches it unasked:

```
  HomepageContent #1
    → de 5 to translate
    → en 0 to translate

5 fields in 1 records: 1 API requests to claude-opus-5-5, about 1414 input and 3128 output tokens, about $0.07.

 Translate these fields? (yes/no) [no]
```

| Option | Effect |
|---|---|
| `--yes` | Accepts the plan without asking. **Required outside a terminal**: without it, the command stops in error before writing anything |
| `--force` | Translates every field again — after a change of prompt or model |
| `--locale=de` | One locale |
| `--dry-run` | The plan and its price only: no request, no write, no registry |

A plan with nothing to translate asks nothing, and still records the registry.

To add a new CMS model: implement `TranslatableContent`, and add its class to `config/i18n.php` →
`cms_models`.

`storage/app/i18n/checksums.json` and `storage/app/i18n/translations/` are left from the cache
before LUMN-110: nothing reads them, and they can be deleted.
