import { describe, expect, it, vi } from 'vitest';
import {
    canvasSize,
    colourChannels,
    DOWNSCALE,
    FRAMES_PER_SECOND,
    grainPool,
    paintFrame,
    shuffle,
    snowMode,
} from '../dead-channel.js';

/** A deterministic source in [0, 1): the same snow on every run, so a failure can be replayed. */
function seeded(seed = 42) {
    let state = seed;
    return () => {
        state = (state * 1664525 + 1013904223) % 4294967296;
        return state / 4294967296;
    };
}

/** Sprawl's base-100 and base-300, as the browser draws them (#1e2226, #33393e). */
const FLOOR = [0x1e, 0x22, 0x26];
const CEILING = [0x33, 0x39, 0x3e];

/** WCAG relative luminance of 0–255 channels. */
function luminance([red, green, blue]) {
    const linear = (channel) => {
        const c = channel / 255;
        return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    };
    return 0.2126 * linear(red) + 0.7152 * linear(green) + 0.0722 * linear(blue);
}

function frame(pool) {
    return paintFrame(new Uint8ClampedArray(pool.length * 4), pool, FLOOR, CEILING);
}

function meanLuminance(pixels) {
    let sum = 0;
    for (let offset = 0; offset < pixels.length; offset += 4) {
        sum += luminance([pixels[offset], pixels[offset + 1], pixels[offset + 2]]);
    }
    return sum / (pixels.length / 4);
}

describe('the rhythm and grain', () => {
    it('runs at twelve frames a second, at a third of the screen resolution', () => {
        expect(FRAMES_PER_SECOND).toBe(12);
        expect(DOWNSCALE).toBe(3);
    });
});

describe('canvasSize()', () => {
    it('divides the viewport by the downscale, rounding up so the edge is always covered', () => {
        expect(canvasSize(1440, 900)).toEqual({ width: 480, height: 300 });
        expect(canvasSize(1441, 901)).toEqual({ width: 481, height: 301 });
        expect(canvasSize(1, 1)).toEqual({ width: 1, height: 1 });
        expect(canvasSize(100, 50, 1)).toEqual({ width: 100, height: 50 });
    });

    it.each([
        [0, 900, 3],
        [1440, -1, 3],
        [1440, 900, 0],
        [Number.NaN, 900, 3],
        [Number.POSITIVE_INFINITY, 900, 3],
        ['1440', 900, 3],
        [undefined, 900, 3],
    ])('refuses width %s, height %s, downscale %s', (width, height, downscale) => {
        expect(() => canvasSize(width, height, downscale)).toThrow(RangeError);
    });
});

describe('grainPool()', () => {
    it('draws one value per grain, every one between floor (0) and ceiling (1)', () => {
        const pool = grainPool(10_000, seeded());

        expect(pool).toHaveLength(10_000);
        expect(Math.min(...pool)).toBeGreaterThanOrEqual(0);
        expect(Math.max(...pool)).toBeLessThanOrEqual(1);
    });

    it('keeps most grains near the background: a few specks of snow, not a grey haze', () => {
        const pool = grainPool(10_000, seeded());
        const mean = pool.reduce((sum, value) => sum + value, 0) / pool.length;

        // The cube of a uniform value averages 1/4: the snow stays a quarter of the way up.
        expect(mean).toBeGreaterThan(0.2);
        expect(mean).toBeLessThan(0.3);
    });

    it('clamps a source that strays outside [0, 1): no grain past the ceiling, none below the floor', () => {
        const strays = [1.7, -0.4, 1, 0.5];
        const pool = grainPool(4, () => strays.shift());

        expect([...pool]).toEqual([1, 0, 1, 0.125]);
    });

    it.each([[0], [-1], [2.5], [Number.NaN], ['10']])('refuses a size of %s', (size) => {
        expect(() => grainPool(size, Math.random)).toThrow(RangeError);
    });
});

