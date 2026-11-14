// @vitest-environment node
import { describe, expect, it, vi } from 'vitest';
import {
    checkOrigin,
    cli,
    countriesIn,
    jsonFetcher,
    originsIn,
    PAUSE_MS,
    RIPESTAT,
    UnreadableAnswer,
    verify,
} from '../verify-boot-origins.js';

/** What RIPEstat answers for a block originated by these AS numbers. */
const overview = (...asns) => ({ data: { asns: asns.map((asn) => ({ asn, holder: `AS${asn} holder` })) } });

/** What RIPEstat answers for a block registered in these countries. */
const registered = (...countries) => ({ data: { located_resources: countries.map((location) => ({ location })) } });

const FREE = { countryCode: 'FR', asn: 12322, carrier: 'Free', block: '78.192.0.0/16' };
const TELSTRA = { countryCode: 'AU', asn: 1221, carrier: 'Telstra', block: '1.128.0.0/16' };

/** RIPEstat as a stand-in: an answer per endpoint and block, and the URLs it was asked. */
function ripestat(answers) {
    const asked = [];
    const fetchJson = vi.fn(async (url) => {
        asked.push(url);
        const { pathname, searchParams } = new URL(url);
        const endpoint = pathname.split('/')[2];
        const answer = answers[`${endpoint} ${searchParams.get('resource')}`];
        if (answer instanceof Error) throw answer;
        return answer;
    });
    return { fetchJson, asked };
}

const holds = (...lines) =>
    Object.fromEntries(
        lines.flatMap((line) => [
            [`prefix-overview ${line.block}`, overview(line.asn)],
            [`rir-stats-country ${line.block}`, registered(line.countryCode)],
        ]),
    );

describe('reading prefix-overview', () => {
    it('gives the AS numbers, sorted', () => {
        expect(originsIn(overview(5410, 3215))).toEqual([3215, 5410]);
    });

    it('gives an empty list when nothing originates the block', () => {
        expect(originsIn(overview())).toEqual([]);
    });

    it.each([
        ['no answer', undefined],
        ['no data', {}],
        ['no list', { data: { asns: 'AS3215' } }],
        ['an entry without a number', { data: { asns: [{ holder: 'Orange' }] } }],
        ['a number written as text', { data: { asns: [{ asn: '3215' }] } }],
    ])('refuses %s: an answer not read is never "nothing to report"', (name, answer) => {
        expect(() => originsIn(answer)).toThrow(UnreadableAnswer);
    });
});

describe('reading rir-stats-country', () => {
    it('gives the countries, each once, sorted', () => {
        expect(countriesIn(registered('NL', 'FR', 'NL'))).toEqual(['FR', 'NL']);
    });

    it('gives an empty list when the block is registered nowhere', () => {
        expect(countriesIn(registered())).toEqual([]);
    });

    it.each([
        ['no answer', undefined],
        ['no data', {}],
        ['no list', { data: { located_resources: 'FR' } }],
        ['an entry without a country', { data: { located_resources: [{ resource: '78.192.0.0/16' }] } }],
    ])('refuses %s', (name, answer) => {
        expect(() => countriesIn(answer)).toThrow(UnreadableAnswer);
    });
});

describe('checking one line', () => {
    it('finds nothing wrong with a line that holds', async () => {
        const { fetchJson } = ripestat(holds(FREE));

        expect(await checkOrigin(FREE, fetchJson)).toEqual([]);
    });

    it('asks both questions about its block, the block written as a URL parameter', async () => {
        const { fetchJson, asked } = ripestat(holds(FREE));

        await checkOrigin(FREE, fetchJson);

        expect(asked).toEqual([
            `${RIPESTAT}/prefix-overview/data.json?resource=78.192.0.0%2F16`,
            `${RIPESTAT}/rir-stats-country/data.json?resource=78.192.0.0%2F16`,
        ]);
    });

    it.each([
        ['another AS', overview(3215), 'originated by AS3215, not AS12322'],
        ['two AS, its own among them', overview(12322, 3215), 'originated by AS3215, AS12322, not AS12322'],
        ['no AS at all', overview(), 'originated by no AS, not AS12322'],
    ])('says so when the block is originated by %s', async (name, answer, problem) => {
        const { fetchJson } = ripestat({ ...holds(FREE), 'prefix-overview 78.192.0.0/16': answer });

        expect(await checkOrigin(FREE, fetchJson)).toEqual([problem]);
    });

    it.each([
        ['another country', registered('BE'), 'registered in BE, not FR'],
        ['two countries, its own among them', registered('FR', 'BE'), 'registered in BE, FR, not FR'],
        ['no country at all', registered(), 'registered in no country, not FR'],
    ])('says so when the block is registered in %s', async (name, answer, problem) => {
        const { fetchJson } = ripestat({ ...holds(FREE), 'rir-stats-country 78.192.0.0/16': answer });

        expect(await checkOrigin(FREE, fetchJson)).toEqual([problem]);
    });

    it('says both when both are wrong', async () => {
        const { fetchJson } = ripestat({
            'prefix-overview 78.192.0.0/16': overview(3215),
            'rir-stats-country 78.192.0.0/16': registered('BE'),
        });

        expect(await checkOrigin(FREE, fetchJson)).toHaveLength(2);
    });

    it('lets an unreadable answer through as a failure, never as a pass', async () => {
        const { fetchJson } = ripestat({ ...holds(FREE), 'rir-stats-country 78.192.0.0/16': { data: {} } });

        await expect(checkOrigin(FREE, fetchJson)).rejects.toThrow(UnreadableAnswer);
    });
});

