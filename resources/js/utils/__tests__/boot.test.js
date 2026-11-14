import { describe, it, expect } from 'vitest';
import { EAST, getBrowserName, getOSName, maskIp, MIDWEST, toGibsonLocation, WEST } from '../boot';

// ─── getBrowserName ───────────────────────────────────────────────────────────

describe('getBrowserName', () => {
    it.each([
        ['Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0', 'MOZILLA_FIREFOX'],
        ['Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/120.0 Safari/537.36 Edg/120.0', 'MS_EDGE'],
        [
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
            'CHROMIUM',
        ],
        [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
            'WEBKIT_SAFARI',
        ],
        ['SomeUnknownBot/1.0', 'UNKNOWN_INTERFACE'],
    ])('detects "%s" as %s', (ua, expected) => {
        expect(getBrowserName(ua)).toBe(expected);
    });

    it('prioritises Firefox over Chrome when both strings are present', () => {
        expect(getBrowserName('Firefox/120.0 Chrome/120.0')).toBe('MOZILLA_FIREFOX');
    });
});

// ─── getOSName ────────────────────────────────────────────────────────────────

describe('getOSName', () => {
    it.each([
        ['Mozilla/5.0 (Windows NT 10.0; Win64; x64)', 'WIN_NT'],
        ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', 'DARWIN'],
        ['Mozilla/5.0 (X11; Linux x86_64)', 'LINUX_X86_64'],
        ['Mozilla/5.0 (Linux; Android 13; Pixel 7)', 'ANDROID'],
        ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)', 'IOS'],
        ['Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X)', 'IOS'],
        ['SomeFuturePlatform/1.0', 'UNIDENTIFIED_OS'],
    ])('detects "%s" as %s', (ua, expected) => {
        expect(getOSName(ua)).toBe(expected);
    });
});

// ─── maskIp ───────────────────────────────────────────────────────────────────

describe('maskIp', () => {
    // Every address it is given is an IPv4 drawn from utils/boot-origin.js, whose tests hold them
    // to that form (LUMN-122): the placeholders and the IPv6 branch it had are gone with the lookup.
    it('masks the last two octets of an IPv4 address, and keeps the first two', () => {
        expect(maskIp('82.64.12.34')).toBe('82.64.███.███');
        expect(maskIp('1.128.0.255')).toBe('1.128.███.███');
    });
});

// ─── toGibsonLocation ─────────────────────────────────────────────────────────

/** An origin as utils/boot-origin.js draws it. */
const origin = (overrides) => ({
    query: '78.192.1.2',
    city: 'Paris',
    countryCode: 'FR',
    region: 'IDF',
    as: 'AS12322 Free',
    ...overrides,
});

describe('toGibsonLocation', () => {
    it('names a country by its zone, the city aside', () => {
        expect(toGibsonLocation(origin({ city: 'Lyon' })).location).toBe('EUROPEAN SPRAWL / PARIS CONURB');
        expect(toGibsonLocation(origin({ countryCode: 'JP', city: 'Tokyo' })).location).toBe(
            'PACIFIC RIM / CHIBA CITY',
        );
        expect(toGibsonLocation(origin({ countryCode: 'TR', city: 'Ankara' })).location).toBe(
            'ISTANBUL RELAY / BOSPHORUS NODE',
        );
    });

    it.each([
        ['NY', 'New York', 'BAMA SPRAWL / NEW YORK CONURB'],
        ['GA', 'Atlanta', 'BAMA SPRAWL / ATLANTA CONURB'],
        ['CA', 'Los Angeles', 'PACIFIC SPRAWL / LOS ANGELES GRID'],
        ['AZ', 'Phoenix', 'PACIFIC SPRAWL / PHOENIX GRID'],
        ['IL', 'Chicago', 'MIDLANDS GRID / CHICAGO NODE'],
        ['NE', 'Omaha', 'MIDLANDS GRID / OMAHA NODE'],
        ['AK', 'Anchorage', 'AMERICAN SPRAWL / ANCHORAGE SECTOR'],
    ])('places the United States by state: %s', (region, city, location) => {
        expect(toGibsonLocation(origin({ countryCode: 'US', region, city })).location).toBe(location);
    });

    it('shows the carrier behind its AS number, upper-cased', () => {
        expect(toGibsonLocation(origin({ as: 'AS3215 Orange' })).carrier).toBe('ORANGE');
        expect(toGibsonLocation(origin({ as: 'AS701 Verizon Business' })).carrier).toBe('VERIZON BUSINESS');
    });

    it('masks the address', () => {
        expect(toGibsonLocation(origin({ query: '86.128.7.9' })).maskedIp).toBe('86.128.███.███');
    });
});

describe('the states of the Sprawl', () => {
    // Data, like the table of origins: taking a state out of a list sends its origins to another
    // branch, still a place, and no rule can tell. The sizes are pinned, so that a change to a list
    // is a decision rather than an accident.
    it('lists twenty states in the east, five in the west, ten in the midwest', () => {
        expect(EAST).toHaveLength(20);
        expect(WEST).toHaveLength(5);
        expect(MIDWEST).toHaveLength(10);
    });

    it('lists each state once, in one list only', () => {
        const all = [...EAST, ...WEST, ...MIDWEST];

        expect(new Set(all).size).toBe(all.length);
        expect(all.every((state) => /^[A-Z]{2}$/.test(state))).toBe(true);
    });

    it('cannot be changed at run time', () => {
        expect([EAST, WEST, MIDWEST].every(Object.isFrozen)).toBe(true);
    });
});
