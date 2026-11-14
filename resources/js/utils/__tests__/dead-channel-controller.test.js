import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createDeadChannel, RESIZE_DEBOUNCE_MS } from '../dead-channel-controller.js';
import { DOWNSCALE, FRAMES_PER_SECOND } from '../dead-channel.js';

/** Sprawl's base-100 and base-300. */
const COLOURS = { '--color-base-100': [30, 34, 38], '--color-base-300': [51, 57, 62] };

/** A listener registry standing in for addEventListener / removeEventListener. */
function events() {
    const listeners = new Map();
    return {
        addEventListener: vi.fn((type, listener) => listeners.set(type, [...(listeners.get(type) ?? []), listener])),
        removeEventListener: vi.fn((type, listener) =>
            listeners.set(
                type,
                (listeners.get(type) ?? []).filter((each) => each !== listener),
            ),
        ),
        fire: (type) => (listeners.get(type) ?? []).forEach((listener) => listener()),
        count: () => [...listeners.values()].reduce((total, list) => total + list.length, 0),
    };
}

/** Everything the controller is handed, as stand-ins a test can drive. */
function stage({
    theme = 'sprawl',
    hidden = false,
    reducedMotion = false,
    motionChoice = null,
    matchMedia = true,
    context = true,
    width = 300,
    height = 150,
} = {}) {
    const drawn = [];
    const fakeContext = {
        createImageData: vi.fn((w, h) => ({ data: new Uint8ClampedArray(w * h * 4) })),
        putImageData: vi.fn((image) => drawn.push(image)),
    };
    const canvas = { width: 0, height: 0, dataset: {}, getContext: vi.fn(() => (context ? fakeContext : null)) };

    const motionEvents = events();
    const motion = { matches: reducedMotion, ...motionEvents };

    const viewEvents = events();
    const view = {
        innerWidth: width,
        innerHeight: height,
        setTimeout: (callback, delay) => setTimeout(callback, delay),
        clearTimeout: (id) => clearTimeout(id),
        ...viewEvents,
        ...(matchMedia ? { matchMedia: vi.fn(() => motion) } : {}),
    };

    const attributes = { 'data-theme': theme, 'data-motion': motionChoice };
    const docEvents = events();
    const doc = {
        hidden,
        documentElement: { getAttribute: vi.fn((name) => attributes[name] ?? null) },
        ...docEvents,
    };

    let rootCallback = null;
    const unobserve = vi.fn(() => {
        rootCallback = null;
    });
    const observeRoot = vi.fn((callback) => {
        rootCallback = callback;
        return unobserve;
    });

    const readColour = vi.fn((token) => COLOURS[token]);
    const onError = vi.fn();

    const snow = createDeadChannel({ canvas, view, doc, readColour, observeRoot, random: Math.random, onError });

    return {
        snow,
        canvas,
        view,
        doc,
        motion,
        readColour,
        onError,
        unobserve,
        fakeContext,
        drawn,
        setTheme(next) {
            attributes['data-theme'] = next;
            rootCallback?.();
        },
        /** What the switch does: a choice carried on <html>, or none. */
        setMotionChoice(next) {
            attributes['data-motion'] = next;
            rootCallback?.();
        },
        listeners: () => viewEvents.count() + docEvents.count() + motionEvents.count(),
    };
}

/** One second of snow: twelve frames. */
const SECOND = 1000;

beforeEach(() => {
    vi.useFakeTimers();
});

afterEach(() => {
    vi.useRealTimers();
});

