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

## A theme's palette — tested where axe cannot see

The axe scan judges what the scanned pages render, and nothing else. A colour no scanned page shows
— an error message appears only after a refused form, a warning badge nowhere yet — can drift below
AA with every test green. Sprawl's palette is therefore checked at the level where it is decided
(LUMN-103): `scripts/__tests__/palette-contrast.test.js` reads the OKLCH values of
`resources/css/scss/_themes.scss`, converts them with `scripts/palette-contrast.js`, and checks
every pair the theme promises — the ticket's table to ±0.05, and every text colour on every
background at 4.5:1 or more, `/60` and `/70` opacities included.

**Change a token, run that test.** A value that is not a plain `oklch(L% C Hdeg)` is refused rather
than skipped. Steampunk and Neon Noir are not covered yet: some of their pairs already fail (see the
table above), and they get the same test with their own theme tickets.

## Motion — the dead channel's snow

Sprawl's sky is an animated grain (LUMN-103): a canvas behind the page, `DeadChannel.vue`, driven by
the pure functions of `resources/js/utils/dead-channel.js`. Four guarantees, each with its test:

| Guarantee | Why | Proven by |
|---|---|---|
| No pixel lighter than `base-300` | the palette's promises are checked on `base-300`, so they hold on every grain | Vitest, and on the canvas itself in `tests/e2e/dead-channel.spec.js` |
| The mean luminance of every frame is the same | grain, never a flash of the whole screen (WCAG 2.3.1): a frame is a fixed pool of values, only shuffled | Vitest |
| Nothing moves under `prefers-reduced-motion` | one frame is drawn, then the canvas stays still | E2E, with the preference emulated |
| Nothing runs that nobody sees | the timer stops in a hidden tab and in the other themes | E2E, on the frame counter `data-frames` |
| A colour it cannot read stops it | a canvas ignores a colour it cannot parse — an empty token, an unknown syntax — and keeps painting in black, silently; `colourChannels()` detects the refusal, and the snow stops and says so once | Vitest, and E2E with a broken token |

**axe still judges the text above it.** A background drawn behind text can turn axe's contrast check
from a verdict into "incomplete" — which removes the check instead of failing it. Measured on
2026-11-12: the canvas, a sibling behind the content, leaves every verdict as it was. axe judges the
text on `base-100`; the grain's ceiling covers the rest.

**A background image hides an element from the contrast check.** DaisyUI layers an invisible grain
image under every button (`background-image: none, var(--fx-noise)`, even with `--noise: 0`), and
axe answered "incomplete" for the hero's primary button: its contrast had never been judged. Sprawl
sets `background-image: none` on its buttons — one `none`: axe reads `none, none` as an image still.
`tests/e2e/accessibility.spec.js` now requires **no "incomplete" contrast at all** on Sprawl's
homepage, and every text of the hero among the judged ones.

**The text sits on screens, not on the snow.** On the homepage, the hero, the about section and the
skills are `.console-panel`s: in Sprawl, opaque `base-100` with a thin phosphor border, the grain
showing only around them (LUMN-103, option A). Not `base-200`: the subtitle's pulsing glow
(`neon-pulse-slow`, a `text-shadow`) lightens what axe takes for the text's background, and on
`base-200` it fell to 4.29:1 at the peak of the pulse — under load only, since the result depends
on the moment of the pulse. **A glowing text needs its contrast checked on its darkest likely
background**, and a background change near one deserves several full runs, not one.

## Motion — the intrusion on hover and focus

Sprawl's second effect (LUMN-103): a link or a button that the pointer reaches, or the keyboard
focuses, shudders once — the red and cyan channels of `.glitch`, split for 200 ms, the text shifted
by a few pixels. CSS only, `sprawl-intrusion` in `resources/css/scss/_effects.scss`: a CSS animation
starts when its selector begins to match, so it plays once per approach, and never loops.

| Guarantee | Why | Proven by |
|---|---|---|
| Once per hover or focus, never in a loop | a burst on approach is atmosphere; a repeating one pulls the eye away from what is read | E2E, counting the browser's `animationstart` and `animationiteration` events |
| The focus ring is drawn through the burst | the effect must never hide where the keyboard is (LUMN-27) | E2E: the burst is paused, sampled every 25 ms, and the ring compared with the one after it |
| Under `prefers-reduced-motion`, a change of colour | the two channels, still, for as long as the hover or focus lasts | E2E, with the preference emulated |
| That change of colour keeps the contrast | it lasts as long as the state, so it is text like any other; every other scan sees the page at rest | axe on a hovered link, a hovered button and a focused footer link, a verdict required |

**An effect on a focused element touches nothing that draws the ring**: no `outline`, no `clip-path`,
no `opacity`. A `clip-path` clips the outline with the element, and is the easiest way to make the
ring vanish for a frame without any test noticing. Inline links take no `transform`: on them, only
the colours split.

**The footer's `.glitch-hover` is Néon's only**: it repeats for as long as the pointer stays, which
the intrusion replaces in Sprawl. Its pause had never worked: an `animation-play-state` written
before the `animation` shorthand is reset by it, and the labels glitched all the time. **Write
`paused` inside the shorthand.**

**Not yet honoured by the rest of the site**: under a reduced-motion preference, Sprawl's name still
glitches (`.glitch`, `.glitch-jitter`), its subtitle pulses and its screen flickers (`crt-flicker`).
These came before LUMN-103 and belong to LUMN-11, which makes the whole site honour the preference.

## Decorative elements — axe-core exemption

When an element is purely aesthetic (no semantic content, `select-none`, not meaningful for AT) and its color cannot reach 4.5:1 regardless of opacity, apply both:
- `aria-hidden="true"` — removes the element from the accessibility tree (correct for screen readers)
- `data-a11y-role="decorative"` — tells the `axeCheck()` helper to skip the element

**Important**: `aria-hidden` alone does **not** suppress axe-core's color-contrast check — axe-core scans visually rendered elements regardless of AT visibility. The `.exclude('[data-a11y-role="decorative"]')` call in `accessibility.spec.js` is what actually removes the element from the scan, aligned with the WCAG 1.4.3 exception for incidental/decorative text.

Example: the `> LIST_` terminal-style header in `SkillsEditor.vue` — pure aesthetic decoration, uses `text-secondary` which cannot reach 4.5:1 in any theme at any opacity.
