// E2E preflight — verifies that the local Playwright run would test what it claims to test.
// Usage: node scripts/e2e-preflight.js   (called by scripts/check.sh before the Playwright step)
//
// Detects, never repairs: a stale build or a missing browser makes the audit refuse to give a
// verdict, with the exact command to run. An audit that declines to answer is worth more than
// one that answers wrong — see LUMN-12.
//
// Three preconditions:
//   1. public/hot exists      → the Vite dev server it names must be listening
//   2. public/hot is absent   → public/build/manifest.json must be newer than every build input
//   3. always                 → the Chromium build the installed Playwright expects must be on disk

import { spawnSync } from 'node:child_process';
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { createConnection } from 'node:net';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

// Everything Vite reads when it builds. Blade views belong here: Tailwind scans them for class
// names, so editing a view can change the compiled CSS. package-lock.json belongs here too: a
// dependency update changes the bundle without touching a single source file.
export const BUILD_INPUTS = ['resources', 'vite.config.js', 'package-lock.json'];

const LISTEN_TIMEOUT_MS = 1500;
const MAX_LISTED_FILES = 5;

/**
 * Lists every regular file under the given roots, recursively, with its modification time.
 *
 * @param {string} root
 * @param {string[]} inputs  paths relative to root, files or directories
 * @returns {{path: string, mtimeMs: number}[]}
 */
export function listInputs(root, inputs) {
    const files = [];
    const visit = (absolute) => {
        if (!existsSync(absolute)) {
            return;
        }
        const stats = statSync(absolute);
        if (stats.isDirectory()) {
            for (const entry of readdirSync(absolute)) {
                visit(join(absolute, entry));
            }
            return;
        }
        files.push({ path: relative(root, absolute), mtimeMs: stats.mtimeMs });
    };
    inputs.forEach((input) => visit(join(root, input)));
    return files;
}

/**
 * Returns the inputs modified after the build, newest first.
 *
 * @param {number} manifestMtimeMs
 * @param {{path: string, mtimeMs: number}[]} inputs
 * @returns {string[]}
 */
export function findStaleInputs(manifestMtimeMs, inputs) {
    return inputs
        .filter((input) => input.mtimeMs > manifestMtimeMs)
        .sort((a, b) => b.mtimeMs - a.mtimeMs)
        .map((input) => input.path);
}

/**
 * Extracts the install directories from `playwright install --dry-run` output.
 *
 * @param {string} output
 * @returns {string[]}
 */
export function parseInstallLocations(output) {
    return [...output.matchAll(/^\s*Install location:\s*(\S.*?)\s*$/gm)].map((match) => match[1]);
}

/**
 * Resolves true when something accepts a TCP connection at the URL's host and port.
 *
 * A TCP probe rather than an HTTP request: the dev server runs HTTPS on a local certificate that
 * Node does not trust. A certificate error would still prove the server is alive, but telling it
 * apart from a real failure is fragile — a refused connection is the only answer that matters.
 *
 * @param {string} url
 * @param {number} [timeoutMs]
 * @returns {Promise<boolean>}
 */
export function isListening(url, timeoutMs = LISTEN_TIMEOUT_MS) {
    let target;
    try {
        target = new URL(url);
    } catch {
        return Promise.resolve(false);
    }
    const port = Number(target.port) || (target.protocol === 'https:' ? 443 : 80);

    return new Promise((resolve) => {
        const socket = createConnection({ host: target.hostname, port });
        const finish = (result) => {
            socket.destroy();
            resolve(result);
        };
        socket.setTimeout(timeoutMs, () => finish(false));
        socket.once('connect', () => finish(true));
        socket.once('error', () => finish(false));
    });
}

/**
 * Checks preconditions 1 and 2. Returns the problems found, empty when the assets are current.
 *
 * @param {string} root
 * @param {(url: string) => Promise<boolean>} [probe]
 * @returns {Promise<string[]>}
 */
export async function checkAssets(root, probe = isListening) {
    const hotFile = join(root, 'public', 'hot');

    if (existsSync(hotFile)) {
        const url = readFileSync(hotFile, 'utf8').trim();
        if (await probe(url)) {
            return [];
        }
        return [
            `public/hot points at ${url}, but nothing is listening there.\n` +
                '  Laravel would serve every page against a dead dev server, so the pages would be tested without CSS.\n' +
                '  → start Vite with `npm run dev`, or delete public/hot to test the compiled build instead.',
        ];
    }

    const manifest = join(root, 'public', 'build', 'manifest.json');
    if (!existsSync(manifest)) {
        return [
            'No Vite dev server and no compiled build (public/build/manifest.json is missing).\n  → run `npm run build`, or start Vite with `npm run dev`.',
        ];
    }

    const stale = findStaleInputs(statSync(manifest).mtimeMs, listInputs(root, BUILD_INPUTS));
    if (stale.length === 0) {
        return [];
    }

    const shown = stale.slice(0, MAX_LISTED_FILES).map((path) => `    ${path}`);
    if (stale.length > MAX_LISTED_FILES) {
        shown.push(`    … and ${stale.length - MAX_LISTED_FILES} more`);
    }
    return [
        `The compiled build is older than ${stale.length} of its inputs, and no Vite dev server is running.\n` +
            "  The suite would test yesterday's assets and could pass while today's are broken.\n" +
            `${shown.join('\n')}\n` +
            '  → run `npm run build`, or start Vite with `npm run dev`.',
    ];
}

/**
 * Checks precondition 3 from the dry-run output. Returns the problems found.
 *
 * Nothing ties the npm version of Playwright to the browser on disk: a dependency update that
 * moves Playwright passes CI — whose E2E job installs the browser itself — and breaks the next
 * local run, possibly days later.
 *
 * @param {string} dryRunOutput
 * @param {(path: string) => boolean} [exists]
 * @returns {string[]}
 */
export function checkBrowsers(dryRunOutput, exists = existsSync) {
    const locations = parseInstallLocations(dryRunOutput);
    if (locations.length === 0) {
        return [
            'Could not read the browser locations from `playwright install --dry-run chromium`.\n  → check that `npm ci` has been run.',
        ];
    }

    const missing = locations.filter((location) => !exists(location));
    if (missing.length === 0) {
        return [];
    }
    return [
        'The installed Playwright expects a browser build that is not on disk:\n' +
            `${missing.map((location) => `    ${location}`).join('\n')}\n` +
            '  → run `npx playwright install chromium`.',
    ];
}

/**
 * Runs every check. Returns the problems found, empty when the E2E suite can be trusted.
 *
 * @param {string} root
 * @returns {Promise<string[]>}
 */
export async function preflight(root) {
    const dryRun = spawnSync(join(root, 'node_modules', '.bin', 'playwright'), ['install', '--dry-run', 'chromium'], {
        cwd: root,
        encoding: 'utf8',
    });

    return [...(await checkAssets(root)), ...checkBrowsers(`${dryRun.stdout ?? ''}${dryRun.stderr ?? ''}`)];
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    const root = fileURLToPath(new URL('..', import.meta.url));
    const problems = await preflight(root);

    if (problems.length > 0) {
        console.error('E2E preflight refused to run the suite:\n');
        problems.forEach((problem) => console.error(`• ${problem}\n`));
        process.exit(1);
    }
}
