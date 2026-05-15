export const VALID_THEMES = ['sprawl', 'steampunk', 'neon-noir'];

export function isValidTheme(id) {
    return VALID_THEMES.includes(id);
}

/**
 * Apply a theme to the document without persisting it to localStorage.
 * Kept separate from the write so storage-event listeners can call it
 * without echoing the change back to the originating tab.
 */
export function applyTheme(id) {
    document.documentElement.setAttribute('data-theme', id);
    const favicon = document.getElementById('favicon');
    if (favicon) favicon.href = `/favicons/favicon-${id}.svg`;
}
