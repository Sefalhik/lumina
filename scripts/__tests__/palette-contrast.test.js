// @vitest-environment node
import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { contrast, luminance, oklchToSrgb, over, themeColours, toHex } from '../palette-contrast.js';

const THEMES = readFileSync(fileURLToPath(new URL('../../resources/css/scss/_themes.scss', import.meta.url)), 'utf8');

const WHITE = [1, 1, 1];
const BLACK = [0, 0, 0];

describe('the colour arithmetic', () => {
    it('gives the WCAG extremes: 21:1 for black on white, 1:1 for a colour on itself, in either order', () => {
        expect(contrast(WHITE, BLACK)).toBeCloseTo(21, 10);
        expect(contrast(BLACK, WHITE)).toBeCloseTo(21, 10);
        expect(contrast([0.4, 0.5, 0.6], [0.4, 0.5, 0.6])).toBe(1);
    });

    it('weighs the channels as WCAG does, and decodes both sides of the sRGB curve', () => {
        expect(luminance(WHITE)).toBeCloseTo(1, 10);
        expect(luminance([1, 0, 0])).toBeCloseTo(0.2126, 10);
        expect(luminance([0, 1, 0])).toBeCloseTo(0.7152, 10);
        expect(luminance([0, 0, 1])).toBeCloseTo(0.0722, 10);
        // Below the knee of the curve the channel is linear (0.04 / 12.92), above it a power.
        expect(luminance([0.04, 0.04, 0.04])).toBeCloseTo(0.04 / 12.92, 10);
        expect(luminance([0.5, 0.5, 0.5])).toBeCloseTo(0.214, 3);
    });

    it('converts the OKLCH extremes and a known colour', () => {
        expect(toHex(oklchToSrgb({ l: 1, c: 0, h: 0 }))).toBe('#ffffff');
        expect(toHex(oklchToSrgb({ l: 0, c: 0, h: 0 }))).toBe('#000000');
        // A dark grey, whose linear channels fall below the knee of the sRGB curve: 0.1³ × 12.92 × 255
        // = 3.29, worked out by hand.
        expect(toHex(oklchToSrgb({ l: 0.1, c: 0, h: 0 }))).toBe('#030303');
        // Out of the sRGB gamut: clipped, never wrapped round or negative.
        expect(toHex(oklchToSrgb({ l: 0.9, c: 0.4, h: 142 }))).toMatch(/^#[0-9a-f]{6}$/);
    });

    it('blends a colour over its background at an opacity, and refuses an opacity that is not one', () => {
        expect(over(WHITE, BLACK, 1)).toEqual(WHITE);
        expect(over(WHITE, BLACK, 0)).toEqual(BLACK);
        expect(over(WHITE, BLACK, 0.6)).toEqual([0.6, 0.6, 0.6]);

        for (const opacity of [-0.1, 1.1, Number.NaN, undefined, '0.6']) {
            expect(() => over(WHITE, BLACK, opacity)).toThrow(RangeError);
        }
    });
});

describe('themeColours()', () => {
    it("reads a theme's tokens, comments after them included", () => {
        const scss =
            "[data-theme='x'] {\n    --color-primary: oklch(88% 0.24 142deg); // #67fd5a\n    --font-body: 'A';\n}\n";

        expect(themeColours(scss, 'x')).toEqual({ primary: { l: 0.88, c: 0.24, h: 142 } });
    });

    it('stops at the end of the theme it reads', () => {
        const scss =
            "[data-theme='x'] {\n    --color-a: oklch(10% 0 0deg);\n}\n[data-theme='y'] {\n    --color-b: oklch(20% 0 0deg);\n}\n";

        expect(Object.keys(themeColours(scss, 'x'))).toEqual(['a']);
    });

    it('refuses a theme that does not exist', () => {
        expect(() => themeColours(THEMES, 'tron')).toThrow(/No \[data-theme='tron'\] block/);
    });

    it.each([['#67fd5a'], ['oklch(88% 0.24 142)'], ['var(--color-base-100)'], ['oklch(0.88 0.24 142deg)']])(
        'refuses %s rather than passing it as an absent colour',
        (value) => {
            expect(() => themeColours(`[data-theme='x'] {\n    --color-a: ${value};\n}\n`, 'x')).toThrow(
                /is not an oklch/,
            );
        },
    );
});

/**
 * What LUMN-103 promises for Sprawl, computed on 2026-11-07 and kept in the ticket's table. The hex
 * values prove the conversion; the ratios are the promise. Any change to a token must recompute.
 */
describe('Sprawl keeps the promises of its palette', () => {
    const tokens = themeColours(THEMES, 'sprawl');
    const rgb = Object.fromEntries(Object.entries(tokens).map(([token, value]) => [token, oklchToSrgb(value)]));
    const AA = 4.5;

    it.each([
        ['base-100', '#1e2226'],
        ['base-200', '#282c30'],
        ['base-300', '#33393e'],
        ['base-content', '#dfe5ec'],
        ['primary', '#67fd5a'],
        ['primary-content', '#030c03'],
        ['secondary', '#ffb147'],
        ['accent', '#ff85c7'],
        ['neutral', '#2c3136'],
        ['neutral-content', '#c7cfd7'],
        ['error', '#ff8079'],
    ])('--color-%s is %s', (token, hex) => {
        expect(toHex(rgb[token])).toBe(hex);
    });

    it.each([
        ['base-content', 'base-100', 1, 12.6],
        ['base-content', 'base-300', 1, 9.3],
        ['base-content', 'base-100', 0.6, 5.4],
        ['base-content', 'base-100', 0.7, 6.9],
        ['primary', 'base-100', 1, 12.0],
        ['primary', 'base-300', 1, 8.8],
        ['primary-content', 'primary', 1, 14.9],
        ['secondary', 'base-100', 1, 8.9],
        ['secondary', 'base-300', 1, 6.5],
        ['accent', 'base-100', 1, 7.2],
        ['accent', 'base-300', 1, 5.3],
        ['neutral-content', 'neutral', 1, 8.3],
        ['error', 'base-100', 1, 6.6],
        ['error', 'base-300', 1, 4.8],
    ])('%s on %s at opacity %s: %s:1, as the ticket says', (text, background, opacity, ratio) => {
        const actual = contrast(over(rgb[text], rgb[background], opacity), rgb[background]);

        expect(Math.abs(actual - ratio)).toBeLessThan(0.05);
        expect(actual).toBeGreaterThanOrEqual(AA);
    });

    // Every colour that may carry text, on every background a text sits on — including the ones no
    // scanned page renders yet, which axe will never judge.
    const TEXT = [
        'base-content',
        'primary',
        'secondary',
        'accent',
        'info',
        'success',
        'warning',
        'error',
        'neutral-content',
    ];
    const BACKGROUNDS = ['base-100', 'base-200', 'base-300'];

    it.each(TEXT.flatMap((text) => BACKGROUNDS.map((background) => [text, background])))(
        '%s reaches AA on %s',
        (text, background) => {
            expect(contrast(rgb[text], rgb[background])).toBeGreaterThanOrEqual(AA);
        },
    );

    it.each([['primary'], ['secondary'], ['accent'], ['neutral']])('text on a %s surface reaches AA', (surface) => {
        expect(contrast(rgb[`${surface}-content`], rgb[surface])).toBeGreaterThanOrEqual(AA);
    });
});
