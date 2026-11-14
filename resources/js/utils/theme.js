/**
 * The site's themes, and the visitor's choice of one (LUMN-124).
 *
 * themes.json is the one list: the layouts, and the script that restores a theme before the first
 * paint, read it too, through config/themes.php. Its first theme is the default — the one a page
 * is served in, and the one a visitor goes back to when the stored choice is gone.
 *
 * ThemeSwitcher.vue is glue: choosing, persisting and following other tabs are here, on the model
 * of utils/motion.js. The theme a page shows is read on <html>, where the anti-FOUC script put it —
 * never from storage, which may refuse to be read.
 */
import THEMES from '../themes.json';
import { storeQuietly, watchStoredKey } from './storage.js';

export { THEMES };

export const VALID_THEMES = THEMES.map((theme) => theme.id);

export const DEFAULT_THEME = VALID_THEMES[0];

export const THEME_STORAGE_KEY = 'theme';

export function isValidTheme(id) {
    return VALID_THEMES.includes(id);
}

/**
 * Apply a theme to the document without persisting it to localStorage.
 * Kept separate from the write so storage-event listeners can call it
 * without echoing the change back to the originating tab.
 */
export function applyTheme(id) {
    if (!isValidTheme(id)) {
        throw new RangeError(`Not a theme of the site: ${String(id)}.`);
    }
    document.documentElement.setAttribute('data-theme', id);
    const favicon = document.getElementById('favicon');
    if (favicon) favicon.href = THEMES.find((theme) => theme.id === id).favicon;
}

/**
 * The theme the page shows, as <html> carries it; the default when it carries none the site knows.
 *
 * @param {{ getAttribute: (name: string) => string | null }} root
 */
export function currentTheme(root) {
    const id = root.getAttribute('data-theme');
    return isValidTheme(id) ? id : DEFAULT_THEME;
}

/**
 * Applies the visitor's choice, then tries to keep it for the next pages. Storage may be refused —
 * a private window, blocked site data, a full quota — and reaching it alone can throw: the page
 * still shows the theme chosen, it is only forgotten afterwards.
 *
 * @param {unknown} id
 * @param {{ storage: () => { setItem: (key: string, value: string) => void } }} where
 * @returns {string} the theme applied
 */
export function chooseTheme(id, { storage }) {
    // Refused by applyTheme before anything is touched: an unknown theme is neither shown nor kept.
    applyTheme(id);
    storeQuietly(storage, THEME_STORAGE_KEY, id);
    return id;
}

/**
 * Follows a theme chosen — or cleared — in another tab (utils/storage.js says how other tabs are
 * heard). A cleared storage takes the choice with it, and the page goes back to the default, as its
 * next load would (Laurent's decision, 2026-11-14). A value that is no theme of the site is no
 * choice either.
 *
 * @param {{ view: { addEventListener: Function, removeEventListener: Function, localStorage?: object }, onChange: (id: string) => void }} where
 * @returns {() => void} how to stop following
 */
export function watchTheme({ view, onChange }) {
    return watchStoredKey({
        view,
        key: THEME_STORAGE_KEY,
        onValue: (value) => {
            const id = isValidTheme(value) ? value : DEFAULT_THEME;
            applyTheme(id);
            onChange(id);
        },
    });
}
