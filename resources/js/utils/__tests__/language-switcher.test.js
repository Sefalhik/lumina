import { readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
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

    it('says the other tabs will hear of it', () => {
        expect(writeLocaleToStorage('de')).toBe(true);
    });

    it('throws nothing at the page when the browser refuses to keep it (LUMN-124)', () => {
        const setItem = vi.spyOn(localStorage, 'setItem').mockImplementation(() => {
            throw new DOMException('quota', 'QuotaExceededError');
        });

        expect(writeLocaleToStorage('de')).toBe(false);

        setItem.mockRestore();
    });
});

describe('LOCALES', () => {
    // A seam: the switcher offers a language only if the islands can speak it. A language missing
    // from either side — one deleted from this list, one file added without its entry — was
    // noticed by no test until LUMN-124's mutation pass.
    it('offers exactly the languages the islands are translated into', () => {
        const directory = join(dirname(fileURLToPath(import.meta.url)), '../../i18n');
        const translated = readdirSync(directory)
            .filter((file) => file.endsWith('.json'))
            .map((file) => file.slice(0, -'.json'.length))
            .sort();

        expect(LOCALES.map((locale) => locale.code).sort()).toEqual(translated);
    });

    it('names and flags every language', () => {
        for (const { code, name, flag } of LOCALES) {
            expect(name.trim(), code).not.toBe('');
            expect(flag.trim(), code).not.toBe('');
        }
    });
});
