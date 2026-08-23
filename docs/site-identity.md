# Site identity — conventions

Who runs this site, and where to find them. Powers the footer today; will power the schema.org
`sameAs` declaration next.

## Why a dedicated model

`SiteIdentity` is a **single-row** model, deliberately separate from `HomepageContent`.

These values appear on **every page** through the footer, not just the home page. Storing them
under "homepage content" would be a naming lie that costs nothing today and confuses everyone the
first time they are needed elsewhere — which is exactly what the structured-data work will do.

## Mixed translated / untranslated model

The trap in this area, and the thing to understand before touching anything:

```php
public array $translatable = ['job_title'];

protected $fillable = [
    'full_name', 'job_title', 'contact_email',
    'github_url', 'linkedin_url', 'mastodon_url',
];
```

**`spatie/laravel-translatable` works column by column, not model by model.** Only the columns
listed in `$translatable` are stored as multilingual JSON. Every other column on the same model
stays an ordinary column.

So a model can perfectly well hold translated *and* untranslated fields:

| Field | Translated? | Why |
|-------|-------------|-----|
| `job_title` | yes | "Tech Lead" may read better localised in some markets |
| `full_name` | no | A proper noun does not get translated |
| `contact_email` | no | An address is an address |
| `github_url`, `linkedin_url`, `mastodon_url` | no | So is a URL |

`HomepageContent` happens to have identical `$translatable` and `$fillable` arrays — that is a
coincidence of its content, **not a constraint of the trait**. Do not copy it as a rule.

The model is registered in `config('i18n.cms_models')`, so `php artisan cms:translate` picks up
`job_title` automatically and ignores everything else.

## URL validation — three axes, not one

`app/Rules/ProfileUrl.php`, used through named factories:

```php
'github_url'   => ['nullable', 'string', 'max:255', 'url:https', ProfileUrl::github()],
'linkedin_url' => ['nullable', 'string', 'max:255', 'url:https', ProfileUrl::linkedin()],
'mastodon_url' => ['nullable', 'string', 'max:255', 'url:https', ProfileUrl::mastodon()],
```

1. **`url:https`** — delegated to Laravel, which restricts the protocol natively. A `sameAs`
   entry over plain HTTP is a poor signal.
2. **The host** — subdomains accepted (`www.linkedin.com`). `null` for Mastodon, which is
   federated: `mastodon.social`, `piaille.fr` and self-hosted instances are all legitimate.
3. **The shape of the path** — `/user`, `/in/user`, `/@user`.

**The third axis is the one that matters, and the one that is easy to forget.** A host check alone
accepts `https://github.com/`, `https://github.com/orgs/laravel/projects/1` and
`https://www.linkedin.com/feed/`. Any of them would end up in a `sameAs` statement declaring that
this person *is* GitHub's home page — worse than declaring nothing, because it actively misinforms.

### Input is normalised before validation

`prepareForValidation()` in `SiteIdentityRequest` trims whitespace and strips query strings and
fragments. Copying a profile URL out of LinkedIn's interface yields `?trk=public_profile&…`:
harmless in a browser, noise in a `sameAs`, and a needless source of churn. Fixing it silently is
kinder than rejecting someone over a suffix LinkedIn added on its own.

## rel="me" — reciprocal verification

Footer links carry `rel="me noopener noreferrer"`.

Mastodon verifies identity by **mutual link**: if the profile points at the site and the site
answers with `rel="me"`, Mastodon shows a green check on the profile. Same reciprocity logic as
`hreflang` — see `docs/seo-conventions.md`. Costs nothing, and turns a one-sided claim into a
verifiable fact.

The same idea applies to GitHub: filling its `blog` field with the site URL is the return link.
That part is manual, on the platform.

## The contact address is exposed in plain text

A deliberate decision, not an oversight.

JavaScript obfuscation dates from an era when harvesters read raw HTML; they now drive headless
browsers and see the reassembled address anyway. It costs accessibility and complexity for a
protection that only stops the crudest bots — on a site whose whole point is being readable
without JavaScript.

A contact form would degrade the service: people writing from their own mail client keep a copy in
their sent folder, attach documents, and manage follow-ups.

What makes the risk acceptable is that `contact@cardascia-it.org` is a **dedicated address on the
site's own domain**. If it ever drowns in spam, it is replaced from the admin UI in three clicks,
without ever exposing a personal address. Spam is handled at reception, not by hiding.

## Everything degrades to nothing

Every field is nullable, and the table is **empty right after migrating** — that is the initial
state of a fresh install, not an exotic edge case.

`SiteIdentityService` filters out blank values; the footer renders nothing for them. No orphan
icons, no dead links. `tests/Feature/Admin/SiteIdentityTest.php` locks this in with an explicit
"no identity at all" test. Do not weaken it.

## Adding a new social network

Four steps:

1. **Migration** — add a nullable column, e.g. `bluesky_url`
2. **Model** — add it to `$fillable` (and to `$translatable` only if it is genuinely translatable,
   which a URL never is)
3. **Rule** — add a factory to `App\Rules\ProfileUrl`: expected host (or `null` if federated), the
   profile path pattern, and a human-readable example for the error message
4. **Request** — add the field to `rules()`, to `PROFILE_FIELDS` so it gets normalised, and to
   `messages()`

The footer needs no change: `SiteIdentityService::NETWORKS` drives display order and labels, and
the Blade template just loops. Add the entry there and it appears.

Then add it to the admin view, and extend the E2E seeder (`E2eSiteIdentitySeeder`) — otherwise the
axe-core scan will never see the new link. See `CLAUDE.md`, Playwright section.

## Tests

| Suite | File |
|-------|------|
| Unit — service | `tests/Unit/Services/SiteIdentityServiceTest.php` |
| Unit — rule | `tests/Unit/Rules/ProfileUrlTest.php` |
| Feature | `tests/Feature/Admin/SiteIdentityTest.php` |
| E2E | `tests/e2e/site-identity.spec.js` |
| E2E — a11y | `tests/e2e/accessibility.spec.js` (footer + admin form, 3 themes) |

The E2E file holds **both** the admin form and the footer, in **serial mode**, deliberately: the
form specs mutate the single identity row, which feeds the footer of every page. Split across two
files, Playwright would run them in parallel over each other's half-written state. They restore
the row after *every* test via `/e2e/site-identity`, not just at the end.

Two of these guard things that are easy to break without noticing:

- `admin_supplied_name_is_escaped_in_the_footer` — `full_name` is admin-editable and rendered on
  every page. Switching the template to `{!! !!}` would open a sitewide injection; this fails first.
- `cms_translate_translates_the_job_title` — without it, removing the model from
  `config('i18n.cms_models')` would go completely unnoticed.
- `a server-side rejection is actually shown to the user` (E2E) — the inputs are `type="url"` and
  `type="email"`, so the browser blocks malformed values before the request is even sent. Only a
  value the browser accepts but the server rejects — a LinkedIn URL in the GitHub field — proves
  the server's own messages are reachable. A Feature test asserting on the session could never
  catch a template that forgot to render them.
