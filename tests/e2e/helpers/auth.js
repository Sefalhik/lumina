import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

export const ADMIN_AUTH_FILE        = path.join(__dirname, '../.auth/admin.json');
export const ADMIN_EDITOR_AUTH_FILE = path.join(__dirname, '../.auth/admin-editor.json');
