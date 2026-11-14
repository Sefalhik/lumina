import { describe, expect, it } from 'vitest';
import { EAST, maskIp, MIDWEST, toGibsonLocation, WEST, ZONES } from '../boot.js';
import { drawOrigin, ORIGIN_TREE, ORIGINS } from '../boot-origin.js';

/** A random source that answers the given values in turn, and counts how often it was asked. */
function sequence(...values) {
    let index = 0;
    const random = () => values[index++];
    random.calls = () => index;
    return random;
}

/** A seeded generator (mulberry32): a statistical test that gives the same answer on every run. */
function seeded(seed) {
    let state = seed >>> 0;
    return () => {
        state = (state + 0x6d2b79f5) >>> 0;
        let t = state;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

/** The address blocks no visitor ever comes from (RFC 6890 and its updates), as [first, last]. */
const NOT_PUBLIC = [
    ['0.0.0.0', '0.255.255.255'], // this network
    ['10.0.0.0', '10.255.255.255'], // private
    ['100.64.0.0', '100.127.255.255'], // shared address space
    ['127.0.0.0', '127.255.255.255'], // loopback
    ['169.254.0.0', '169.254.255.255'], // link local
    ['172.16.0.0', '172.31.255.255'], // private
    ['192.0.0.0', '192.0.0.255'], // protocol assignments
    ['192.0.2.0', '192.0.2.255'], // documentation
    ['192.88.99.0', '192.88.99.255'], // 6to4 relay anycast
    ['192.168.0.0', '192.168.255.255'], // private
    ['198.18.0.0', '198.19.255.255'], // benchmarking
    ['198.51.100.0', '198.51.100.255'], // documentation
    ['203.0.113.0', '203.0.113.255'], // documentation
    ['224.0.0.0', '255.255.255.255'], // multicast, reserved, broadcast
];

const toNumber = (address) => address.split('.').reduce((total, octet) => total * 256 + Number(octet), 0);
const isPublic = (address) =>
    NOT_PUBLIC.every(([first, last]) => toNumber(address) < toNumber(first) || toNumber(address) > toNumber(last));

/** A block's network, its size, and its first and last addresses. */
function span(block) {
    const [network, length] = block.split('/');
    const size = 2 ** (32 - Number(length));
    const last = toNumber(network) + size - 1;
    const dotted = [24, 16, 8, 0].map((shift) => Math.floor(last / 2 ** shift) % 256).join('.');
    return { network, length: Number(length), size, first: network, last: dotted };
}

const named = (origin) => `${origin.countryCode} AS${origin.asn} ${origin.block}`;

/** The random values that walk the tree to a line: country, carrier in a city, block. */
function pathTo(origin) {
    for (const [c, country] of ORIGIN_TREE.entries()) {
        for (const [p, presence] of country.entries()) {
            const b = presence.indexOf(origin);
            if (b !== -1) {
                return [(c + 0.5) / ORIGIN_TREE.length, (p + 0.5) / country.length, (b + 0.5) / presence.length];
            }
        }
    }
    throw new Error(`Not in the tree: ${named(origin)}`);
}

/** Each line by the two octets it shows — unique, as a test below requires. */
const BY_FIRST_TWO_OCTETS = new Map(ORIGINS.map((origin) => [origin.block.split('.').slice(0, 2).join('.'), origin]));

/**
 * The line of the table a drawn origin came from: the one its first two octets name, provided the
 * address is inside that line's block and the carrier and city are that line's.
 */
function lineOf(drawn) {
    const origin = BY_FIRST_TWO_OCTETS.get(drawn.query.split('.').slice(0, 2).join('.'));
    if (origin === undefined) return undefined;
    const { first, last } = span(origin.block);
    const inside = toNumber(drawn.query) >= toNumber(first) && toNumber(drawn.query) <= toNumber(last);
    const same = drawn.as === `AS${origin.asn} ${origin.carrier}` && drawn.city === origin.city;
    return inside && same ? origin : undefined;
}

describe('the table of origins', () => {
    it('holds the 334 blocks checked on RIPEstat on 2026-11-14', () => {
        // A line added or lost changes this number: change it only with a new check of the line.
        expect(ORIGINS).toHaveLength(334);
    });

    it('cannot be changed at run time, nor any of its lines', () => {
        expect(Object.isFrozen(ORIGINS)).toBe(true);
        expect(ORIGINS.every(Object.isFrozen)).toBe(true);
    });

    it.each(ORIGINS.map((origin) => [named(origin), origin]))(
        '%s is a block from /16 to /24, written from its network address',
        (name, { block }) => {
            expect(block).toMatch(/^(\d{1,3}\.){3}\d{1,3}\/\d{2}$/);
            const { network, length, size } = span(block);
            expect(network.split('.').every((octet) => Number(octet) <= 255)).toBe(true);
            expect(length).toBeGreaterThanOrEqual(16);
            expect(length).toBeLessThanOrEqual(24);
            expect(toNumber(network) % size).toBe(0);
        },
    );

    it.each(ORIGINS.map((origin) => [named(origin), origin]))(
        '%s holds only public addresses, from its first to its last',
        (name, { block }) => {
            const { first, last } = span(block);
            expect(isPublic(first)).toBe(true);
            expect(isPublic(last)).toBe(true);
        },
    );

    it('shows each pair of first two octets once: two lines never look alike', () => {
        const shown = ORIGINS.map((origin) => origin.block.split('.').slice(0, 2).join('.'));

        expect(new Set(shown).size).toBe(shown.length);
    });

    it.each(ORIGINS.map((origin) => [named(origin), origin]))(
        '%s: an AS number, a carrier that fits the sequence, a city, a region',
        (name, origin) => {
            expect(Number.isInteger(origin.asn) && origin.asn > 0).toBe(true);
            expect(origin.carrier.trim()).not.toBe('');
            // The [NET ] Carrier line holds 28 characters before the terminal's edge.
            expect(origin.carrier.length).toBeLessThanOrEqual(28);
            expect(origin.city.trim()).not.toBe('');
            expect(origin.region.trim()).not.toBe('');
        },
    );
});

describe('the table and the zones of Gibson', () => {
    it('reaches every zone, and comes from no country without one', () => {
        const countries = new Set(ORIGINS.map((origin) => origin.countryCode));

        expect([...countries].sort()).toEqual(Object.keys(ZONES).sort());
    });

    it('reaches every state the Sprawl lists, east, west and midwest', () => {
        const states = new Set(ORIGINS.filter((origin) => origin.countryCode === 'US').map((origin) => origin.region));

        for (const state of [...EAST, ...WEST, ...MIDWEST]) {
            expect(states.has(state), state).toBe(true);
        }
    });

    it('reaches the fourth branch of the United States too, a state outside the three lists', () => {
        const listed = new Set([...EAST, ...WEST, ...MIDWEST]);

        expect(ORIGINS.some((origin) => origin.countryCode === 'US' && !listed.has(origin.region))).toBe(true);
    });

    it.each(ORIGINS.map((origin) => [named(origin), origin]))('%s lands in a place of its zone', (name, origin) => {
        const { location, carrier } = toGibsonLocation(drawOrigin(sequence(...pathTo(origin), 0)));

        expect(location).toMatch(/^\S.* \/ \S.*$/);
        expect(carrier).toBe(origin.carrier.toUpperCase());
    });
});

describe('the tree a draw walks', () => {
    it('holds every line of the table, once, in its order', () => {
        const walked = ORIGIN_TREE.flat(2);

        expect(walked).toHaveLength(ORIGINS.length);
        expect(walked.every((origin, index) => origin === ORIGINS[index])).toBe(true);
    });

    it('has one branch per country, and one per carrier in a city', () => {
        expect(ORIGIN_TREE).toHaveLength(Object.keys(ZONES).length);
        for (const country of ORIGIN_TREE) {
            expect(new Set(country.flat().map((origin) => origin.countryCode)).size).toBe(1);
            for (const presence of country) {
                expect(presence.length).toBeGreaterThan(0);
                expect(new Set(presence.map((origin) => `${origin.asn} ${origin.city}`)).size).toBe(1);
            }
        }
        const presences = ORIGIN_TREE.flat().map((presence) => `${presence[0].asn} ${presence[0].city}`);
        expect(new Set(presences).size).toBe(presences.length);
    });

    it('cannot be changed at run time', () => {
        expect(Object.isFrozen(ORIGIN_TREE)).toBe(true);
        expect(ORIGIN_TREE.every(Object.isFrozen)).toBe(true);
        expect(ORIGIN_TREE.flat().every(Object.isFrozen)).toBe(true);
    });
});

describe('drawing an origin', () => {
    it('gives the shape toGibsonLocation() reads, the carrier behind its AS number', () => {
        const first = ORIGINS[0];

        expect(drawOrigin(sequence(0, 0, 0, 0))).toEqual({
            query: first.block.split('/')[0],
            city: first.city,
            countryCode: first.countryCode,
            region: first.region,
            as: `AS${first.asn} ${first.carrier}`,
        });
    });

    it('asks the random source four times: country, carrier, block, address', () => {
        const random = sequence(0.3, 0.3, 0.3, 0.3, 0.3);

        drawOrigin(random);

        expect(random.calls()).toBe(4);
    });

    it.each(ORIGINS.map((origin) => [named(origin), origin]))('can reach %s', (name, origin) => {
        expect(drawOrigin(sequence(...pathTo(origin), 0)).query).toBe(span(origin.block).first);
    });

    it('reaches the very last line, and the last address of its block', () => {
        const almostOne = 1 - Number.EPSILON;
        const last = ORIGINS.at(-1);

        expect(drawOrigin(sequence(almostOne, almostOne, almostOne, almostOne)).query).toBe(span(last.block).last);
    });

    it('draws the address over its whole block', () => {
        const origin = ORIGINS.find((each) => each.block.endsWith('/16'));
        const path = pathTo(origin);
        const [a, b] = origin.block.split('.');

        expect(drawOrigin(sequence(...path, 256 / 65536)).query).toBe(`${a}.${b}.1.0`);
        expect(drawOrigin(sequence(...path, 255 / 65536)).query).toBe(`${a}.${b}.0.255`);
    });

    it('stays inside a smaller block too', () => {
        const origin = ORIGINS.find((each) => !each.block.endsWith('/16'));
        const { first, last } = span(origin.block);
        const path = pathTo(origin);

        expect(drawOrigin(sequence(...path, 0)).query).toBe(first);
        expect(drawOrigin(sequence(...path, 1 - Number.EPSILON)).query).toBe(last);
    });

    it('gives a public address, inside the block of its line, whatever the draw', () => {
        const random = seeded(122);
        for (let draw = 0; draw < 5000; draw++) {
            const drawn = drawOrigin(random);

            expect(lineOf(drawn), drawn.query).toBeDefined();
            expect(isPublic(drawn.query), drawn.query).toBe(true);
            expect(maskIp(drawn.query)).toMatch(/^\d{1,3}\.\d{1,3}\.███\.███$/);
        }
    });

    it('lands in every country about as often as in any other — Lagos as often as Paris', () => {
        const random = seeded(2026);
        const counts = new Map();
        for (let draw = 0; draw < ORIGIN_TREE.length * 1000; draw++) {
            const { countryCode } = drawOrigin(random);
            counts.set(countryCode, (counts.get(countryCode) ?? 0) + 1);
        }

        expect(counts.size).toBe(ORIGIN_TREE.length);
        for (const [country, count] of counts) {
            // A thousand expected for each; six standard deviations either side.
            expect(count, country).toBeGreaterThan(810);
            expect(count, country).toBeLessThan(1190);
        }
    });

    it('surprises: every line of the table comes up, sooner or later', () => {
        // Each line shows its own two octets, so every line is an origin a visitor can see. With
        // the draw going by country first, the rarest line is an American one: about one visit in
        // four thousand. Fifty thousand seeded visits reach them all.
        const random = seeded(7);
        const seen = new Set();
        for (let draw = 0; draw < 50000; draw++) seen.add(lineOf(drawOrigin(random)));

        expect(seen.size).toBe(ORIGINS.length);
    });

    it('differs when the random source differs', () => {
        expect(drawOrigin(sequence(0.1, 0.5, 0.5, 0.5))).not.toEqual(drawOrigin(sequence(0.9, 0.5, 0.5, 0.5)));
        expect(drawOrigin(sequence(0.1, 0.5, 0.5, 0.2)).query).not.toBe(drawOrigin(sequence(0.1, 0.5, 0.5, 0.8)).query);
    });
});

describe('the test of public addresses itself', () => {
    // A check that says yes to everything would let the table hold anything.
    it.each([
        '10.1.2.3',
        '127.0.0.1',
        '172.20.0.1',
        '192.168.1.1',
        '100.64.0.1',
        '169.254.1.1',
        '224.0.0.1',
        '0.1.2.3',
    ])('refuses %s', (address) => {
        expect(isPublic(address)).toBe(false);
    });
});
