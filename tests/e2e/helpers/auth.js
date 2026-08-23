import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

/**
 * One isolated admin session per group of specs that loads admin pages.
 *
 * Any page load CONSUMES the pending flash messages of its server-side session.
 * Specs sharing a session therefore steal each other's flashes and fail
 * intermittently under fullyParallel — including read-only specs that submit
 * nothing at all, which is why the axe-core scans need their own.
 */
export const ADMIN_AUTH_FILE = path.join(__dirname, '../.auth/admin.json');
export const ADMIN_EDITOR_AUTH_FILE = path.join(__dirname, '../.auth/admin-editor.json');
export const ADMIN_A11Y_AUTH_FILE = path.join(__dirname, '../.auth/admin-a11y.json');
export const ADMIN_IDENTITY_AUTH_FILE = path.join(__dirname, '../.auth/admin-identity.json');
