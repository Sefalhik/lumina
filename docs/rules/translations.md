---
paths:
  - "lang/**"
  - "resources/js/i18n/**"
  - "resources/js/utils/i18n.js"
  - "resources/prompts/translation/**"
  - "app/Services/Translation/**"
  - "app/Services/AnthropicTranslator.php"
  - "app/Console/Commands/*Translate.php"
  - "config/i18n.php"
---

# Rules — translations

- Write French first, and only French: `lang/fr/`, `resources/js/i18n/fr.json`, the French value of a
  translatable column. The other 23 languages are produced from it.
- Never edit another language by hand to add a key: run `php artisan i18n:translate`, which
  translates what is missing or whose French changed, with the repository as the reference. It shows
  the cost and asks once; outside a terminal it needs `--yes`.
- Commit `lang/.translation-sources.json` with the files it describes: it records the French each
  translation comes from. A key without its fingerprint is adopted as it is, never retranslated.
- Content stored in the database (CMS models) is translated by `php artisan cms:translate`; the
  homepage copy ships with the deployment, exported by `php artisan cms:export-seed`.
- Island keys are in English, scoped by component (`theme_switcher.*`, `boot.*`); the fallback
  locale is `fr`.
- Choose a model or a prompt on a measurement, in `config/i18n.php` and
  `resources/prompts/translation/` — never in `.env`.
- A translation that loses, duplicates or invents a placeholder is refused, and its keys stay owed:
  do not loosen that check to make a run pass.

References: [internationalisation](../i18n/README.md), [homepage content](../homepage-content.md).
