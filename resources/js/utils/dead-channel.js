/**
 * The dead channel's snow — Sprawl's sky, "the color of television, tuned to a dead channel"
 * (LUMN-103). Pure functions: the component only wires them to a canvas, a timer and the page.
 *
 * Two guarantees, both tested here rather than trusted:
 *
 * - **No pixel lighter than base-300.** Every grain is a blend of base-100 (the floor) and base-300
 *   (the ceiling), never beyond. The contrast promises of the palette hold on base-300, so they hold
 *   on every pixel of snow (scripts/__tests__/palette-contrast.test.js).
 * - **No flicker of the whole.** A frame is a fixed pool of grain values, shuffled: only the
 *   positions change, so the mean luminance of every frame is the same, exactly. Grain, not a
 *   flashing screen (WCAG 2.3.1).
 */

/** Twelve frames a second: the jitter of an old tube, far from a smooth animation. */
export const FRAMES_PER_SECOND = 12;

/** One canvas pixel per three screen pixels each way, stretched: a fine grain, a ninth of the work. */
export const DOWNSCALE = 3;

/**
 * How bright a grain is, between floor (0) and ceiling (1). A cube keeps most grains near the
 * background and lets a few specks of snow through: "à peine visible".
 */
const SHAPE = 3;

/**
 * One value from the random source. A value that is not a finite number is refused: NaN slips through
 * Math.min and Math.max, and would paint a black grain or corrupt the pool.
 *
 * @param {() => number} random
 * @returns {number}
 */
function draw(random) {
    const value = random();
    if (typeof value !== 'number' || !Number.isFinite(value)) {
        throw new RangeError(`The random source gave ${value}, not a finite number.`);
    }
    return value;
}

/**
 * A colour as three channels from 0 to 255; anything else is refused.
 *
 * @param {string} name
 * @param {unknown} colour
 */
function assertColour(name, colour) {
    const valid =
        Array.isArray(colour) &&
        colour.length === 3 &&
        colour.every(
            (channel) => typeof channel === 'number' && Number.isFinite(channel) && channel >= 0 && channel <= 255,
        );
    if (!valid) {
        throw new RangeError(`The ${name} must be three channels from 0 to 255, not ${JSON.stringify(colour)}.`);
    }
}

/**
 * The canvas size for a viewport, in canvas pixels.
 *
 * @param {number} width viewport width, in CSS pixels
 * @param {number} height viewport height, in CSS pixels
 * @param {number} [downscale]
 * @returns {{ width: number, height: number }}
 */
export function canvasSize(width, height, downscale = DOWNSCALE) {
    for (const [name, value] of [
        ['width', width],
        ['height', height],
        ['downscale', downscale],
    ]) {
        if (typeof value !== 'number' || !Number.isFinite(value) || value <= 0) {
            throw new RangeError(`The ${name} of the snow must be a positive number, not ${value}.`);
        }
    }
    return { width: Math.ceil(width / downscale), height: Math.ceil(height / downscale) };
}

/**
 * The grain values a frame is made of, drawn once: each frame shuffles them, never redraws them.
 *
 * @param {number} size number of grains (canvas pixels)
 * @param {() => number} random a source in [0, 1), as Math.random
 * @returns {Float32Array} values in [0, 1]
 */
export function grainPool(size, random) {
    if (!Number.isInteger(size) || size <= 0) {
        throw new RangeError(`A grain pool needs a positive whole size, not ${size}.`);
    }
    const pool = new Float32Array(size);
    for (let index = 0; index < size; index++) {
        // Clamped: a source that strays outside [0, 1) must not push a grain past the ceiling.
        pool[index] = Math.min(1, Math.max(0, draw(random))) ** SHAPE;
    }
    return pool;
}

/**
 * Fisher–Yates, in place: a permutation, so the pool keeps every value it had.
 *
 * @param {Float32Array} pool
 * @param {() => number} random
 * @returns {Float32Array} the same pool
 */
