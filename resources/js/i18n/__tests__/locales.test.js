import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/**
 * Every island's text exists in every language (LUMN-11). French is the source; a key it has that
 * another file lacks falls back to French silently, in the middle of a page in Latvian. Nothing
 * checked it until the motion switch added the first key written by hand in 24 files.
 */
const directory = join(dirname(fileURLToPath(import.meta.url)), '..');
const locales = readdirSync(directory)
    .filter((file) => file.endsWith('.json'))
    .map((file) => file.slice(0, -'.json'.length))
    .sort();

function read(locale) {
    return JSON.parse(readFileSync(join(directory, `${locale}.json`), 'utf8'));
}

/** Every leaf of a messages object, as [dotted key, value]. */
function leaves(messages, prefix = '') {
    return Object.entries(messages).flatMap(([key, value]) =>
        value !== null && typeof value === 'object' ? leaves(value, `${prefix}${key}.`) : [[`${prefix}${key}`, value]],
    );
}

const source = new Map(leaves(read('fr')));

describe('the island translations', () => {
    it('cover the 24 official languages of the European Union', () => {
        expect(locales).toEqual([
            'bg',
            'cs',
            'da',
            'de',
            'el',
            'en',
            'es',
            'et',
            'fi',
            'fr',
            'ga',
            'hr',
            'hu',
            'it',
            'lt',
            'lv',
            'mt',
            'nl',
            'pl',
            'pt',
            'ro',
            'sk',
            'sl',
            'sv',
        ]);
    });

    it.each(locales)('%s has exactly the keys of French', (locale) => {
        const keys = leaves(read(locale)).map(([key]) => key);

        expect(keys.sort()).toEqual([...source.keys()].sort());
    });

    it.each(locales)('%s has no empty text', (locale) => {
        const empty = leaves(read(locale)).filter(([, value]) => typeof value !== 'string' || value.trim() === '');

        expect(empty).toEqual([]);
    });

    it.each(locales)('%s keeps the placeholders of French', (locale) => {
        const placeholders = (text) => (String(text).match(/\{\w+\}/g) ?? []).sort();
        const mismatched = leaves(read(locale))
            .filter(([key]) => source.has(key))
            .filter(([key, value]) => placeholders(value).join() !== placeholders(source.get(key)).join())
            .map(([key]) => key);

        expect(mismatched).toEqual([]);
    });

    it('carries the motion switch in every language', () => {
        for (const locale of locales) {
            const { motion_toggle: toggle } = read(locale);

            expect(Object.keys(toggle).sort(), locale).toEqual(['label', 'off', 'on']);
            expect(toggle.on, locale).not.toBe(toggle.off);
        }
    });
});
