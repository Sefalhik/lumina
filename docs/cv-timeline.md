# CV timeline (`Experience`)

Delivered by LUMN-18. First entity of the CV page; the pattern below is meant to be replicated for
education, certifications and skills.

## Partial translation — decided at design time

| Field | Translated | Why |
|-------|-----------|-----|
| `employer`, `location` | no | proper nouns and geography |
| `started_at`, `ended_at` | no | dates |
| **`job_title`** | **no** | it is what a `sameAs` statement cross-references with the LinkedIn profile, which carries one hand-typed title. A localised one would match in `fr` alone — the mistake LUMN-15 had to undo on `SiteIdentity` |
| `description`, `achievements` | yes | prose |

`ExperienceService` reads this split from the model's `$translatable` rather than repeating it, so
changing the model alone changes the service's behaviour — and fails the two tests that guard the
decision.

Declaring `$translatable` is only half of it: `Experience::class` must also sit in
`config('i18n.cms_models')`, or `cms:translate` never walks it. That second half was missed when
LUMN-18 shipped and added on 2026-09-13 — the model was translatable and untranslated for a day,
with nothing reporting it.

## A null `ended_at` means "still in this position"

A business state, not missing data. It is named rather than implied: `isCurrent()`,
`scopeCurrent()`, a comment on the column, and tests asserting both. See the memory rule on
explicit NULL semantics — an implicit one is re-explained at every reading.

## Ordering lives in two places, deliberately

`Experience::scopeMostRecentFirst()` orders by `started_at` then `id`; `CvService::timeline()`
sorts by `started_at` alone. The second does not override the first — **PHP 8 sorts are stable**,
so positions starting the same month keep the order the query gave them and the `id` tie-breaker
survives.

Both are load-bearing: the service sort makes the page correct whatever the caller hands over, the
scope makes it deterministic. Swapping either for an unstable sort loses the tie-breaker silently.

## The period sentence has one author

`CvService::period()` writes "04/2024 — 09/2026", or "04/2024 — aujourd'hui" while the position is
held, and **both** the public page and the admin index read it — the latter through
`ExperienceService::adminRows()`. The index used to rebuild that ternary in Blade with its own
label, so the same business rule lived in a service and in a template. Two tests now read the same
string from both pages, so a format change on one side fails unless it is made on both.

The views receive shaped arrays, never models: handing a template a model is what invited the
ternary to be written there in the first place.

## Route URLs take no explicit `lang`

`SetLocale` sets `URL::defaults(['lang' => …])` for the whole request, so
`route('admin.experiences.index')` already carries the locale. Passing `['lang' => app()->getLocale()]`
is redundant, and it invites the argument to drift from the locale actually in effect. Seven call
sites in `app/` still repeat it (auth, 2FA, homepage, identity) — pre-existing, worth a sweep.

## Query cost

The CV page issues **one query for the positions**, whatever their number — the model has no
relation, so nothing can be lazily loaded. `ExperienceTest` pins this as an *invariance* rather
than a fixed count, so the guard survives LUMN-19 adding a second section.

The weight is elsewhere: **~94 KB of JSON per row** once `cms:translate` has run, since
`spatie/laravel-translatable` stores all twenty-four locales in one column and the page loads them
all to display one. Harmless at CV scale — worth settling before the blog engine reuses the pattern
on article bodies.

## Normalisation is the framework's job

Inputs arrive trimmed: `TrimStrings` sits in the global middleware stack, immediately before
`ConvertEmptyStringsToNull`. A `prepareForValidation()` that only trims is dead code — one was
written here and removed once measured. `SiteIdentityRequest` still carries a half-dead one: its
trimming is redundant, its query-string stripping is not.
