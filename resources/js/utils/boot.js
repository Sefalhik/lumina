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

/**
 * Masks the host portion of an address for display: the first two octets stay. Every address the
 * sequence shows is an IPv4 drawn from utils/boot-origin.js, whose tests hold them to that form.
 */
export function maskIp(ip) {
    return ip.replace(/^(\d+\.\d+)\.\d+\.\d+$/, '$1.███.███');
}

// ─── Gibson universe mapping ─────────────────────────────────────────────────

/**
 * The states of the Sprawl — BAMA, the Boston–Atlanta Metropolitan Axis (Neuromancer) — then the
 * West Coast and the Midwest. Every state listed is reached by an origin of utils/boot-origin.js.
 */
export const EAST = Object.freeze([
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
]);
export const WEST = Object.freeze(['CA', 'OR', 'WA', 'NV', 'AZ']);
export const MIDWEST = Object.freeze(['IL', 'OH', 'MI', 'IN', 'WI', 'MN', 'MO', 'IA', 'KS', 'NE']);

/**
 * Every country an origin can come from, as Gibson would name it. A string, or for the United
 * States a function of the origin's region and city. utils/boot-origin.js draws from these
 * countries and no other: its tests require every zone to be reached, and every origin to land in
 * one — no zone written for nothing, no origin without its place.
 */
export const ZONES = Object.freeze({
    // ── North America ────────────────────────────────────────────────────
    // The Sprawl / BAMA = Boston–Atlanta Metropolitan Axis (Neuromancer)
    US: ({ region, city }) => {
        const node = city.toUpperCase();
        if (EAST.includes(region)) return `BAMA SPRAWL / ${node} CONURB`;
        if (WEST.includes(region)) return `PACIFIC SPRAWL / ${node} GRID`;
        if (MIDWEST.includes(region)) return `MIDLANDS GRID / ${node} NODE`;
        return `AMERICAN SPRAWL / ${node} SECTOR`;
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
});

/**
 * Maps an origin to Gibson Sprawl nomenclature.
 *
 * @param {{ query: string, city: string, countryCode: string, region: string, as: string }} origin
 *   drawn by utils/boot-origin.js (LUMN-122), in the field names of the geolocation API it replaced.
 *   Its tests hold every origin to a zone of ZONES: there is no origin without its place, and so
 *   no fallback here — the masked origin of the network lookup went with it.
 * @returns {{ location: string, maskedIp: string, carrier: string }}
 */
export function toGibsonLocation(origin) {
    const zone = ZONES[origin.countryCode];
    return {
        location: typeof zone === 'function' ? zone(origin) : zone,
        maskedIp: maskIp(origin.query),
        carrier: origin.as.replace(/^AS\d+\s+/, '').toUpperCase(),
    };
}