export function shuffle(pool, random) {
    for (let index = pool.length - 1; index > 0; index--) {
        const other = Math.min(index, Math.max(0, Math.floor(draw(random) * (index + 1))));
        [pool[index], pool[other]] = [pool[other], pool[index]];
    }
    return pool;
}

/**
 * Paints one frame into RGBA pixel data: each grain blends floor and ceiling, fully opaque.
 *
 * @param {Uint8ClampedArray} pixels RGBA, four bytes per grain
 * @param {Float32Array} pool one value per grain
 * @param {[number, number, number]} floor base-100, channels 0–255
 * @param {[number, number, number]} ceiling base-300, channels 0–255 — none below the floor's
 * @returns {Uint8ClampedArray} the same pixels
 */
export function paintFrame(pixels, pool, floor, ceiling) {
    if (pixels.length !== pool.length * 4) {
        throw new RangeError(`${pool.length} grains need ${pool.length * 4} bytes, not ${pixels.length}.`);
    }
    assertColour('floor', floor);
    assertColour('ceiling', ceiling);
    if (floor.some((channel, index) => channel > ceiling[index])) {
        // A ceiling darker than its floor would turn "never lighter than base-300" upside down.
        throw new RangeError(`The ceiling ${ceiling} is darker than the floor ${floor} on some channel.`);
    }
    for (let grain = 0; grain < pool.length; grain++) {
        // The ceiling is guaranteed here, where the pixels are painted, not trusted to whoever built
        // the pool: a value past 1 would paint lighter than base-300.
        if (!(pool[grain] >= 0 && pool[grain] <= 1)) {
            throw new RangeError(`Grain ${grain} is ${pool[grain]}, outside [0, 1].`);
        }
        const offset = grain * 4;
        for (let channel = 0; channel < 3; channel++) {
            pixels[offset + channel] = Math.floor(floor[channel] + pool[grain] * (ceiling[channel] - floor[channel]));
        }
        pixels[offset + 3] = 255;
    }
    return pixels;
}

/**
 * What the snow should be doing.
 *
 * - `off`: another theme — nothing drawn, nothing running;
 * - `still`: animations stopped, by the switch or the preference (utils/motion.js) — one frame, then
 *   nothing moves;
 * - `paused`: the tab is hidden — nothing runs that nobody sees;
 * - `animate`: twelve frames a second.
 *
 * @param {{ theme: string | null, hidden: boolean, motionStopped: boolean }} state
 * @returns {'off' | 'still' | 'paused' | 'animate'}
 */
export function snowMode({ theme, hidden, motionStopped }) {
    if (theme !== 'sprawl') return 'off';
    if (motionStopped) return 'still';
    if (hidden) return 'paused';
    return 'animate';
}

/**
 * A CSS colour as three channels from 0 to 255, through a 2D context's own conversion: the browser
 * knows oklch(), this code does not have to.
 *
 * A context silently ignores a value it cannot parse — an empty token, a syntax it does not know —
 * and keeps its previous fillStyle: the snow would paint in that colour, black by default, and say
 * nothing. So the value is set over two different colours: a context that kept either has refused it.
 *
 * @param {string} value a CSS colour, as a theme token holds it
 * @param {{ fillStyle: string, fillRect: (x: number, y: number, width: number, height: number) => void, getImageData: (x: number, y: number, width: number, height: number) => { data: Uint8ClampedArray } }} context
 * @returns {[number, number, number]}
 */
export function colourChannels(value, context) {
    const readings = ['#000000', '#ffffff'].map((previous) => {
        context.fillStyle = previous;
        context.fillStyle = value;
        return context.fillStyle;
    });
    if (readings[0] !== readings[1]) {
        throw new RangeError(`This browser cannot draw the colour ${JSON.stringify(value)}.`);
    }

    context.fillRect(0, 0, 1, 1);
    const [red, green, blue] = context.getImageData(0, 0, 1, 1).data;
    return [red, green, blue];
}
