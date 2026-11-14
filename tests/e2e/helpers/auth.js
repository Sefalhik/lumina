/**
 * One isolated admin session per group of specs that loads admin pages.
 *
 * Any page load CONSUMES the pending flash messages of its server-side session.
 * Specs sharing a session therefore steal each other's flashes and fail
 * intermittently under fullyParallel — including read-only specs that submit
 * nothing at all, which is why the axe-core scans need their own.
 *
 * These are group names, not files. A spec asks for one with
 * `test.use({ adminSession: ADMIN_SESSION })`, and helpers/test.js hands it the
 * session that auth.setup.js created for that group on the spec's own worker
 * slot (LUMN-111). A spec never sets storageState itself: a path given there
 * would bypass the slot, and reach a session that does not exist in its database.
 */
export const ADMIN_SESSION = 'admin';
export const ADMIN_EDITOR_SESSION = 'admin-editor';
export const ADMIN_A11Y_SESSION = 'admin-a11y';
export const ADMIN_IDENTITY_SESSION = 'admin-identity';
export const ADMIN_EXPERIENCES_SESSION = 'admin-experiences';
export const ADMIN_CSP_SESSION = 'admin-csp';
export const ADMIN_SNOW_SESSION = 'admin-snow';
export const ADMIN_MOTION_SESSION = 'admin-motion';

/** Every group, for auth.setup.js. */
export const ADMIN_SESSIONS = [
    ADMIN_SESSION, // general admin specs — homepage form
    ADMIN_EDITOR_SESSION, // skills editor
    ADMIN_IDENTITY_SESSION, // site identity form
    ADMIN_A11Y_SESSION, // axe-core scans — submit nothing, but still consume flashes
    ADMIN_EXPERIENCES_SESSION, // CV experiences CRUD
    ADMIN_CSP_SESSION, // content security policy scan — submits nothing, still consumes flashes
    ADMIN_SNOW_SESSION, // the dead channel's snow on the admin pages — loads them, consumes flashes
    ADMIN_MOTION_SESSION, // the motion switch on the admin pages — loads them, consumes flashes
];
