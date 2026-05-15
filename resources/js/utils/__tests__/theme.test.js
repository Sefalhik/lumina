import { describe, it, expect, afterEach } from 'vitest';
import { VALID_THEMES, isValidTheme, applyTheme } from '../theme.js';

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
});
