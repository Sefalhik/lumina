/**
 * Boot sequence utilities — pure functions and data fetching.
 * Extracted for testability; BootSequence.vue imports from here.
 */

export const STORAGE_KEY = 'boot_sequence_played';

// ─── Browser detection ───────────────────────────────────────────────────────

/** @param {string} [ua] - User-agent string (defaults to navigator.userAgent) */
export function getBrowserName(ua = navigator.userAgent) {
    if (ua.includes('Firefox/')) return 'MOZILLA_FIREFOX';
    if (ua.includes('Edg/')) return 'MS_EDGE';
    if (ua.includes('Chrome/')) return 'CHROMIUM';
    if (ua.includes('Safari/')) return 'WEBKIT_SAFARI';
    return 'UNKNOWN_INTERFACE';
}

/** @param {string} [ua] - User-agent string (defaults to navigator.userAgent) */
export function getOSName(ua = navigator.userAgent) {
    // Mobile checks must come before their desktop counterparts:
    // Android UAs contain "Linux", iOS UAs contain "Mac OS X"
    if (ua.includes('Android')) return 'ANDROID';
    if (ua.includes('iPhone') || ua.includes('iPad')) return 'IOS';
    if (ua.includes('Windows NT')) return 'WIN_NT';
    if (ua.includes('Mac OS X')) return 'DARWIN';
    if (ua.includes('Linux')) return 'LINUX_X86_64';
    return 'UNIDENTIFIED_OS';
}

// ─── IP masking ──────────────────────────────────────────────────────────────

/** Masks the host portion of an IP address for display. */
export function maskIp(ip) {
    if (!ip) return '███.███.███.███';
    if (ip.includes(':')) {
        // IPv6: keep first two groups
        const parts = ip.split(':');
        return parts.slice(0, 2).join(':') + ':████:████:████:████';
    }
    // IPv4: keep first two octets
    return ip.replace(/^(\d+\.\d+)\.\d+\.\d+$/, '$1.███.███');
}

// ─── Gibson universe geo mapping ─────────────────────────────────────────────

/**
 * Maps real-world geo data (from ipapi.co) to Gibson Sprawl nomenclature.
 *
 * @param {object|null} data - Response from ipapi.co, or null on fetch failure
 * @returns {{ location: string, maskedIp: string, carrier: string }}
 */
