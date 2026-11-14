import { describe, expect, it, vi } from 'vitest';
import {
    applyMotionChoice,
    chooseMotion,
    documentMotion,
    MOTION_ATTRIBUTE,
    MOTION_STORAGE_KEY,
    pageMotion,
    parseMotionChoice,
    REDUCED_MOTION_QUERY,
    reducedMotionQuery,
    resolveMotion,
    toggledMotion,
    watchMotion,
} from '../motion.js';

/** An <html> element reduced to its attributes. */
function root(attributes = {}) {
    const values = new Map(Object.entries(attributes));
    return {
        getAttribute: vi.fn((name) => (values.has(name) ? values.get(name) : null)),
        setAttribute: vi.fn((name, value) => values.set(name, value)),
        removeAttribute: vi.fn((name) => values.delete(name)),
        has: (name) => values.has(name),
        value: (name) => values.get(name),
    };
}

describe('the names the anti-FOUC script and the stylesheet share', () => {
    // restore-preferences.blade.php and _effects.scss write them by hand: a rename here alone would
    // split the rule in two, silently.
    it('are the ones written there', () => {
        expect(MOTION_STORAGE_KEY).toBe('motion');
        expect(MOTION_ATTRIBUTE).toBe('data-motion');
        expect(REDUCED_MOTION_QUERY).toBe('(prefers-reduced-motion: reduce)');
    });
});

describe('a stored or carried value', () => {
    it.each(['on', 'off'])('is a choice when it is %s', (value) => {
        expect(parseMotionChoice(value)).toBe(value);
    });

    it.each([null, undefined, '', 'ON', ' off', 'true', 'false', 'paused', 1, true, {}])(
        'is no choice when it is %s',
        (value) => {
            expect(parseMotionChoice(value)).toBeNull();
        },
    );
});

describe('the rule', () => {
    it('follows the preference when no choice was made', () => {
        expect(resolveMotion({ choice: null, prefersReduced: false })).toBe('on');
        expect(resolveMotion({ choice: null, prefersReduced: true })).toBe('off');
    });

    it('lets a choice win over a preference for reduced motion — animations back on', () => {
        expect(resolveMotion({ choice: 'on', prefersReduced: true })).toBe('on');
    });

    it('lets a choice win over the absence of a preference — animations stopped', () => {
        expect(resolveMotion({ choice: 'off', prefersReduced: false })).toBe('off');
    });

    it('treats a value that is no choice as none: the preference decides', () => {
        expect(resolveMotion({ choice: 'paused', prefersReduced: true })).toBe('off');
        expect(resolveMotion({ choice: 'paused', prefersReduced: false })).toBe('on');
    });
});

describe('the state of a document', () => {
    it('reads the choice on <html>', () => {
        const html = root({ [MOTION_ATTRIBUTE]: 'off' });

        expect(documentMotion(html, { matches: false })).toBe('off');
        expect(html.getAttribute).toHaveBeenCalledWith('data-motion');
    });

    it('falls back on the query when <html> carries no choice', () => {
        expect(documentMotion(root(), { matches: true })).toBe('off');
        expect(documentMotion(root(), { matches: false })).toBe('on');
    });

    it('stops animations when the preference cannot be known', () => {
        expect(documentMotion(root(), null)).toBe('off');
    });

    it('still lets a choice turn them back on when the preference cannot be known', () => {
        expect(documentMotion(root({ [MOTION_ATTRIBUTE]: 'on' }), null)).toBe('on');
    });
});

describe('the reduced-motion query of a window', () => {
    it('is asked of the window with the query the stylesheet uses', () => {
        const query = { matches: true };
        const view = { matchMedia: vi.fn(() => query) };

        expect(reducedMotionQuery(view)).toBe(query);
        expect(view.matchMedia).toHaveBeenCalledWith('(prefers-reduced-motion: reduce)');
    });

    it('is null for a window without matchMedia', () => {
        expect(reducedMotionQuery({})).toBeNull();
        expect(reducedMotionQuery({ matchMedia: 'not a function' })).toBeNull();
    });
});

