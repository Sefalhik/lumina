/**
 * Runs the dead channel's snow on a canvas (LUMN-103): when to draw, when to stop, what to re-read.
 *
 * Everything it touches is handed to it — the canvas, the window, the document, how to read a theme
 * colour, how to watch the theme, the random source — so that it is tested with stand-ins
 * (resources/js/utils/__tests__/dead-channel-controller.test.js), and DeadChannel.vue only starts and
 * stops it. The grain itself is utils/dead-channel.js.
 *
 * Its one rule: the snow is decoration, so nothing it does may break the page. A browser without a
 * 2D canvas gets no snow; a refusal from the grain functions — a colour it cannot read, a viewport of
 * zero, a random source that is not one — stops the snow, is reported once, and goes no further.
 */
import { canvasSize, FRAMES_PER_SECOND, grainPool, paintFrame, shuffle, snowMode } from './dead-channel.js';
import { documentMotion, reducedMotionQuery } from './motion.js';

/** Resize events arrive by the dozen while a window is dragged: the snow is rebuilt once, after them. */
export const RESIZE_DEBOUNCE_MS = 200;

/**
 * @typedef {object} DeadChannelDependencies
 * @property {{ width: number, height: number, dataset: Record<string, string>, getContext: (type: string) => (null | { createImageData: (width: number, height: number) => { data: Uint8ClampedArray }, putImageData: (image: object, x: number, y: number) => void }) }} canvas
 * @property {{ innerWidth: number, innerHeight: number, setTimeout: Function, clearTimeout: Function, addEventListener: Function, removeEventListener: Function, matchMedia?: (query: string) => { matches: boolean, addEventListener: Function, removeEventListener: Function } }} view
 * @property {{ hidden: boolean, documentElement: { getAttribute: (name: string) => string | null }, addEventListener: Function, removeEventListener: Function }} doc
 * @property {(token: string) => [number, number, number]} readColour  a theme token as 0–255 channels
 * @property {(callback: () => void) => () => void} observeRoot  calls back when <html> changes its theme or its motion
 *   choice (`data-theme`, `data-motion`); returns how to stop watching
 * @property {() => number} random
 * @property {(error: Error) => void} onError
 */

/**
 * @param {DeadChannelDependencies} dependencies
 * @returns {{ start: () => void, stop: () => void, frames: () => number }}
 */
export function createDeadChannel({ canvas, view, doc, readColour, observeRoot, random, onError }) {
    let context = null;
    let image = null;
    let pool = null;
    let floor = null;
    let ceiling = null;
    let timer = null;
    let resizeTimer = null;
    let frames = 0;
    let motion = null;
    let unobserveRoot = null;
    let running = false;

    function halt() {
        view.clearTimeout(timer);
    }

    /** Any refusal ends the snow, once: reported, the listeners removed, nothing left running. */
    function fail(error) {
        stop();
        onError(error);
    }

    function resize() {
        const { width, height } = canvasSize(view.innerWidth, view.innerHeight);
        canvas.width = width;
        canvas.height = height;
        image = context.createImageData(width, height);
        pool = grainPool(width * height, random);
    }

    function draw() {
        shuffle(pool, random);
        paintFrame(image.data, pool, floor, ceiling);
        context.putImageData(image, 0, 0);
        frames++;
        canvas.dataset.frames = String(frames);
    }

    function tick() {
        try {
            draw();
            timer = view.setTimeout(tick, 1000 / FRAMES_PER_SECOND);
        } catch (error) {
            fail(error);
        }
    }

    function update() {
        halt();
        try {
            const mode = snowMode({
                theme: doc.documentElement.getAttribute('data-theme'),
                hidden: doc.hidden,
                // The switch, or the preference without one (LUMN-11) — and without matchMedia, the
                // preference cannot be known: the snow stays still, on the safe side.
                motionStopped: documentMotion(doc.documentElement, motion) === 'off',
            });
            if (mode === 'off' || mode === 'paused') return;

            // Read on every start: the theme may have changed since the last one.
            floor = readColour('--color-base-100');
            ceiling = readColour('--color-base-300');

            if (mode === 'still') {
                draw();
                return;
            }
        } catch (error) {
            fail(error);
            return;
        }
        tick();
    }

    function onResize() {
        view.clearTimeout(resizeTimer);
        resizeTimer = view.setTimeout(() => {
            try {
                resize();
            } catch (error) {
                fail(error);
                return;
            }
            update();
        }, RESIZE_DEBOUNCE_MS);
    }

    function start() {
        if (running) return;
        context = canvas.getContext('2d');
        if (context === null) return;

        motion = reducedMotionQuery(view);
        try {
            resize();
        } catch (error) {
            onError(error);
            return;
        }

        running = true;
        unobserveRoot = observeRoot(update);
        doc.addEventListener('visibilitychange', update);
        motion?.addEventListener('change', update);
        view.addEventListener('resize', onResize);
        update();
    }

    function stop() {
        if (!running) return;
        running = false;
        halt();
        view.clearTimeout(resizeTimer);
        unobserveRoot();
        doc.removeEventListener('visibilitychange', update);
        motion?.removeEventListener('change', update);
        view.removeEventListener('resize', onResize);
    }

    return { start, stop, frames: () => frames };
}