export function toGibsonLocation(data) {
    const fallback = {
        location: 'UNCHARTED GRID / ORIGIN MASKED',
        maskedIp: '███.███.███.███',
        carrier: 'UNKNOWN UPLINK',
    };
    if (!data || data.error) return fallback;

    // ip-api.com field names (server-side proxy normalises nothing — mapping lives here)
    const { query: ip, city, countryCode: cc, region: rc, as: org } = data;
    const carrier = org
        ? org
              .replace(/^AS\d+\s+/i, '')
              .toUpperCase()
              .substring(0, 28)
        : 'UNKNOWN CARRIER';
    const maskedIp = maskIp(ip);

    const zones = {
        // ── North America ────────────────────────────────────────────────────
        // The Sprawl / BAMA = Boston–Atlanta Metropolitan Axis (Neuromancer)
        US: () => {
            const east = [
                'NY',
                'NJ',
                'MA',
                'CT',
                'RI',
                'PA',
                'MD',
                'DC',
                'VA',
                'DE',
                'NH',
                'VT',
                'ME',
                'NC',
                'SC',
                'GA',
                'FL',
                'AL',
                'MS',
                'TN',
            ];
            const west = ['CA', 'OR', 'WA', 'NV', 'AZ'];
            const mid = ['IL', 'OH', 'MI', 'IN', 'WI', 'MN', 'MO', 'IA', 'KS', 'NE'];
            if (east.includes(rc)) return `BAMA SPRAWL / ${(city ?? 'NODE').toUpperCase()} CONURB`;
            if (west.includes(rc)) return `PACIFIC SPRAWL / ${(city ?? 'NODE').toUpperCase()} GRID`;
            if (mid.includes(rc)) return `MIDLANDS GRID / ${(city ?? 'NODE').toUpperCase()} NODE`;
            return `AMERICAN SPRAWL / ${(city ?? 'NODE').toUpperCase()} SECTOR`;
        },
        CA: 'NORTHERN TERRITORIES / TORONTO NODE',
        MX: 'AZTLAN REACH / MEXICO CITY SPRAWL',
        BR: 'SOUTHERN REACH / RIO SPRAWL',
        AR: 'SOUTHERN REACH / BUENOS AIRES NODE',
        CL: 'SOUTHERN REACH / SANTIAGO NODE',
        CO: 'SOUTHERN REACH / BOGOTA NODE',

        // ── Europe ───────────────────────────────────────────────────────────
        FR: 'EUROPEAN SPRAWL / PARIS CONURB',
        GB: 'EUROPEAN SPRAWL / LONDON SPRAWL',
        DE: 'EUROPEAN SPRAWL / FRANKFURT NODE',
        IT: 'EUROPEAN SPRAWL / MILAN GRID',
        ES: 'EUROPEAN SPRAWL / BARCELONA NODE',
        PT: 'EUROPEAN SPRAWL / LISBON NODE',
        NL: 'EUROPEAN SPRAWL / AMSTERDAM RELAY',
        BE: 'EUROPEAN SPRAWL / ANTWERP NODE',
        CH: 'EUROPEAN SPRAWL / BERNE NEUTRAL ZONE',
        AT: 'EUROPEAN SPRAWL / WIEN NODE',
        SE: 'NORDIC GRID / STOCKHOLM NODE',
        NO: 'NORDIC GRID / OSLO NODE',
        FI: 'NORDIC GRID / HELSINKI SECTOR', // Screaming Fist theatre
        DK: 'NORDIC GRID / COPENHAGEN RELAY',
        PL: 'EASTERN BLOC REMNANT / WARSAW NODE',
        CZ: 'EASTERN BLOC REMNANT / PRAGUE NODE',
        HU: 'EASTERN BLOC REMNANT / BUDAPEST NODE',
        RO: 'EASTERN BLOC REMNANT / BUCHAREST NODE',
        UA: 'EASTERN BLOC REMNANT / KYIV NODE',
        RU: 'EASTERN BLOC REMNANT / MOSCOW SPRAWL',

        // ── Turkey — explicit Gibson reference (Neuromancer ch.8) ────────────
        TR: 'ISTANBUL RELAY / BOSPHORUS NODE',

        // ── Middle East ──────────────────────────────────────────────────────
        AE: 'ARABIAN REACH / DUBAI FREEPORT',
        SA: 'ARABIAN REACH / RIYADH NODE',
        IL: 'ARABIAN REACH / TEL AVIV NODE',
        IR: 'ARABIAN REACH / TEHRAN NODE',

        // ── Asia Pacific ─────────────────────────────────────────────────────
        JP: 'PACIFIC RIM / CHIBA CITY', // Neuromancer opens here
        KR: 'PACIFIC RIM / SEOUL NODE',
        CN: 'PACIFIC RIM / HONG KONG FREEPORT',
        HK: 'PACIFIC RIM / HONG KONG FREEPORT',
        TW: 'PACIFIC RIM / TAIPEI NODE',
        SG: 'PACIFIC RIM / SINGAPORE FREEPORT',
        TH: 'PACIFIC RIM / BANGKOK NODE',
        VN: 'PACIFIC RIM / SAIGON NODE',
        IN: 'SUBCONTINENTAL GRID / BOMBAY NODE',
        AU: 'PACIFIC RIM / SYDNEY SPRAWL',
        NZ: 'PACIFIC RIM / AUCKLAND NODE',

        // ── Africa ───────────────────────────────────────────────────────────
        ZA: 'AFRICAN GRID / CAPE TOWN RELAY',
        NG: 'AFRICAN GRID / LAGOS NODE',
        EG: 'AFRICAN GRID / CAIRO NODE',
        MA: 'AFRICAN GRID / CASABLANCA NODE',
        KE: 'AFRICAN GRID / NAIROBI NODE',
    };

    const entry = zones[cc];
    const location =
        typeof entry === 'function' ? entry() : (entry ?? `UNCHARTED GRID / ${(city ?? 'UNKNOWN').toUpperCase()} NODE`);

    return { location, maskedIp, carrier };
}

// ─── Geo fetch ───────────────────────────────────────────────────────────────

/**
 * Fetches geo data from ipapi.co. Returns null on any failure or timeout.
 * Data is only used client-side and never stored server-side.
 */
const GEO_CACHE_KEY = 'geo_data';

export async function fetchGeoData() {
    const cached = sessionStorage.getItem(GEO_CACHE_KEY);
    if (cached) return JSON.parse(cached);

    try {
        const controller = new AbortController();
        const tid = setTimeout(() => controller.abort(), 3000);
        const res = await fetch(route('api.geo'), { signal: controller.signal });
        clearTimeout(tid);
        const data = await res.json();
        if (data && !data.error) sessionStorage.setItem(GEO_CACHE_KEY, JSON.stringify(data));
        return data;
    } catch {
        return null;
    }
}
