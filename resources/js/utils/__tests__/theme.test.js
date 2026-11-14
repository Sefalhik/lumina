import { existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, expect, afterEach, vi } from 'vitest';
import {
    applyTheme,
    chooseTheme,
    currentTheme,
    DEFAULT_THEME,
    isValidTheme,
    THEME_STORAGE_KEY,
    THEMES,
    VALID_THEMES,
    watchTheme,
} from '../theme.js';

describe('VALID_THEMES', () => {
    it('contains exactly three themes', () => {
        expect(VALID_THEMES).toHaveLength(3);
    });

    it('includes sprawl, steampunk and neon-noir', () => {
        expect(VALID_THEMES).toContain('sprawl');
        expect(VALID_THEMES).toContain('steampunk');
        expect(VALID_THEMES).toContain('neon-noir');
    });
});

describe('isValidTheme', () => {
    it.each(VALID_THEMES)('returns true for "%s"', (id) => {
        expect(isValidTheme(id)).toBe(true);
    });

    it.each(['', 'dark', 'light', 'cyberpunk', 'unknown'])('returns false for "%s"', (id) => {
        expect(isValidTheme(id)).toBe(false);
    });
});

describe('applyTheme', () => {
    afterEach(() => {
        document.documentElement.removeAttribute('data-theme');
        document.getElementById('favicon')?.remove();
    });

    it('sets data-theme on the document element', () => {
        applyTheme('steampunk');
        expect(document.documentElement.getAttribute('data-theme')).toBe('steampunk');
    });

    it('overwrites an existing data-theme value', () => {
        document.documentElement.setAttribute('data-theme', 'sprawl');
        applyTheme('neon-noir');
        expect(document.documentElement.getAttribute('data-theme')).toBe('neon-noir');
    });

    it('updates the favicon href when #favicon is present', () => {
        const link = document.createElement('link');
        link.id = 'favicon';
        document.head.appendChild(link);

        applyTheme('neon-noir');

        expect(link.getAttribute('href')).toContain('favicon-neon-noir.svg');
    });

    it('sets the correct favicon path for each valid theme', () => {
        const link = document.createElement('link');
        link.id = 'favicon';
        document.head.appendChild(link);

        for (const id of VALID_THEMES) {
            applyTheme(id);
            expect(link.getAttribute('href')).toContain(`favicon-${id}.svg`);
        }
    });

    it('does not throw when #favicon is absent', () => {
        expect(() => applyTheme('sprawl')).not.toThrow();
    });

    it.each([undefined, null, '', 'dark', 'SPRAWL', 0, {}])('refuses %s, and touches nothing', (id) => {
        document.documentElement.setAttribute('data-theme', 'sprawl');
        const link = document.createElement('link');
        link.id = 'favicon';
        link.setAttribute('href', '/favicons/favicon-sprawl.svg');
        document.head.appendChild(link);

        expect(() => applyTheme(id)).toThrow(RangeError);

        expect(document.documentElement.getAttribute('data-theme')).toBe('sprawl');
        expect(link.getAttribute('href')).toBe('/favicons/favicon-sprawl.svg');
    });
});

const PUBLIC = join(dirname(fileURLToPath(import.meta.url)), '../../../../public');

describe('the list of themes', () => {
    it('makes the first theme the default: the one every layout is served in', () => {
        expect(DEFAULT_THEME).toBe('sprawl');
        expect(DEFAULT_THEME).toBe(THEMES[0].id);
    });

    it('gives every theme a favicon that exists', () => {
        // A seam between themes.json and public/: a theme added without its file would show none.
        for (const { id, favicon } of THEMES) {
            expect(favicon, id).toBe(`/favicons/favicon-${id}.svg`);
            expect(existsSync(join(PUBLIC, favicon)), favicon).toBe(true);
        }
    });

    it('keeps its stored choice under the key the anti-FOUC script reads', () => {
        expect(THEME_STORAGE_KEY).toBe('theme');
    });
});

