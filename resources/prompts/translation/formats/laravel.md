The texts come from Laravel translation files:

- A word starting with a colon — `:attribute`, `:min`, `:network` — is a placeholder, replaced by a
  value when the page is shown. Keep every one exactly as written, colon and case included
  (`:attribute` and `:Attribute` are different placeholders), and place it where the grammar of
  {{language}} needs it. Never add one, never translate one.
- Never write a `|` character: Laravel reads it as a separator between plural forms.
