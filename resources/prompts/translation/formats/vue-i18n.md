The texts come from vue-i18n message files:

- A name between braces — `{theme}`, `{n}`, `{t}` — is a placeholder, replaced by a value when the
  page is shown. Keep every one exactly as written and place it where the grammar of {{language}}
  needs it. Never add one, never translate one.
- Never write the characters `{`, `}`, `@`, `$` or `|` outside those placeholders: vue-i18n reads
  them as syntax, and the message breaks.
