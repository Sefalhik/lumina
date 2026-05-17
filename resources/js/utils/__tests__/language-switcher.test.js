import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { LOCALES, urlFor, filterLocales, writeLocaleToStorage } from '../language-switcher.js';

describe('urlFor', () => {
    it('replaces the locale segment in a simple path', () => {
        expect(urlFor('/fr/', 'de')).toBe('/de/');
    });

    it('replaces the locale in a nested path', () => {
        expect(urlFor('/fr/cv', 'de')).toBe('/de/cv');
    });

    it('preserves the query string', () => {
        expect(urlFor('/fr/cv', 'en', '?tab=skills')).toBe('/en/cv?tab=skills');
    });

    it('handles a root path with no locale segment', () => {
        expect(urlFor('/', 'de')).toBe('/de');
    });

    it('handles a deeply nested path', () => {
        expect(urlFor('/fr/blog/my-article', 'es')).toBe('/es/blog/my-article');
    });

    it('uses an empty search by default', () => {
        expect(urlFor('/fr/', 'it')).not.toContain('?');
    });
});

describe('filterLocales', () => {
    it('returns all locales when query is empty', () => {
        expect(filterLocales(LOCALES, '')).toHaveLength(LOCALES.length);
    });

    it('returns all locales when query is whitespace only', () => {
        expect(filterLocales(LOCALES, '   ')).toHaveLength(LOCALES.length);
    });

    it('filters by locale name (case-insensitive)', () => {
        const result = filterLocales(LOCALES, 'deutsch');
        expect(result).toHaveLength(1);
        expect(result[0].code).toBe('de');
    });

    it('filters by locale code', () => {
        const result = filterLocales(LOCALES, 'fr');
        expect(result.some((l) => l.code === 'fr')).toBe(true);
    });

    it('matches partial name', () => {
        const result = filterLocales(LOCALES, 'slov');
        const codes = result.map((l) => l.code);
        expect(codes).toContain('sk');
        expect(codes).toContain('sl');
    });

    it('is case-insensitive on locale name', () => {
        expect(filterLocales(LOCALES, 'FRANÇAIS')).toHaveLength(1);
    });

    it('returns empty array when no locale matches', () => {
        expect(filterLocales(LOCALES, 'zzzzzz')).toHaveLength(0);
    });

    it('works on a custom locale list', () => {
        const custom = [
            { code: 'fr', name: 'Français', flag: '🇫🇷' },
            { code: 'de', name: 'Deutsch', flag: '🇩🇪' },
        ];
        expect(filterLocales(custom, 'de')).toHaveLength(1);
        expect(filterLocales(custom, 'de')[0].code).toBe('de');
    });
});

describe('writeLocaleToStorage', () => {
    beforeEach(() => localStorage.clear());
    afterEach(() => localStorage.clear());

    it('writes the locale code under the "locale" key', () => {
        writeLocaleToStorage('de');
        expect(localStorage.getItem('locale')).toBe('de');
    });

    it('overwrites a previously stored locale', () => {
        writeLocaleToStorage('fr');
        writeLocaleToStorage('en');
        expect(localStorage.getItem('locale')).toBe('en');
    });

    it('does not affect other localStorage keys', () => {
        localStorage.setItem('theme', 'steampunk');
        writeLocaleToStorage('es');
        expect(localStorage.getItem('theme')).toBe('steampunk');
    });
});