describe('the state of the page a window shows', () => {
    it('reads the document of that window, with its query', () => {
        const view = { document: { documentElement: root() }, matchMedia: () => ({ matches: true }) };

        expect(pageMotion(view)).toBe('off');
    });

    it('lets the choice on that document win', () => {
        const view = {
            document: { documentElement: root({ [MOTION_ATTRIBUTE]: 'on' }) },
            matchMedia: () => ({ matches: true }),
        };

        expect(pageMotion(view)).toBe('on');
    });

    it('stops animations for a window without matchMedia', () => {
        expect(pageMotion({ document: { documentElement: root() } })).toBe('off');
    });
});

describe('the switch', () => {
    it('reverses the current state', () => {
        expect(toggledMotion('on')).toBe('off');
        expect(toggledMotion('off')).toBe('on');
    });
});

describe('a value carried from another tab', () => {
    it.each(['on', 'off'])('sets %s on <html>', (value) => {
        const html = root();

        applyMotionChoice(value, html);

        expect(html.value('data-motion')).toBe(value);
    });

    it.each([null, '', 'paused'])('removes the attribute when it is %s, so the preference decides again', (value) => {
        const html = root({ [MOTION_ATTRIBUTE]: 'off' });

        applyMotionChoice(value, html);

        expect(html.has('data-motion')).toBe(false);
        expect(html.setAttribute).not.toHaveBeenCalled();
    });
});

describe('recording a choice', () => {
    it('sets it on <html> and stores it for the next pages', () => {
        const html = root();
        const storage = { setItem: vi.fn() };

        expect(chooseMotion('off', { root: html, storage: () => storage })).toBe('off');

        expect(html.value('data-motion')).toBe('off');
        expect(storage.setItem).toHaveBeenCalledWith('motion', 'off');
    });

    it('refuses what is no choice, and changes nothing', () => {
        const html = root({ [MOTION_ATTRIBUTE]: 'on' });
        const storage = { setItem: vi.fn() };

        expect(() => chooseMotion('paused', { root: html, storage: () => storage })).toThrow(RangeError);

        expect(html.value('data-motion')).toBe('on');
        expect(storage.setItem).not.toHaveBeenCalled();
    });

    it('still applies the choice to the page when storage refuses to write', () => {
        const html = root();
        const storage = {
            setItem: () => {
                throw new DOMException('quota', 'QuotaExceededError');
            },
        };

        expect(chooseMotion('off', { root: html, storage: () => storage })).toBe('off');
        expect(html.value('data-motion')).toBe('off');
    });

    it('still applies the choice to the page when storage cannot even be reached', () => {
        const html = root();
        const storage = () => {
            throw new DOMException('blocked', 'SecurityError');
        };

        expect(chooseMotion('on', { root: html, storage })).toBe('on');
        expect(html.value('data-motion')).toBe('on');
    });
});

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
        fire: (type, event) => (listeners.get(type) ?? []).forEach((listener) => listener(event)),
        count: () => [...listeners.values()].reduce((total, list) => total + list.length, 0),
    };
}

/** A window with its reduced-motion query and its localStorage, as stand-ins a test can drive. */
function stage({ prefersReduced = false, matchMedia = true, choice = null, localStorage = { name: 'local' } } = {}) {
    const media = { matches: prefersReduced, ...events() };
    const viewEvents = events();
    const view = {
        ...viewEvents,
        ...(matchMedia ? { matchMedia: vi.fn(() => media) } : {}),
    };
    Object.defineProperty(view, 'localStorage', {
        get: () => {
            if (localStorage instanceof Error) throw localStorage;
            return localStorage;
        },
    });
    const html = root(choice === null ? {} : { [MOTION_ATTRIBUTE]: choice });
    const onChange = vi.fn();
    const stop = watchMotion({ view, root: html, onChange });

    return {
        html,
        onChange,
        stop,
        listeners: () => viewEvents.count() + media.count(),
        setPreference(reduced) {
            media.matches = reduced;
            media.fire('change');
        },
        storage(event) {
            viewEvents.fire('storage', { storageArea: localStorage, ...event });
        },
    };
}

