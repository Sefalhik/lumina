import { describe, it, expect, beforeEach } from 'vitest';
import { createI18n } from '../i18n.js';

describe('createI18n', () => {
    beforeEach(() => {
        document.documentElement.removeAttribute('lang');
    });

    it('falls back to fr when no lang attribute is set', () => {
        const i18n = createI18n();
        expect(i18n.global.locale.value).toBe('fr');
    });

    it('uses the en locale from lang attribute', () => {
        document.documentElement.setAttribute('lang', 'en');
        const i18n = createI18n();
        expect(i18n.global.locale.value).toBe('en');
    });

    it('strips the region subtag from lang (fr-FR → fr)', () => {
        document.documentElement.setAttribute('lang', 'fr-FR');
        const i18n = createI18n();
        expect(i18n.global.locale.value).toBe('fr');
    });

    it('falls back to fr for unsupported locales', () => {
        document.documentElement.setAttribute('lang', 'de');
        const i18n = createI18n();
        expect(i18n.global.locale.value).toBe('fr');
    });

    it('exposes french translations', () => {
        document.documentElement.setAttribute('lang', 'fr');
        const i18n = createI18n();
        expect(i18n.global.t('boot.skip')).toContain('IGNORER');
    });

    it('exposes english translations', () => {
        document.documentElement.setAttribute('lang', 'en');
        const i18n = createI18n();
        expect(i18n.global.t('boot.skip')).toContain('SKIP');
    });
});
