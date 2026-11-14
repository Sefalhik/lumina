import { describe, expect, it, vi } from 'vitest';
import { readStored, storeQuietly, watchStoredKey } from '../storage.js';

const refused = (name) => () => {
    throw new DOMException('refused', name);
};

describe('reading a stored value', () => {
    it('gives the value', () => {
        expect(readStored(() => ({ getItem: () => 'off' }), 'motion')).toBe('off');
    });

    it('gives null when the key is absent, and an empty value as it is', () => {
        expect(readStored(() => ({ getItem: () => null }), 'motion')).toBeNull();
        expect(readStored(() => ({ getItem: () => '' }), 'motion')).toBe('');
    });

    it('asks for the key it was given', () => {
        const getItem = vi.fn(() => null);

        readStored(() => ({ getItem }), 'theme');

        expect(getItem).toHaveBeenCalledWith('theme');
    });

    it('gives undefined, never an error, when reading throws', () => {
        expect(readStored(() => ({ getItem: refused('SecurityError') }), 'motion')).toBeUndefined();
    });

    it('gives undefined when the storage cannot even be reached', () => {
        expect(readStored(refused('SecurityError'), 'motion')).toBeUndefined();
    });
});

describe('storing a value', () => {
    it('keeps it, and says so', () => {
        const setItem = vi.fn();

        expect(storeQuietly(() => ({ setItem }), 'theme', 'steampunk')).toBe(true);
        expect(setItem).toHaveBeenCalledWith('theme', 'steampunk');
    });

    it('says it was not kept, never an error, when writing throws', () => {
        expect(storeQuietly(() => ({ setItem: refused('QuotaExceededError') }), 'theme', 'sprawl')).toBe(false);
    });

    it('says it was not kept when the storage cannot even be reached', () => {
        expect(storeQuietly(refused('SecurityError'), 'theme', 'sprawl')).toBe(false);
    });
});

/** A window with its localStorage and its storage events, as stand-ins a test can drive. */
function stage({ localStorage = { name: 'local' } } = {}) {
    const listeners = [];
    const view = {
        addEventListener: vi.fn((type, listener) => listeners.push(listener)),
        removeEventListener: vi.fn((type, listener) => listeners.splice(listeners.indexOf(listener), 1)),
    };
    Object.defineProperty(view, 'localStorage', {
        get: () => {
            if (localStorage instanceof Error) throw localStorage;
            return localStorage;
        },
    });
    const onValue = vi.fn();
    const stop = watchStoredKey({ view, key: 'theme', onValue });
    return {
        view,
        onValue,
        stop,
        listeners,
        fire: (event) => listeners.forEach((listener) => listener({ storageArea: localStorage, ...event })),
    };
}

describe('following a key from other tabs', () => {
    it('listens to storage events', () => {
        const { view, listeners } = stage();

        expect(view.addEventListener).toHaveBeenCalledWith('storage', expect.any(Function));
        expect(listeners).toHaveLength(1);
    });

    it('hears the new value of its key', () => {
        const { onValue, fire } = stage();

        fire({ key: 'theme', newValue: 'steampunk' });

        expect(onValue).toHaveBeenCalledWith('steampunk');
    });

    it('hears a removed key as null', () => {
        const { onValue, fire } = stage();

        fire({ key: 'theme', newValue: null });

        expect(onValue).toHaveBeenCalledWith(null);
    });

    it('hears a cleared storage as null: localStorage.clear() names no key', () => {
        const { onValue, fire } = stage();

        fire({ key: null, newValue: null });

        expect(onValue).toHaveBeenCalledWith(null);
    });

    it('ignores another key', () => {
        const { onValue, fire } = stage();

        fire({ key: 'motion', newValue: 'off' });

        expect(onValue).not.toHaveBeenCalled();
    });

    it('ignores another storage area, cleared or changed: sessionStorage never held it', () => {
        const { onValue, fire } = stage();

        fire({ key: null, newValue: null, storageArea: { name: 'session' } });
        fire({ key: 'theme', newValue: 'steampunk', storageArea: { name: 'session' } });

        expect(onValue).not.toHaveBeenCalled();
    });

    it('ignores an event that names no storage area: a synthetic one says nothing of localStorage', () => {
        const { onValue, listeners } = stage();

        listeners.forEach((listener) => listener({ key: 'theme', newValue: 'steampunk' }));

        expect(onValue).not.toHaveBeenCalled();
    });

    it('ignores the news when the page cannot reach its own localStorage', () => {
        const { onValue, fire } = stage({ localStorage: new DOMException('blocked', 'SecurityError') });

        fire({ key: 'theme', newValue: 'steampunk', storageArea: { name: 'local' } });

        expect(onValue).not.toHaveBeenCalled();
    });

    it('stops listening, and hears nothing after', () => {
        const { stop, view, listeners, onValue, fire } = stage();

        stop();
        fire({ key: 'theme', newValue: 'steampunk' });

        expect(view.removeEventListener).toHaveBeenCalledWith('storage', expect.any(Function));
        expect(listeners).toHaveLength(0);
        expect(onValue).not.toHaveBeenCalled();
    });
});
