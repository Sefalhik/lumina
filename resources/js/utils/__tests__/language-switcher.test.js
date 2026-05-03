import { describe, it, expect } from 'vitest';
import { urlFor } from '../language-switcher.js';

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