describe('following the state under a page', () => {
    it('reports nothing until something changes', () => {
        const { onChange } = stage();

        expect(onChange).not.toHaveBeenCalled();
    });

    it('reports a change of preference, both ways, when the visitor made no choice', () => {
        const { onChange, setPreference } = stage();

        setPreference(true);
        setPreference(false);

        expect(onChange.mock.calls).toEqual([['off'], ['on']]);
    });

    it('reports the choice, not the preference, when the visitor made one', () => {
        const { onChange, setPreference } = stage({ choice: 'on' });

        setPreference(true);

        expect(onChange).toHaveBeenCalledWith('on');
    });

    it('carries a choice made in another tab onto the page, and reports it', () => {
        const { html, onChange, storage } = stage();

        storage({ key: 'motion', newValue: 'off' });

        expect(html.value('data-motion')).toBe('off');
        expect(onChange).toHaveBeenCalledWith('off');
    });

    it('lets the preference decide again when another tab clears its storage', () => {
        // localStorage.clear() sends no key: the choice went with everything else.
        const { html, onChange, storage } = stage({ prefersReduced: true, choice: 'on' });

        storage({ key: null, newValue: null });

        expect(html.has('data-motion')).toBe(false);
        expect(onChange).toHaveBeenCalledWith('off');
    });

    it('treats a value from another tab that is no choice as none', () => {
        const { html, onChange, storage } = stage({ choice: 'off' });

        storage({ key: 'motion', newValue: 'paused' });

        expect(html.has('data-motion')).toBe(false);
        expect(onChange).toHaveBeenCalledWith('on');
    });

    it('ignores a change of another key', () => {
        const { html, onChange, storage } = stage({ choice: 'off' });

        storage({ key: 'theme', newValue: 'steampunk' });

        expect(html.value('data-motion')).toBe('off');
        expect(onChange).not.toHaveBeenCalled();
    });

    it('ignores a cleared sessionStorage: it never held the choice', () => {
        const { html, onChange, storage } = stage({ choice: 'off' });

        storage({ key: null, newValue: null, storageArea: { name: 'session' } });

        expect(html.value('data-motion')).toBe('off');
        expect(onChange).not.toHaveBeenCalled();
    });

    it('ignores storage news when the page cannot reach its own localStorage', () => {
        const { html, onChange, storage } = stage({
            choice: 'off',
            localStorage: new DOMException('blocked', 'SecurityError'),
        });

        storage({ key: 'motion', newValue: 'on', storageArea: { name: 'local' } });

        expect(html.value('data-motion')).toBe('off');
        expect(onChange).not.toHaveBeenCalled();
    });

    it('still follows other tabs in a browser without matchMedia, the preference taken as reduced', () => {
        const { onChange, storage } = stage({ matchMedia: false });

        storage({ key: 'motion', newValue: 'on' });
        storage({ key: null, newValue: null });

        expect(onChange.mock.calls).toEqual([['on'], ['off']]);
    });

    it('stops following both sources, and nothing is left listening', () => {
        const { onChange, setPreference, storage, stop, listeners } = stage();
        expect(listeners()).toBe(2);

        stop();
        setPreference(true);
        storage({ key: 'motion', newValue: 'off' });

        expect(listeners()).toBe(0);
        expect(onChange).not.toHaveBeenCalled();
    });

    it('stops cleanly in a browser without matchMedia', () => {
        const { stop, listeners } = stage({ matchMedia: false });

        stop();

        expect(listeners()).toBe(0);
    });
});
