/**
 * Whether the site's animations run (LUMN-11).
 *
 * WCAG 2.2.2 (level A) asks that every visitor can stop what moves on its own for more than five
 * seconds, decoration included. The rule, in one place:
 *
 * - a choice the visitor made with the switch wins, both ways — kept in localStorage under `motion`,
 *   and carried on <html> as `data-motion` by layouts/partials/restore-preferences.blade.php before
 *   the first paint;
 * - without one, the system's preference: animations stop when it asks for reduced motion.
 *
 * The stylesheet applies the same rule on its own (`when-motion-stopped` in _effects.scss), so that
 * a page without JavaScript, or one whose preference changes during the visit, follows it too. This
 * module is for what only a script can stop — the snow, the boot sequence — and for the switch that
 * shows the state.
 *
 * Only 'on' and 'off' are choices. Anything else — a value nobody wrote, an empty attribute — is no
 * choice, here as in the stylesheet: the preference decides.
 */

import { storeQuietly, watchStoredKey } from './storage.js';

export const MOTION_STORAGE_KEY = 'motion';
export const MOTION_ATTRIBUTE = 'data-motion';
export const REDUCED_MOTION_QUERY = '(prefers-reduced-motion: reduce)';

/**
 * @param {unknown} value
 * @returns {'on' | 'off' | null} the choice it holds, or null when it holds none
 */
export function parseMotionChoice(value) {
    return value === 'on' || value === 'off' ? value : null;
}

/**
 * @param {{ choice: unknown, prefersReduced: boolean }} state
 * @returns {'on' | 'off'}
 */
export function resolveMotion({ choice, prefersReduced }) {
    return parseMotionChoice(choice) ?? (prefersReduced ? 'off' : 'on');
}

/**
 * The state of a page, from where the anti-FOUC script and the switch leave the choice.
 *
 * @param {{ getAttribute: (name: string) => string | null }} root the <html> element
 * @param {{ matches: boolean } | null} media the reduced-motion query; null when the browser cannot
 *   evaluate it — then the preference cannot be known, and animations stop, on the safe side
 * @returns {'on' | 'off'}
 */
export function documentMotion(root, media) {
    return resolveMotion({
        choice: root.getAttribute(MOTION_ATTRIBUTE),
        prefersReduced: media === null ? true : media.matches,
    });
}

/**
 * The reduced-motion query of a window, or null when it has none.
 *
 * @param {{ matchMedia?: (query: string) => { matches: boolean } }} view
 */
export function reducedMotionQuery(view) {
    return typeof view.matchMedia === 'function' ? view.matchMedia(REDUCED_MOTION_QUERY) : null;
}

/**
 * The state of the page a window shows.
 *
 * @param {{ document: { documentElement: { getAttribute: (name: string) => string | null } }, matchMedia?: Function }} view
 * @returns {'on' | 'off'}
 */
export function pageMotion(view) {
    return documentMotion(view.document.documentElement, reducedMotionQuery(view));
}

/**
 * @param {'on' | 'off'} current
 * @returns {'on' | 'off'} the choice that reverses it
 */
export function toggledMotion(current) {
    return current === 'off' ? 'on' : 'off';
}

/**
 * Carries a stored value onto <html>, as the anti-FOUC script does: a choice is set, anything else
 * removes the attribute, and the preference decides again. Used when another tab changes the choice.
 *
 * @param {unknown} value
 * @param {{ setAttribute: Function, removeAttribute: Function }} root
 */
export function applyMotionChoice(value, root) {
    const choice = parseMotionChoice(value);
    if (choice === null) {
        root.removeAttribute(MOTION_ATTRIBUTE);
        return;
    }
    root.setAttribute(MOTION_ATTRIBUTE, choice);
}

/**
 * Records the visitor's choice: on <html> at once, in storage for the next pages.
 *
 * Storage may be refused — a private window, blocked site data — and reading `localStorage` alone
 * can throw. The choice then holds for this page only, and the page still follows it: the switch
 * must not fail because the browser forgets.
 *
 * @param {unknown} choice
 * @param {{ root: { setAttribute: Function }, storage: () => { setItem: (key: string, value: string) => void } }} where
 * @returns {'on' | 'off'}
 */
export function chooseMotion(choice, { root, storage }) {
    const valid = parseMotionChoice(choice);
    if (valid === null) {
        throw new RangeError(`A motion choice is 'on' or 'off', not ${String(choice)}.`);
    }
    root.setAttribute(MOTION_ATTRIBUTE, valid);
    storeQuietly(storage, MOTION_STORAGE_KEY, valid);
    return valid;
}

/**
 * Follows what can change the state under a page once it is shown: the system's preference, and a
 * choice made — or cleared — in another tab (utils/storage.js says how other tabs are heard: a
 * cleared storage takes the choice with it, and the preference decides again). MotionToggle.vue
 * only displays what it reports.
 *
 * @param {{ view: { addEventListener: Function, removeEventListener: Function, matchMedia?: Function, localStorage?: object }, root: { getAttribute: Function, setAttribute: Function, removeAttribute: Function }, onChange: (motion: 'on' | 'off') => void }} where
 * @returns {() => void} how to stop following
 */
export function watchMotion({ view, root, onChange }) {
    const media = reducedMotionQuery(view);

    function report() {
        onChange(documentMotion(root, media));
    }

    const stopFollowingTabs = watchStoredKey({
        view,
        key: MOTION_STORAGE_KEY,
        onValue: (value) => {
            applyMotionChoice(value, root);
            report();
        },
    });
    media?.addEventListener('change', report);

    return () => {
        media?.removeEventListener('change', report);
        stopFollowingTabs();
    };
}