describe('the theme a page shows', () => {
    const root = (theme) => ({ getAttribute: (name) => (name === 'data-theme' ? theme : null) });

    it.each(VALID_THEMES)('is the one <html> carries: %s', (id) => {
        expect(currentTheme(root(id))).toBe(id);
    });

    it.each([null, '', 'dark', 'Sprawl'])('is the default when <html> carries none the site knows (%s)', (value) => {
        expect(currentTheme(root(value))).toBe(DEFAULT_THEME);
    });
});

describe('choosing a theme', () => {
    afterEach(() => {
        document.documentElement.removeAttribute('data-theme');
    });

    it('applies it, keeps it, and says which', () => {
        const setItem = vi.fn();

        expect(chooseTheme('steampunk', { storage: () => ({ setItem }) })).toBe('steampunk');

        expect(document.documentElement.getAttribute('data-theme')).toBe('steampunk');
        expect(setItem).toHaveBeenCalledWith('theme', 'steampunk');
    });

    it.each([undefined, null, 'dark', 'SPRAWL', 42])('refuses %s, and neither shows nor keeps it', (id) => {
        document.documentElement.setAttribute('data-theme', 'sprawl');
        const setItem = vi.fn();

        expect(() => chooseTheme(id, { storage: () => ({ setItem }) })).toThrow(RangeError);

        expect(document.documentElement.getAttribute('data-theme')).toBe('sprawl');
        expect(setItem).not.toHaveBeenCalled();
    });

    it('still applies it when the browser refuses to keep it', () => {
        // Before LUMN-124, the switcher wrote before updating what it showed: a refused write left
        // the page in one theme and the switcher naming another.
        const storage = () => {
            throw new DOMException('blocked', 'SecurityError');
        };

        expect(chooseTheme('neon-noir', { storage })).toBe('neon-noir');
        expect(document.documentElement.getAttribute('data-theme')).toBe('neon-noir');
    });
});

describe('a theme changed in another tab', () => {
    // How other tabs are heard — which key, which storage area — is storage.test.js. These prove
    // watchTheme wires it, and what a change means for the theme.
    function stage() {
        const listeners = [];
        const localStorage = { name: 'local' };
        const view = {
            localStorage,
            addEventListener: vi.fn((type, listener) => listeners.push(listener)),
            removeEventListener: vi.fn((type, listener) => listeners.splice(listeners.indexOf(listener), 1)),
        };
        const onChange = vi.fn();
        const stop = watchTheme({ view, onChange });
        return {
            onChange,
            stop,
            listeners,
            fire: (event) => listeners.forEach((listener) => listener({ storageArea: localStorage, ...event })),
        };
    }

    afterEach(() => {
        document.documentElement.removeAttribute('data-theme');
    });

    it('is applied here, and reported', () => {
        const { onChange, fire } = stage();

        fire({ key: 'theme', newValue: 'steampunk' });

        expect(document.documentElement.getAttribute('data-theme')).toBe('steampunk');
        expect(onChange).toHaveBeenCalledWith('steampunk');
    });

    it('cleared there brings the default back here, as the next page would', () => {
        document.documentElement.setAttribute('data-theme', 'neon-noir');
        const { onChange, fire } = stage();

        fire({ key: null, newValue: null });

        expect(document.documentElement.getAttribute('data-theme')).toBe(DEFAULT_THEME);
        expect(onChange).toHaveBeenCalledWith(DEFAULT_THEME);
    });

    it('that is no theme of the site counts as none: the default', () => {
        const { onChange, fire } = stage();

        fire({ key: 'theme', newValue: 'dark' });

        expect(onChange).toHaveBeenCalledWith(DEFAULT_THEME);
    });

    it('is no longer followed once stopped', () => {
        const { onChange, stop, listeners } = stage();

        stop();

        expect(listeners).toHaveLength(0);
        expect(onChange).not.toHaveBeenCalled();
    });
});
