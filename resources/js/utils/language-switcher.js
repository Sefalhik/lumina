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
 * Persist the chosen locale to localStorage so other tabs can react via the
 * `storage` event and navigate to the equivalent URL in the new locale.
 */
export function writeLocaleToStorage(code) {
    localStorage.setItem('locale', code);
}
