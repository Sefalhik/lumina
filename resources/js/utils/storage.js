/**
 * The browser's storage, as the islands use it (LUMN-124): one rule for every preference — the
 * theme, the motion choice, the boot sequence's mark.
 *
 * Storage may be refused — a private window, blocked site data, a full quota — and reaching
 * `localStorage` or `sessionStorage` alone can throw. A preference is never worth an error thrown at
 * the page: a refused write is forgotten afterwards, a refused read is told apart from an absent
 * value, and each caller decides what that means for it.
 */

/**
 * @param {() => { getItem: (key: string) => string | null }} storage
 * @param {string} key
 * @returns {string | null | undefined} the value; null when absent; undefined when it cannot be read
 */
export function readStored(storage, key) {
    try {
        return storage().getItem(key);
    } catch {
        return undefined;
    }
}

/**
 * @param {() => { setItem: (key: string, value: string) => void }} storage
 * @param {string} key
 * @param {string} value
 * @returns {boolean} whether it was kept
 */
export function storeQuietly(storage, key, value) {
    try {
        storage().setItem(key, value);
        return true;
    } catch {
        return false;
    }
}

/**
 * Follows a key of localStorage as other tabs change it. A `storage` event names the key that
 * changed, or none when the other tab cleared its storage — the value is gone then, and `onValue`
 * hears null. Events of another storage area (sessionStorage, shared with a frame) are not about it.
 *
 * @param {{ view: { addEventListener: Function, removeEventListener: Function, localStorage?: object }, key: string, onValue: (value: string | null) => void }} where
 * @returns {() => void} how to stop following
 */
export function watchStoredKey({ view, key, onValue }) {
    function fromLocalStorage(event) {
        try {
            return event.storageArea === view.localStorage;
        } catch {
            // A storage the page cannot reach sends it no news.
            return false;
        }
    }

    function onStorage(event) {
        if (event.key !== key && event.key !== null) return;
        if (!fromLocalStorage(event)) return;
        onValue(event.newValue);
    }

    view.addEventListener('storage', onStorage);
    return () => view.removeEventListener('storage', onStorage);
}
