/** @typedef {{ code: string, name: string, flag: string }} Locale */

/** @type {Locale[]} */
export const LOCALES = [
    { code: 'bg', name: 'Български', flag: '🇧🇬' },
    { code: 'cs', name: 'Čeština', flag: '🇨🇿' },
    { code: 'da', name: 'Dansk', flag: '🇩🇰' },
    { code: 'de', name: 'Deutsch', flag: '🇩🇪' },
    { code: 'el', name: 'Ελληνικά', flag: '🇬🇷' },
    { code: 'en', name: 'English', flag: '🇬🇧' },
    { code: 'es', name: 'Español', flag: '🇪🇸' },
    { code: 'et', name: 'Eesti', flag: '🇪🇪' },
    { code: 'fi', name: 'Suomi', flag: '🇫🇮' },
    { code: 'fr', name: 'Français', flag: '🇫🇷' },
    { code: 'ga', name: 'Gaeilge', flag: '🇮🇪' },
    { code: 'hr', name: 'Hrvatski', flag: '🇭🇷' },
    { code: 'hu', name: 'Magyar', flag: '🇭🇺' },
    { code: 'it', name: 'Italiano', flag: '🇮🇹' },
    { code: 'lt', name: 'Lietuvių', flag: '🇱🇹' },
    { code: 'lv', name: 'Latviešu', flag: '🇱🇻' },
    { code: 'mt', name: 'Malti', flag: '🇲🇹' },
    { code: 'nl', name: 'Nederlands', flag: '🇳🇱' },
    { code: 'pl', name: 'Polski', flag: '🇵🇱' },
    { code: 'pt', name: 'Português', flag: '🇵🇹' },
    { code: 'ro', name: 'Română', flag: '🇷🇴' },
    { code: 'sk', name: 'Slovenčina', flag: '🇸🇰' },
    { code: 'sl', name: 'Slovenščina', flag: '🇸🇮' },
    { code: 'sv', name: 'Svenska', flag: '🇸🇪' },
];

/**
 * Builds a URL for the given locale by replacing the first path segment.
 * Preserves nested paths and query strings.
 *
 * @param {string} pathname  e.g. '/fr/cv'
 * @param {string} lang      e.g. 'de'
 * @param {string} search    e.g. '?foo=bar'
 * @returns {string}
 */
export function urlFor(pathname, lang, search = '') {
    const parts = pathname.split('/');
    parts[1] = lang;
    return parts.join('/') + search;
}

/**
 * Filters a locale list by name or code, case-insensitively.
 * Returns the full list when the query is empty or whitespace-only.
 *
 * @param {Locale[]} locales
 * @param {string}   query
 * @returns {Locale[]}
 */
export function filterLocales(locales, query) {
    const q = query.trim().toLowerCase();
    if (!q) return locales;
    return locales.filter((l) => l.name.toLowerCase().includes(q) || l.code.includes(q));
}

/**
 * Persist the chosen locale to localStorage so other tabs can react via the
 * `storage` event and navigate to the equivalent URL in the new locale.
 */
export function writeLocaleToStorage(code) {
    localStorage.setItem('locale', code);
}
