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
