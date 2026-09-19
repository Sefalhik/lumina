// @vitest-environment node
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mkdtempSync, mkdirSync, writeFileSync, utimesSync, rmSync } from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import {
    BUILD_INPUTS,
    checkAssets,
    checkBrowsers,
    findStaleInputs,
    isListening,
    listInputs,
    parseInstallLocations,
} from '../e2e-preflight.js';

const DAY = 24 * 60 * 60;
const NOW = Math.floor(Date.now() / 1000);

const alive = async () => true;
const dead = async () => false;

// Writes a file under root and sets its modification time, in seconds since the epoch.
function touch(root, path, mtime) {
    const absolute = join(root, path);
    mkdirSync(join(absolute, '..'), { recursive: true });
    writeFileSync(absolute, '');
    utimesSync(absolute, mtime, mtime);
}

// Output of `playwright install --dry-run chromium`, trimmed to the lines the parser reads.
const DRY_RUN = `Chrome for Testing 153.0.8010.12 (playwright chromium v1243)
  Install location:    /cache/ms-playwright/chromium-1243
  Download url:        https://cdn.playwright.dev/builds/cft/153.0.8010.12/linux64/chrome-linux64.zip

FFmpeg (playwright ffmpeg v1011)
  Install location:    /cache/ms-playwright/ffmpeg-1011
  Download url:        https://cdn.playwright.dev/dbazure/download/playwright/builds/ffmpeg/1011/ffmpeg-linux.zip

Chrome Headless Shell 153.0.8010.12 (playwright chromium-headless-shell v1243)
  Install location:    /cache/ms-playwright/chromium_headless_shell-1243
`;

let root;

beforeEach(() => {
    root = mkdtempSync(join(tmpdir(), 'e2e-preflight-'));
});

afterEach(() => {
    rmSync(root, { recursive: true, force: true });
});

describe('BUILD_INPUTS', () => {
    it('covers views, lockfile and Vite config, not only JS and CSS', () => {
        // resources/ carries the Blade views Tailwind scans; the lockfile carries dependency updates.
        expect(BUILD_INPUTS).toEqual(['resources', 'vite.config.js', 'package-lock.json']);
    });
});

describe('listInputs', () => {
    it('walks directories recursively and skips missing inputs', () => {
        touch(root, 'resources/views/home.blade.php', NOW);
        touch(root, 'resources/js/i18n/de.json', NOW);
        touch(root, 'vite.config.js', NOW);

        const paths = listInputs(root, BUILD_INPUTS).map((input) => input.path);

        expect(paths.sort()).toEqual(['resources/js/i18n/de.json', 'resources/views/home.blade.php', 'vite.config.js']);
    });
});

describe('findStaleInputs', () => {
    it('returns only inputs newer than the manifest, newest first', () => {
        const manifest = 1000;
        const inputs = [
            { path: 'old.js', mtimeMs: 500 },
            { path: 'newer.js', mtimeMs: 1500 },
            { path: 'newest.js', mtimeMs: 2000 },
            { path: 'same.js', mtimeMs: 1000 },
        ];

        expect(findStaleInputs(manifest, inputs)).toEqual(['newest.js', 'newer.js']);
    });
});

// Criteria 1, 2 and 3 of LUMN-12 — the stale build.
describe('checkAssets without a Vite dev server', () => {
    it('passes when the build is newer than every input', async () => {
        touch(root, 'resources/css/app.css', NOW - DAY);
        touch(root, 'public/build/manifest.json', NOW);

        expect(await checkAssets(root, dead)).toEqual([]);
    });

    it('refuses when a Blade view changed after the build, and says to rebuild', async () => {
        touch(root, 'public/build/manifest.json', NOW - DAY);
        touch(root, 'resources/views/home.blade.php', NOW);

        const problems = await checkAssets(root, dead);

        expect(problems).toHaveLength(1);
        expect(problems[0]).toContain('resources/views/home.blade.php');
        expect(problems[0]).toContain('npm run build');
        expect(problems[0]).toContain('npm run dev');
    });

    it('refuses when only the lockfile changed — a dependency update', async () => {
        touch(root, 'resources/css/app.css', NOW - 2 * DAY);
        touch(root, 'public/build/manifest.json', NOW - DAY);
        touch(root, 'package-lock.json', NOW);

        const problems = await checkAssets(root, dead);

        expect(problems).toHaveLength(1);
        expect(problems[0]).toContain('package-lock.json');
    });

    it('lists five stale files at most, then counts the rest', async () => {
        touch(root, 'public/build/manifest.json', NOW - DAY);
        for (let i = 0; i < 8; i++) {
            touch(root, `resources/js/i18n/l${i}.json`, NOW - i);
        }

        const [problem] = await checkAssets(root, dead);

        expect(problem).toContain('older than 8 of its inputs');
        expect(problem).toContain('… and 3 more');
    });

    it('refuses when there is no build at all', async () => {
        touch(root, 'resources/css/app.css', NOW);

        const [problem] = await checkAssets(root, dead);

        expect(problem).toContain('manifest.json is missing');
        expect(problem).toContain('npm run build');
    });
});