describe('a random source that is not one', () => {
    // NaN slips through Math.min and Math.max: unchecked, it painted a black grain from grainPool()
    // and corrupted two values of three in shuffle() — reproduced before the fix.
    it.each([[Number.NaN], [Number.POSITIVE_INFINITY], [undefined], ['0.5'], [null]])(
        'grainPool() refuses a source that gives %s',
        (value) => {
            expect(() => grainPool(3, () => value)).toThrow(/not a finite number/);
        },
    );

    it.each([[Number.NaN], [Number.NEGATIVE_INFINITY], [undefined]])(
        'shuffle() refuses a source that gives %s',
        (value) => {
            expect(() => shuffle(Float32Array.from([0.1, 0.2, 0.3]), () => value)).toThrow(/not a finite number/);
        },
    );

    it('shuffle() keeps a negative source inside the pool', () => {
        const pool = Float32Array.from([0.1, 0.2, 0.3]);

        shuffle(pool, () => -0.5);

        expect([...pool].sort()).toEqual([...Float32Array.from([0.1, 0.2, 0.3])].sort());
    });
});

describe('shuffle()', () => {
    it('only moves the values: the same pool, in another order', () => {
        const pool = grainPool(1_000, seeded(1));
        const before = [...pool].sort();
        const order = [...pool];

        shuffle(pool, seeded(2));

        expect([...pool].sort()).toEqual(before);
        expect([...pool]).not.toEqual(order);
    });

    it('stays inside the pool when the source returns its upper bound', () => {
        const pool = Float32Array.from([0.1, 0.2, 0.3]);

        shuffle(pool, () => 0.9999999999);

        expect([...pool].sort()).toEqual([...Float32Array.from([0.1, 0.2, 0.3])].sort());
    });
});

describe('paintFrame()', () => {
    it('never paints a grain lighter than the ceiling, nor darker than the floor', () => {
        const pixels = frame(grainPool(20_000, seeded()));
        const ceiling = luminance(CEILING);
        const floor = luminance(FLOOR);

        for (let offset = 0; offset < pixels.length; offset += 4) {
            const grain = luminance([pixels[offset], pixels[offset + 1], pixels[offset + 2]]);
            expect(grain).toBeLessThanOrEqual(ceiling);
            expect(grain).toBeGreaterThanOrEqual(floor);
        }
    });

    it('paints the floor for 0, the ceiling for 1, fully opaque', () => {
        const pixels = frame(Float32Array.from([0, 1]));

        expect([...pixels]).toEqual([...FLOOR, 255, ...CEILING, 255]);
    });

    it('keeps the mean luminance of every frame the same: grain, never a flicker of the whole', () => {
        const random = seeded(7);
        const pool = grainPool(5_000, random);
        const means = [];

        for (let index = 0; index < 12; index++) {
            means.push(meanLuminance(frame(shuffle(pool, random))));
        }

        expect(Math.max(...means) - Math.min(...means)).toBeLessThan(1e-9);
    });

    it.each([[1.5], [-0.1], [Number.NaN]])(
        'refuses a grain of %s: the ceiling is guaranteed where pixels are painted',
        (value) => {
            // Before the fix, a grain of 1.5 painted [61, 68, 74] — lighter than base-300 [51, 57, 62].
            expect(() => paintFrame(new Uint8ClampedArray(8), Float32Array.from([0.5, value]), FLOOR, CEILING)).toThrow(
                /outside \[0, 1\]/,
            );
        },
    );

    it.each([
        [[30, 34]],
        [[30, 34, 38, 0]],
        [[30, Number.NaN, 38]],
        [[30, 34, 256]],
        [[-1, 34, 38]],
        [['30', 34, 38]],
        [null],
        ['#1e2226'],
    ])('refuses a floor of %j', (floor) => {
        expect(() => paintFrame(new Uint8ClampedArray(4), Float32Array.from([0.5]), floor, CEILING)).toThrow(
            /floor must be three channels/,
        );
    });

    it('accepts the full channel range, 0 and 255 included', () => {
        expect([
            ...paintFrame(new Uint8ClampedArray(8), Float32Array.from([0, 1]), [0, 0, 0], [255, 255, 255]),
        ]).toEqual([0, 0, 0, 255, 255, 255, 255, 255]);
    });

    it('refuses a ceiling that is not a colour', () => {
        expect(() => paintFrame(new Uint8ClampedArray(4), Float32Array.from([0.5]), FLOOR, [51, 57])).toThrow(
            /ceiling must be three channels/,
        );
    });

    it('refuses pixel data that does not fit the pool', () => {
        expect(() => paintFrame(new Uint8ClampedArray(7), new Float32Array(2), FLOOR, CEILING)).toThrow(RangeError);
    });

    it('refuses a ceiling darker than its floor on any channel', () => {
        expect(() => paintFrame(new Uint8ClampedArray(4), new Float32Array(1), CEILING, FLOOR)).toThrow(
            /darker than the floor/,
        );
        expect(() => paintFrame(new Uint8ClampedArray(4), new Float32Array(1), [10, 10, 10], [20, 5, 20])).toThrow(
            RangeError,
        );
    });
});