describe('in Sprawl, a visible tab, no motion preference', () => {
    it('draws twelve frames a second, counted on the canvas', () => {
        const { snow, canvas } = stage();

        snow.start();
        expect(snow.frames()).toBe(1);

        vi.advanceTimersByTime(SECOND);
        expect(snow.frames()).toBe(1 + FRAMES_PER_SECOND);
        expect(canvas.dataset.frames).toBe(String(1 + FRAMES_PER_SECOND));
    });

    it('shuffles the grain from one frame to the next: snow, not a still picture', () => {
        const { snow, drawn } = stage();

        snow.start();
        const first = Uint8ClampedArray.from(drawn[0].data);
        vi.advanceTimersByTime(1000 / FRAMES_PER_SECOND);

        expect(drawn.at(-1).data).not.toEqual(first);
    });

    it('sizes the canvas from the viewport, at a third of its resolution', () => {
        const { snow, canvas, fakeContext } = stage({ width: 1440, height: 900 });

        snow.start();

        expect([canvas.width, canvas.height]).toEqual([1440 / DOWNSCALE, 900 / DOWNSCALE]);
        expect(fakeContext.createImageData).toHaveBeenCalledWith(480, 300);
    });

    it('paints between base-100 and base-300, as read from the theme', () => {
        const { snow, drawn, readColour } = stage();

        snow.start();

        expect(readColour).toHaveBeenCalledWith('--color-base-100');
        expect(readColour).toHaveBeenCalledWith('--color-base-300');
        const { data } = drawn[0];
        for (let offset = 0; offset < data.length; offset += 4) {
            for (let channel = 0; channel < 3; channel++) {
                expect(data[offset + channel]).toBeGreaterThanOrEqual(COLOURS['--color-base-100'][channel]);
                expect(data[offset + channel]).toBeLessThanOrEqual(COLOURS['--color-base-300'][channel]);
            }
        }
    });

    it('ignores a second start: one timer, never two', () => {
        const { snow } = stage();

        snow.start();
        snow.start();
        vi.advanceTimersByTime(SECOND);

        expect(snow.frames()).toBe(1 + FRAMES_PER_SECOND);
    });
});

describe('a hidden tab', () => {
    it('draws nothing while hidden, and starts when the tab shows', () => {
        const { snow, doc } = stage({ hidden: true });

        snow.start();
        vi.advanceTimersByTime(SECOND);
        expect(snow.frames()).toBe(0);

        doc.hidden = false;
        doc.fire('visibilitychange');
        vi.advanceTimersByTime(SECOND);
        expect(snow.frames()).toBe(1 + FRAMES_PER_SECOND);
    });

    it('stops a running snow, without a frame more', () => {
        const { snow, doc } = stage();

        snow.start();
        vi.advanceTimersByTime(SECOND);
        const before = snow.frames();

        doc.hidden = true;
        doc.fire('visibilitychange');
        vi.advanceTimersByTime(SECOND);

        expect(snow.frames()).toBe(before);
    });
});

describe('a reduced-motion preference', () => {
    it('draws one frame and nothing more', () => {
        const { snow } = stage({ reducedMotion: true });

        snow.start();
        vi.advanceTimersByTime(SECOND);

        expect(snow.frames()).toBe(1);
    });

    it('follows the preference when it changes, both ways', () => {
        const { snow, motion } = stage();

        snow.start();
        motion.matches = true;
        motion.fire('change');
        const still = snow.frames();
        vi.advanceTimersByTime(SECOND);
        expect(snow.frames()).toBe(still);

        motion.matches = false;
        motion.fire('change');
        vi.advanceTimersByTime(SECOND);
        expect(snow.frames()).toBeGreaterThanOrEqual(still + FRAMES_PER_SECOND);
    });

    it('is assumed when the browser cannot be asked: no matchMedia, no movement', () => {
        const { snow } = stage({ matchMedia: false });

        snow.start();
        vi.advanceTimersByTime(SECOND);

        expect(snow.frames()).toBe(1);
    });
});

describe('the theme', () => {
    it.each([['neon-noir'], ['steampunk'], [null]])('draws nothing in %s', (theme) => {
        const { snow } = stage({ theme });

        snow.start();
        vi.advanceTimersByTime(SECOND);

        expect(snow.frames()).toBe(0);
    });

    it('stops when Sprawl is left, starts when it comes back, and re-reads the colours', () => {
        const { snow, setTheme, readColour } = stage();

        snow.start();
        setTheme('neon-noir');
        const away = snow.frames();
        vi.advanceTimersByTime(SECOND);
        expect(snow.frames()).toBe(away);

        readColour.mockClear();
        setTheme('sprawl');
        vi.advanceTimersByTime(SECOND);
        expect(snow.frames()).toBeGreaterThanOrEqual(away + FRAMES_PER_SECOND);
        expect(readColour).toHaveBeenCalledTimes(2);
    });
});