// Criterion 3 — Vite running means the assets are served live, whatever the build's age.
describe('checkAssets with public/hot', () => {
    it('passes when the dev server is listening, even over a stale build', async () => {
        touch(root, 'public/build/manifest.json', NOW - DAY);
        touch(root, 'resources/views/home.blade.php', NOW);
        writeFileSync(join(root, 'public/hot'), 'https://dev.cardascia-it.org:5173\n');

        expect(await checkAssets(root, alive)).toEqual([]);
    });

    it('refuses a hot file left behind by a Vite that has died', async () => {
        mkdirSync(join(root, 'public'), { recursive: true });
        writeFileSync(join(root, 'public/hot'), 'https://dev.cardascia-it.org:5173');

        const [problem] = await checkAssets(root, dead);

        expect(problem).toContain('nothing is listening');
        expect(problem).toContain('delete public/hot');
    });

    it('probes the URL the hot file names, trimmed', async () => {
        mkdirSync(join(root, 'public'), { recursive: true });
        writeFileSync(join(root, 'public/hot'), '  https://example.test:5173\n');
        const probed = [];

        await checkAssets(root, async (url) => {
            probed.push(url);
            return true;
        });

        expect(probed).toEqual(['https://example.test:5173']);
    });
});

describe('isListening', () => {
    let server;

    afterEach(() => new Promise((resolve) => (server ? server.close(() => resolve()) : resolve())));

    it('resolves true when a server accepts connections', async () => {
        server = createServer((socket) => socket.end());
        await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));

        expect(await isListening(`https://127.0.0.1:${server.address().port}`)).toBe(true);
    });

    it('resolves false when the port is closed', async () => {
        server = createServer();
        await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
        const { port } = server.address();
        await new Promise((resolve) => server.close(resolve));
        server = null;

        expect(await isListening(`https://127.0.0.1:${port}`)).toBe(false);
    });

    it('resolves false on a malformed URL instead of throwing', async () => {
        expect(await isListening('not a url')).toBe(false);
    });
});

describe('parseInstallLocations', () => {
    it('extracts every install directory', () => {
        expect(parseInstallLocations(DRY_RUN)).toEqual([
            '/cache/ms-playwright/chromium-1243',
            '/cache/ms-playwright/ffmpeg-1011',
            '/cache/ms-playwright/chromium_headless_shell-1243',
        ]);
    });
});

// Criterion added when LUMN-12 was widened — the Playwright browser binary.
describe('checkBrowsers', () => {
    it('passes when every expected browser is on disk', () => {
        expect(checkBrowsers(DRY_RUN, () => true)).toEqual([]);
    });

    it('refuses when Playwright moved to a build that is not installed, and says how to install it', () => {
        const onDisk = new Set(['/cache/ms-playwright/ffmpeg-1011']);

        const [problem] = checkBrowsers(DRY_RUN, (path) => onDisk.has(path));

        expect(problem).toContain('/cache/ms-playwright/chromium-1243');
        expect(problem).toContain('/cache/ms-playwright/chromium_headless_shell-1243');
        expect(problem).not.toContain('ffmpeg-1011');
        expect(problem).toContain('npx playwright install chromium');
    });

    it('refuses rather than passes when the output cannot be read', () => {
        const [problem] = checkBrowsers('', () => true);

        expect(problem).toContain('Could not read');
    });
});
