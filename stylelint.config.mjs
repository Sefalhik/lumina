// Stylelint — the SCSS of resources/css. A module rather than JSON so that each rule turned off carries
// its reason next to it, as renovate.json5 does (LUMN-152): in the JSON this file replaces, two quality
// rules and fourteen formatting rules had been off — the first since the first commit, the others
// through stylelint-config-prettier-scss, abandoned in 2023 — and nothing said why.
//
// A rule is off here only when it was measured to contradict Prettier, which formats the SCSS on every
// commit (lint-staged: stylelint --fix, then prettier --write). Of the fourteen formatting rules,
// measured on 2026-11-21: one contradicts Prettier; three check what Prettier leaves as written
// (a blank line before an at-rule, a space before the parentheses of @else if, the spaces around an
// operator); ten restate what Prettier writes anyway, and guard what reaches the repository without the
// hook. scripts/__tests__/stylelint-config.test.js holds each of these claims.

export default {
    extends: ['stylelint-config-standard-scss'],
    plugins: ['stylelint-order'],
    rules: {
        'order/properties-alphabetical-order': true,

        // Sass modules and Tailwind 4 bring at-rules the SCSS rule does not know.
        'scss/at-rule-no-unknown': [
            true,
            { ignoreAtRules: ['use', 'forward', 'tailwind', 'plugin', 'apply', 'layer', 'source'] },
        ],

        // A less specific selector after a more specific one, on the same element: a cascade bug. The
        // standard configuration leaves this rule off; it is on here.
        'no-descending-specificity': true,

        // Prettier wraps a long expression after its operator — `$a + $b +` then the rest on the next
        // line — and this rule refuses exactly that: the two cannot both be satisfied.
        'scss/operator-no-newline-after': null,

        // Each theme of _themes.scss groups its palette (--color-*) and its typography (--font-*), a
        // blank line between them. This rule would remove that line, and add one after `color-scheme`.
        'custom-property-empty-line-before': null,
    },
};