describe('resizing the window', () => {
    it('rebuilds the snow once, after the burst of events, at the new size', () => {
        const { snow, view, canvas, fakeContext } = stage({ width: 300, height: 150 });

        snow.start();
        fakeContext.createImageData.mockClear();

        view.innerWidth = 900;
        view.innerHeight = 600;
        for (let event = 0; event < 10; event++) {
            view.fire('resize');
            vi.advanceTimersByTime(RESIZE_DEBOUNCE_MS / 4);
        }
        expect(fakeContext.createImageData).not.toHaveBeenCalled();

        vi.advanceTimersByTime(RESIZE_DEBOUNCE_MS);
        expect(fakeContext.createImageData).toHaveBeenCalledTimes(1);
        expect([canvas.width, canvas.height]).toEqual([300, 200]);
    });

    it('redraws the still frame after a rebuild: resizing a canvas clears it', () => {
        const { snow, view } = stage({ reducedMotion: true });

        snow.start();
        view.fire('resize');
        vi.advanceTimersByTime(RESIZE_DEBOUNCE_MS);
        vi.advanceTimersByTime(SECOND);

        expect(snow.frames()).toBe(2);
    });

    it('keeps a single timer running after a rebuild', () => {
        const { snow, view } = stage();

        snow.start();
        view.fire('resize');
        vi.advanceTimersByTime(RESIZE_DEBOUNCE_MS);
        const after = snow.frames();
        vi.advanceTimersByTime(SECOND);

        expect(snow.frames() - after).toBe(FRAMES_PER_SECOND);
    });
});

describe('stop()', () => {
    it('stops the timer, the pending rebuild, every listener and the theme watch', () => {
        const { snow, view, unobserve, listeners, fakeContext } = stage();

        snow.start();
        expect(listeners()).toBe(3);
        view.fire('resize');
        snow.stop();

        const frames = snow.frames();
        fakeContext.createImageData.mockClear();
        vi.advanceTimersByTime(SECOND);

        expect(snow.frames()).toBe(frames);
        expect(fakeContext.createImageData).not.toHaveBeenCalled();
        expect(listeners()).toBe(0);
        expect(unobserve).toHaveBeenCalledTimes(1);
    });

    it('stops cleanly in a browser without matchMedia', () => {
        const { snow, listeners } = stage({ matchMedia: false });

        snow.start();
        expect(() => snow.stop()).not.toThrow();

        expect(listeners()).toBe(0);
    });

    it('is harmless before a start, and twice in a row', () => {
        const { snow, unobserve } = stage();

        snow.stop();
        snow.start();
        snow.stop();
        snow.stop();

        expect(unobserve).toHaveBeenCalledTimes(1);
    });

    it('ignores the events that arrive after it', () => {
        const { snow, doc } = stage();

        snow.start();
        snow.stop();
        const frames = snow.frames();
        doc.fire('visibilitychange');
        vi.advanceTimersByTime(SECOND);

        expect(snow.frames()).toBe(frames);
    });
});

