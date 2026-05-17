import { describe, it, expect } from 'vitest';
import { parseTechs, parseSkills, serializeCategory, createCategory, createTech } from '../skills-editor.js';

const makeKey = () => {
    let k = 0;
    return () => ++k;
};

// ─── parseTechs ───────────────────────────────────────────────────────────────

describe('parseTechs', () => {
    it('wraps each string in a Tech object', () => {
        const result = parseTechs(['PHP', 'Laravel'], makeKey());
        expect(result).toEqual([
            { v: 'PHP', _key: 1 },
            { v: 'Laravel', _key: 2 },
        ]);
    });

    it('returns an empty array for an empty input', () => {
        expect(parseTechs([], makeKey())).toEqual([]);
    });

    it('assigns unique keys across calls using the provided nextKey', () => {
        const nextKey = makeKey();
        const [a] = parseTechs(['X'], nextKey);
        const [b] = parseTechs(['Y'], nextKey);
        expect(a._key).not.toBe(b._key);
    });
});

// ─── parseSkills ──────────────────────────────────────────────────────────────

describe('parseSkills', () => {
    it('parses a valid JSON payload', () => {
        const json = JSON.stringify([{ icon: '◈', name: 'Frontend', techs: ['Vue'] }]);
        const result = parseSkills(json, makeKey());
        expect(result).toHaveLength(1);
        expect(result[0].icon).toBe('◈');
        expect(result[0].name).toBe('Frontend');
        expect(result[0].techs).toEqual([{ v: 'Vue', _key: 1 }]);
    });

    it('returns an empty array for an empty JSON array', () => {
        expect(parseSkills('[]', makeKey())).toEqual([]);
    });

    it('defaults icon to ⬡ when absent', () => {
        const json = JSON.stringify([{ name: 'Backend', techs: [] }]);
        expect(parseSkills(json, makeKey())[0].icon).toBe('⬡');
    });

    it('defaults name to empty string when absent', () => {
        const json = JSON.stringify([{ icon: '⬡', techs: [] }]);
        expect(parseSkills(json, makeKey())[0].name).toBe('');
    });

    it('defaults techs to empty array when absent', () => {
        const json = JSON.stringify([{ icon: '⬡', name: 'Backend' }]);
        expect(parseSkills(json, makeKey())[0].techs).toEqual([]);
    });

    it('assigns a _key to each category', () => {
        const json = JSON.stringify([
            { name: 'A', techs: [] },
            { name: 'B', techs: [] },
        ]);
        const result = parseSkills(json, makeKey());
        expect(result[0]._key).not.toBe(result[1]._key);
    });
});

// ─── serializeCategory ────────────────────────────────────────────────────────

describe('serializeCategory', () => {
    it('unwraps techs from Tech objects to plain strings', () => {
        const cat = { icon: '⬡', name: 'Backend', techs: [{ v: 'PHP', _key: 1 }], _key: 99 };
        expect(serializeCategory(cat).techs).toEqual(['PHP']);
    });

    it('strips _key from the output', () => {
        const cat = { icon: '⬡', name: 'Backend', techs: [], _key: 99 };
        expect(serializeCategory(cat)).not.toHaveProperty('_key');
    });

    it('preserves icon and name', () => {
        const cat = { icon: '◈', name: 'Frontend', techs: [], _key: 1 };
        const result = serializeCategory(cat);
        expect(result.icon).toBe('◈');
        expect(result.name).toBe('Frontend');
    });

    it('round-trips through parseSkills', () => {
        const original = [{ icon: '⬡', name: 'Backend', techs: ['PHP', 'Laravel'] }];
        const parsed = parseSkills(JSON.stringify(original), makeKey());
        expect(parsed.map(serializeCategory)).toEqual(original);
    });
});

// ─── createCategory ───────────────────────────────────────────────────────────

describe('createCategory', () => {
    it('returns an empty name', () => {
        expect(createCategory(makeKey()).name).toBe('');
    });

    it('returns the default icon ⬡', () => {
        expect(createCategory(makeKey()).icon).toBe('⬡');
    });

    it('returns an empty techs array', () => {
        expect(createCategory(makeKey()).techs).toEqual([]);
    });

    it('calls nextKey once for the category _key', () => {
        const nextKey = makeKey();
        expect(createCategory(nextKey)._key).toBe(1);
    });
});

// ─── createTech ───────────────────────────────────────────────────────────────

describe('createTech', () => {
    it('returns an empty value', () => {
        expect(createTech(makeKey()).v).toBe('');
    });

    it('calls nextKey once for the tech _key', () => {
        const nextKey = makeKey();
        expect(createTech(nextKey)._key).toBe(1);
    });
});