describe('snowMode()', () => {
    it.each([
        [{ theme: 'sprawl', hidden: false, reducedMotion: false }, 'animate'],
        [{ theme: 'sprawl', hidden: true, reducedMotion: false }, 'paused'],
        [{ theme: 'sprawl', hidden: false, reducedMotion: true }, 'still'],
        // Reduced motion wins over a hidden tab: the one frame is drawn once the tab shows again.
        [{ theme: 'sprawl', hidden: true, reducedMotion: true }, 'still'],
        [{ theme: 'neon-noir', hidden: false, reducedMotion: false }, 'off'],
        [{ theme: 'steampunk', hidden: false, reducedMotion: true }, 'off'],
        [{ theme: null, hidden: false, reducedMotion: false }, 'off'],
        [{ theme: 'Sprawl', hidden: false, reducedMotion: false }, 'off'],
    ])('%o → %s', (state, mode) => {
        expect(snowMode(state)).toBe(mode);
    });
});

describe('colourChannels()', () => {
    /**
     * A 2D context that parses only the colours it knows, as a browser parses only the syntaxes it
     * knows — and, like a real one, silently keeps its previous fillStyle on anything else. Its
     * pixel is the colour of the last fillRect over (0, 0, 1, 1); any other read gives nothing.
     */
    function fakeContext() {
        const known = {
            '#000000': ['#000000', [0, 0, 0, 255]],
            '#ffffff': ['#ffffff', [255, 255, 255, 255]],
            'oklch(25% 0.010 250deg)': ['#1e2226', [30, 34, 38, 255]],
        };
        const channels = Object.fromEntries(Object.values(known));
        let fillStyle = '#000000';
        let pixel = [0, 0, 0, 0];

        return {
            get fillStyle() {
                return fillStyle;
            },
            set fillStyle(value) {
                if (value in known) fillStyle = known[value][0];
            },
            fillRect: vi.fn((x, y, width, height) => {
                if ([x, y, width, height].join() === '0,0,1,1') pixel = channels[fillStyle];
            }),
            getImageData: vi.fn((x, y, width, height) => ({
                data: Uint8ClampedArray.from([x, y, width, height].join() === '0,0,1,1' ? pixel : [0, 0, 0, 0]),
            })),
        };
    }

    it('reads a colour as the browser draws it, three channels without alpha', () => {
        expect(colourChannels('oklch(25% 0.010 250deg)', fakeContext())).toEqual([30, 34, 38]);
    });

    it.each([
        ['#000000', [0, 0, 0]],
        ['#ffffff', [255, 255, 255]],
    ])('reads %s itself: the colours it compares against are not taken for a refusal', (value, expected) => {
        expect(colourChannels(value, fakeContext())).toEqual(expected);
    });

    it.each([
        ['an empty token, as a missing CSS variable reads', ''],
        ['a syntax the browser does not know', 'color(display-p4 1 0 0)'],
    ])('refuses %s, before painting anything', (_, value) => {
        const context = fakeContext();

        expect(() => colourChannels(value, context)).toThrow(RangeError);
        expect(() => colourChannels(value, context)).toThrow(JSON.stringify(value));
        expect(context.fillRect).not.toHaveBeenCalled();
    });
});