describe('a browser or a theme it cannot use', () => {
    it('gives no snow and no error without a 2D canvas', () => {
        const { snow, onError, listeners } = stage({ context: false });

        snow.start();
        vi.advanceTimersByTime(SECOND);

        expect(snow.frames()).toBe(0);
        expect(listeners()).toBe(0);
        expect(onError).not.toHaveBeenCalled();
    });

    it('reports a viewport of zero once, and starts nothing', () => {
        const { snow, onError, listeners } = stage({ width: 0 });

        expect(() => snow.start()).not.toThrow();
        vi.advanceTimersByTime(SECOND);

        expect(onError).toHaveBeenCalledTimes(1);
        expect(onError.mock.calls[0][0]).toBeInstanceOf(RangeError);
        expect(listeners()).toBe(0);
        expect(snow.frames()).toBe(0);
    });

    it.each([
        ['a colour it cannot read', (s) => s.readColour.mockImplementation(() => [0, 0])],
        [
            'a ceiling darker than the floor',
            (s) => s.readColour.mockImplementation((t) => (t === '--color-base-100' ? [60, 60, 60] : [10, 10, 10])),
        ],
        [
            'a reading that throws',
            (s) =>
                s.readColour.mockImplementation(() => {
                    throw new Error('no canvas');
                }),
        ],
    ])('stops the snow and reports once on %s, without breaking the page', (_, breakIt) => {
        const s = stage();
        breakIt(s);

        expect(() => s.snow.start()).not.toThrow();
        vi.advanceTimersByTime(SECOND);

        expect(s.onError).toHaveBeenCalledTimes(1);
        expect(s.listeners()).toBe(0);
        expect(s.unobserve).toHaveBeenCalledTimes(1);
    });

    it('stops and reports once when a frame fails mid-run', () => {
        const s = stage();
        s.snow.start();
        s.fakeContext.putImageData.mockImplementation(() => {
            throw new Error('context lost');
        });

        expect(() => vi.advanceTimersByTime(SECOND)).not.toThrow();

        expect(s.onError).toHaveBeenCalledTimes(1);
        expect(s.listeners()).toBe(0);
    });

    it('stops and reports once when a rebuild fails', () => {
        const s = stage();
        s.snow.start();
        s.view.innerWidth = Number.NaN;
        s.view.fire('resize');

        expect(() => vi.advanceTimersByTime(RESIZE_DEBOUNCE_MS)).not.toThrow();
        const frames = s.snow.frames();
        vi.advanceTimersByTime(SECOND);

        expect(s.onError).toHaveBeenCalledTimes(1);
        expect(s.listeners()).toBe(0);
        // Nothing starts again after the failure.
        expect(s.snow.frames()).toBe(frames);
    });
});

describe('the motion switch (LUMN-11)', () => {
    it('stops the snow at one frame when the visitor turned animations off', () => {
        const { snow } = stage({ motionChoice: 'off' });

        snow.start();
        vi.advanceTimersByTime(SECOND);

        expect(snow.frames()).toBe(1);
    });

    it('lets the snow run when the visitor turned animations back on despite the preference', () => {
        const { snow } = stage({ reducedMotion: true, motionChoice: 'on' });

        snow.start();
        vi.advanceTimersByTime(SECOND);

        expect(snow.frames()).toBeGreaterThanOrEqual(FRAMES_PER_SECOND);
    });

    it('lets a choice turn the snow on even when the preference cannot be known', () => {
        const { snow } = stage({ matchMedia: false, motionChoice: 'on' });

        snow.start();
        vi.advanceTimersByTime(SECOND);

        expect(snow.frames()).toBeGreaterThanOrEqual(FRAMES_PER_SECOND);
    });

    it('follows the switch while it runs, both ways', () => {
        const { snow, setMotionChoice } = stage();

        snow.start();
        vi.advanceTimersByTime(SECOND);
        const running = snow.frames();
        setMotionChoice('off');
        vi.advanceTimersByTime(SECOND);

        // One still frame drawn when it stops, then nothing.
        expect(snow.frames()).toBe(running + 1);

        setMotionChoice('on');
        vi.advanceTimersByTime(SECOND);

        expect(snow.frames()).toBeGreaterThanOrEqual(running + 1 + FRAMES_PER_SECOND);
    });

    it('treats a value that is no choice as none, and follows the preference', () => {
        const stillStage = stage({ reducedMotion: true, motionChoice: 'paused' });
        stillStage.snow.start();
        vi.advanceTimersByTime(SECOND);
        expect(stillStage.snow.frames()).toBe(1);

        const runningStage = stage({ reducedMotion: false, motionChoice: 'paused' });
        runningStage.snow.start();
        vi.advanceTimersByTime(SECOND);
        expect(runningStage.snow.frames()).toBeGreaterThanOrEqual(FRAMES_PER_SECOND);
    });
});
