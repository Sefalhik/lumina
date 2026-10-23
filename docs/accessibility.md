# Accessibility (WCAG 2.1 AA)

All views are axe-scanned against WCAG 2.1 AA via `@axe-core/playwright` in `tests/e2e/accessibility.spec.js` (3 themes × N pages).

## Color contrast — safe opacity thresholds

Dark-themed UIs with near-black backgrounds require higher opacity than typical designs. Thresholds below apply to lighter base colors (e.g., `text-base-content`, `text-primary`).

| Text size | Utility opacity | Status |
|-----------|----------------|--------|
| 10px (`text-[10px]`) | `/40` | ❌ fails (~2.6:1) |
| 10px | `/60` | ✅ passes (~4.8:1) for light base colors |
| 12–14px (`text-xs`, `text-sm`) | `/50` | ❌ fails (~3.5–4.1:1) |
| 12–14px | `/70` | ✅ passes (~5:1) for light base colors |
| Any | `text-secondary/50` | ❌ fails — `text-secondary` is a dark mid-tone in these themes |
| Any | `text-secondary` (full opacity) | ❌ still fails in some themes (e.g., neon-noir: ~2.5:1) |

When in doubt, prefer higher opacity — the design intent of "muted" labels is preserved at `/60`–`/70`. Never assume `text-secondary` at any opacity will pass for small text against near-black backgrounds.

## Decorative elements — axe-core exemption

When an element is purely aesthetic (no semantic content, `select-none`, not meaningful for AT) and its color cannot reach 4.5:1 regardless of opacity, apply both:
- `aria-hidden="true"` — removes the element from the accessibility tree (correct for screen readers)
- `data-a11y-role="decorative"` — tells the `axeCheck()` helper to skip the element

**Important**: `aria-hidden` alone does **not** suppress axe-core's color-contrast check — axe-core scans visually rendered elements regardless of AT visibility. The `.exclude('[data-a11y-role="decorative"]')` call in `accessibility.spec.js` is what actually removes the element from the scan, aligned with the WCAG 1.4.3 exception for incidental/decorative text.

Example: the `> LIST_` terminal-style header in `SkillsEditor.vue` — pure aesthetic decoration, uses `text-secondary` which cannot reach 4.5:1 in any theme at any opacity.