describe('checking the table', () => {
    it('returns the lines that do not hold, with what is wrong', async () => {
        const { fetchJson } = ripestat({ ...holds(FREE, TELSTRA), 'prefix-overview 1.128.0.0/16': overview(4804) });

        const failures = await verify({ origins: [FREE, TELSTRA], fetchJson, pause: async () => {} });

        expect(failures).toEqual([{ line: TELSTRA, problems: ['originated by AS4804, not AS1221'] }]);
    });

    it('pauses after every line: a free public service, asked politely', async () => {
        const { fetchJson } = ripestat(holds(FREE, TELSTRA));
        const pause = vi.fn(async () => {});

        await verify({ origins: [FREE, TELSTRA], fetchJson, pause });

        expect(pause).toHaveBeenCalledTimes(2);
        expect(PAUSE_MS).toBeGreaterThan(0);
    });

    it('stops at the first answer it cannot read', async () => {
        const { fetchJson, asked } = ripestat({ ...holds(TELSTRA), 'prefix-overview 78.192.0.0/16': {} });

        await expect(verify({ origins: [FREE, TELSTRA], fetchJson, pause: async () => {} })).rejects.toThrow(
            UnreadableAnswer,
        );
        expect(asked).toHaveLength(1);
    });
});

describe('fetching an answer', () => {
    it('reads the JSON of a success, with a time limit', async () => {
        const transport = vi.fn(async () => ({ ok: true, json: async () => overview(12322) }));

        expect(await jsonFetcher(transport)('https://stat.ripe.net/x')).toEqual(overview(12322));
        expect(transport).toHaveBeenCalledWith('https://stat.ripe.net/x', { signal: expect.any(AbortSignal) });
    });

    it('does not read an answer that is not a success', async () => {
        const transport = async () => ({ ok: false, status: 429, json: async () => overview(12322) });

        await expect(jsonFetcher(transport)('https://stat.ripe.net/x')).rejects.toThrow(
            'https://stat.ripe.net/x answered 429',
        );
    });

    it('lets a network failure through', async () => {
        const transport = async () => {
            throw new TypeError('fetch failed');
        };

        await expect(jsonFetcher(transport)('https://stat.ripe.net/x')).rejects.toThrow('fetch failed');
    });
});

describe('the command', () => {
    const io = (answers) => {
        const out = vi.fn();
        const err = vi.fn();
        const { fetchJson } = ripestat(answers);
        return { out, err, fetchJson, pause: async () => {} };
    };

    it('exits 0 and says so when every block holds', async () => {
        const run = io(holds(FREE, TELSTRA));

        expect(await cli({ origins: [FREE, TELSTRA], ...run })).toBe(0);
        expect(run.out).toHaveBeenCalledWith(
            'All 2 blocks hold: each is originated by its AS and registered in its country.',
        );
        expect(run.err).not.toHaveBeenCalled();
    });

    it('exits 1 and names each block that does not hold', async () => {
        const run = io({ ...holds(FREE, TELSTRA), 'rir-stats-country 1.128.0.0/16': registered('NZ') });

        expect(await cli({ origins: [FREE, TELSTRA], ...run })).toBe(1);
        expect(run.err).toHaveBeenCalledWith('1 of 2 blocks do not hold:\n');
        expect(run.err).toHaveBeenCalledWith('• AU AS1221 Telstra 1.128.0.0/16: registered in NZ, not AU\n');
        expect(run.out).not.toHaveBeenCalled();
    });

    it('exits 2 when it could not read an answer: never a pass', async () => {
        const run = io({ ...holds(FREE), 'prefix-overview 78.192.0.0/16': { data: { asns: null } } });

        expect(await cli({ origins: [FREE], ...run })).toBe(2);
        expect(run.err).toHaveBeenCalledWith(
            'The check could not run: prefix-overview: no list of AS numbers in the answer\n',
        );
        expect(run.out).not.toHaveBeenCalled();
    });

    it('exits 2 when the service does not answer', async () => {
        const run = io({ 'prefix-overview 78.192.0.0/16': new TypeError('fetch failed') });

        expect(await cli({ origins: [FREE], ...run })).toBe(2);
        expect(run.err).toHaveBeenCalledWith('The check could not run: fetch failed\n');
    });
});
