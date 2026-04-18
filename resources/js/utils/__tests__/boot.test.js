import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { getBrowserName, getOSName, maskIp, toGibsonLocation, fetchGeoData } from '../boot';

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
    it('masks the last two octets of an IPv4 address', () => {
        expect(maskIp('82.64.12.34')).toBe('82.64.███.███');
    });

    it('keeps the first two octets unchanged', () => {
        expect(maskIp('192.168.1.100')).toBe('192.168.███.███');
    });

    it('masks the host portion of an IPv6 address', () => {
        expect(maskIp('2a01:e0a:1ac:abcd::1')).toBe('2a01:e0a:████:████:████:████');
    });

    it('returns a placeholder for null', () => {
        expect(maskIp(null)).toBe('███.███.███.███');
    });

    it('returns a placeholder for undefined', () => {
        expect(maskIp(undefined)).toBe('███.███.███.███');
    });

    it('returns a placeholder for an empty string', () => {
        expect(maskIp('')).toBe('███.███.███.███');
    });
});

// ─── toGibsonLocation ─────────────────────────────────────────────────────────

describe('toGibsonLocation', () => {
    it('returns the fallback for null data', () => {
        const result = toGibsonLocation(null);
        expect(result.location).toBe('UNCHARTED GRID / ORIGIN MASKED');
        expect(result.maskedIp).toBe('███.███.███.███');
        expect(result.carrier).toBe('UNKNOWN UPLINK');
    });

    it('returns the fallback when the API signals an error', () => {
        expect(toGibsonLocation({ error: true }).location).toBe('UNCHARTED GRID / ORIGIN MASKED');
    });

    // ip-api.com field names: query, countryCode, region, as
    it('maps France to EUROPEAN SPRAWL / PARIS CONURB', () => {
        const { location } = toGibsonLocation({
            query: '1.2.3.4',
            countryCode: 'FR',
            city: 'Paris',
            as: 'AS12322 Free SAS',
        });
        expect(location).toBe('EUROPEAN SPRAWL / PARIS CONURB');
    });

    it('maps Japan to PACIFIC RIM / CHIBA CITY (Neuromancer reference)', () => {
        const { location } = toGibsonLocation({ query: '1.2.3.4', countryCode: 'JP', city: 'Tokyo', as: '' });
        expect(location).toBe('PACIFIC RIM / CHIBA CITY');
    });

    it('maps Turkey to ISTANBUL RELAY / BOSPHORUS NODE (Neuromancer reference)', () => {
        const { location } = toGibsonLocation({ query: '1.2.3.4', countryCode: 'TR', city: 'Istanbul', as: '' });
        expect(location).toBe('ISTANBUL RELAY / BOSPHORUS NODE');
    });

    it('maps US East Coast states to BAMA SPRAWL', () => {
        const { location } = toGibsonLocation({
            query: '1.2.3.4',
            countryCode: 'US',
            region: 'NY',
            city: 'New York',
            as: '',
        });
        expect(location).toBe('BAMA SPRAWL / NEW YORK CONURB');
    });

    it('maps US West Coast states to PACIFIC SPRAWL', () => {
        const { location } = toGibsonLocation({
            query: '1.2.3.4',
            countryCode: 'US',
            region: 'CA',
            city: 'Los Angeles',
            as: '',
        });
        expect(location).toBe('PACIFIC SPRAWL / LOS ANGELES GRID');
    });

    it('maps US Midwest states to MIDLANDS GRID', () => {
        const { location } = toGibsonLocation({
            query: '1.2.3.4',
            countryCode: 'US',
            region: 'IL',
            city: 'Chicago',
            as: '',
        });
        expect(location).toBe('MIDLANDS GRID / CHICAGO NODE');
    });

    it('maps unmapped US states to AMERICAN SPRAWL', () => {
        const { location } = toGibsonLocation({
            query: '1.2.3.4',
            countryCode: 'US',
            region: 'AK',
            city: 'Anchorage',
            as: '',
        });
        expect(location).toBe('AMERICAN SPRAWL / ANCHORAGE SECTOR');
    });

    it('falls back to UNCHARTED GRID for unknown country codes', () => {
        const { location } = toGibsonLocation({ query: '1.2.3.4', countryCode: 'ZZ', city: 'Nowhere', as: '' });
        expect(location).toBe('UNCHARTED GRID / NOWHERE NODE');
    });

    it('strips the ASN prefix from the carrier name', () => {
        const { carrier } = toGibsonLocation({
            query: '1.2.3.4',
            countryCode: 'FR',
            city: 'Paris',
            as: 'AS12322 Free SAS',
        });
        expect(carrier).toBe('FREE SAS');
    });

    it('truncates carrier names longer than 28 characters', () => {
        const { carrier } = toGibsonLocation({
            query: '1.2.3.4',
            countryCode: 'FR',
            city: 'Paris',
            as: 'AS9999 A Very Long ISP Name That Goes On Forever',
        });
        expect(carrier.length).toBeLessThanOrEqual(28);
    });

    it('uses UNKNOWN CARRIER when org is missing', () => {
        const { carrier } = toGibsonLocation({ query: '1.2.3.4', countryCode: 'FR', city: 'Paris' });
        expect(carrier).toBe('UNKNOWN CARRIER');
    });

    it('masks the IP address', () => {
        const { maskedIp } = toGibsonLocation({ query: '82.64.12.34', countryCode: 'FR', city: 'Paris', as: '' });
        expect(maskedIp).toBe('82.64.███.███');
    });
});

// ─── fetchGeoData ─────────────────────────────────────────────────────────────

describe('fetchGeoData', () => {
    beforeEach(() => {
        vi.stubGlobal('fetch', vi.fn());
        // Ziggy's route() helper is injected by Blade at runtime — stub it for unit tests
        vi.stubGlobal('route', vi.fn().mockReturnValue('/api/geo'));
        sessionStorage.clear(); // prevent geo cache from leaking between tests
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        sessionStorage.clear();
    });

    it('returns parsed JSON on a successful fetch', async () => {
        const payload = { ip: '1.2.3.4', country_code: 'FR', city: 'Paris' };
        fetch.mockResolvedValue({ json: () => Promise.resolve(payload) });

        const result = await fetchGeoData();
        expect(result).toEqual(payload);
        expect(fetch).toHaveBeenCalledWith('/api/geo', expect.objectContaining({ signal: expect.any(AbortSignal) }));
    });

    it('returns null on a network error', async () => {
        fetch.mockRejectedValue(new Error('Network failure'));
        expect(await fetchGeoData()).toBeNull();
    });

    it('returns null when the request is aborted', async () => {
        fetch.mockRejectedValue(new DOMException('Aborted', 'AbortError'));
        expect(await fetchGeoData()).toBeNull();
    });
});
