---
paths:
  - "resources/js/**"
  - "resources/views/**"
  - "resources/css/**"
---

# Rules — front end

## Structure

- Render public pages in Blade, for SEO. Mount each interactive widget as an **island**: its own
  `createApp()`, injected into its own DOM node. There is no global Vue application.
- Write components with `<script setup>` only, props through `defineProps`. Use `ref()` and
  `computed()` for values that are really reactive, never for a constant.
- Keep domain logic out of components: it lives in `resources/js/utils/`, as pure functions tested
  with Vitest. A component wires Vue primitives around them.
- Reference a route by its name, through Ziggy's `route()` — never a hard-coded URL.
- Read and write `localStorage` and `sessionStorage` through `readStored` and `storeQuietly`: a
  private window, blocked site data or a full quota makes a bare access throw.

## Text

- French is the source. Blade reads `__()` from `lang/`; an island reads `useI18n()` and its JSON
  files. Never write a visible string in another language first — see the
  [translation rules](translations.md).

## Accessibility — WCAG 2.1 AA

- Every view is scanned by axe-core in all three themes: a new view or state must pass it.
- On near-black backgrounds, small muted text takes `/60` to `/70` opacity — never `text-secondary`.
  [The thresholds](../accessibility.md#color-contrast--safe-opacity-thresholds).
- Mark a purely decorative element with **both** `aria-hidden="true"` and
  `data-a11y-role="decorative"`: the first alone does not stop axe.
- Ship every looping effect with its stopped state, in `when-motion-stopped`: every visitor can stop
  the animations (WCAG 2.2.2) — [Motion](../accessibility.md#motion--every-visitor-can-stop-it).

References: [accessibility](../accessibility.md), [architecture](../architecture.md),
[homepage content](../homepage-content.md), [site identity](../site-identity.md).
