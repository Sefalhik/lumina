/**
 * Contrast of a theme's colours, computed from the OKLCH values of _themes.scss (LUMN-103).
 *
 * The axe scan only judges what a scanned page renders: a colour no page shows yet — an error
 * message, a warning badge — can drift below WCAG AA without a single test noticing. These
 * functions let a unit test check every pair a theme promises, at the level where it is decided.
 *
 * OKLCH → OKLab → linear sRGB (Björn Ottosson's matrices, as CSS Color 4), clipped to the sRGB
 * gamut; WCAG 2.1 relative luminance and contrast ratio. A colour with an opacity utility (`/60`)
 * is blended over its background in gamma-encoded sRGB, as browsers do.
 */

/** @typedef {{ l: number, c: number, h: number }} Oklch  l in 0–1, c chroma, h in degrees */
/** @typedef {[number, number, number]} Rgb  gamma-encoded sRGB, each channel in 0–1 */

const TOKEN = /--color-([a-z0-9-]+)\s*:\s*([^;]+);/g;
const OKLCH = /^oklch\(\s*([\d.]+)%\s+([\d.]+)\s+([\d.]+)deg\s*\)$/;

/**
 * The `--color-*` tokens of one theme's block, as OKLCH.
 *
 * A theme that is missing, or a token whose value is not a plain `oklch(L% C Hdeg)`, is refused:
 * a value this reader cannot parse must fail the test, never pass as an absent colour.
 *
 * @param {string} scss the content of _themes.scss
 * @param {string} theme
 * @returns {Record<string, Oklch>}
 */
export function themeColours(scss, theme) {
    const start = scss.indexOf(`[data-theme='${theme}'] {`);
    if (start === -1) {
        throw new Error(`No [data-theme='${theme}'] block.`);
    }
    const block = scss.slice(start, scss.indexOf('\n}', start));

    const colours = {};
    for (const [, token, raw] of block.matchAll(TOKEN)) {
        const value = raw.trim();
        const match = value.match(OKLCH);
        if (match === null) {
            throw new Error(`--color-${token} of ${theme} is not an oklch(L% C Hdeg) value: ${value}`);
        }
        colours[token] = { l: Number(match[1]) / 100, c: Number(match[2]), h: Number(match[3]) };
    }
    return colours;
}

/** sRGB transfer function: linear light to gamma-encoded. */
const encode = (linear) => (linear <= 0.0031308 ? 12.92 * linear : 1.055 * linear ** (1 / 2.4) - 0.055);

/** sRGB transfer function: gamma-encoded to linear light. */
const decode = (channel) => (channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4);

const clip = (value) => Math.min(1, Math.max(0, value));

/**
 * @param {Oklch} colour
 * @returns {Rgb}
 */
export function oklchToSrgb({ l, c, h }) {
    const radians = (h * Math.PI) / 180;
    const a = c * Math.cos(radians);
    const b = c * Math.sin(radians);

    const lms = [
        (l + 0.3963377774 * a + 0.2158037573 * b) ** 3,
        (l - 0.1055613458 * a - 0.0638541728 * b) ** 3,
        (l - 0.0894841775 * a - 1.291485548 * b) ** 3,
    ];

    const linear = [
        4.0767416621 * lms[0] - 3.3077115913 * lms[1] + 0.2309699292 * lms[2],
        -1.2684380046 * lms[0] + 2.6097574011 * lms[1] - 0.3413193965 * lms[2],
        -0.0041960863 * lms[0] - 0.7034186147 * lms[1] + 1.707614701 * lms[2],
    ];

    return linear.map((channel) => clip(encode(clip(channel))));
}

/** @param {Rgb} rgb  `#rrggbb` */
export function toHex(rgb) {
    return `#${rgb
        .map((channel) =>
            Math.round(channel * 255)
                .toString(16)
                .padStart(2, '0'),
        )
        .join('')}`;
}

/**
 * `colour` drawn at `opacity` over `background`, blended in gamma-encoded sRGB.
 *
 * @param {Rgb} colour
 * @param {Rgb} background
 * @param {number} opacity 0–1
 * @returns {Rgb}
 */
export function over(colour, background, opacity) {
    // A number only: '0.6' would pass the comparisons below, coerced without a word.
    if (typeof opacity !== 'number' || !(opacity >= 0 && opacity <= 1)) {
        throw new RangeError(`An opacity is between 0 and 1, not ${opacity}.`);
    }
    return colour.map((channel, index) => channel * opacity + background[index] * (1 - opacity));
}

/** WCAG 2.1 relative luminance. @param {Rgb} rgb */
export function luminance(rgb) {
    const [r, g, b] = rgb.map(decode);
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** WCAG 2.1 contrast ratio, from 1 to 21, whatever the order. @param {Rgb} first @param {Rgb} second */
export function contrast(first, second) {
    const [lighter, darker] = [luminance(first), luminance(second)].sort((x, y) => y - x);
    return (lighter + 0.05) / (darker + 0.05);
}
