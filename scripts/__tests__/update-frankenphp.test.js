// @vitest-environment node
import { describe, it, expect, afterEach } from 'vitest';
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import {
    accessSync,
    chmodSync,
    constants,
    existsSync,
    mkdirSync,
    mkdtempSync,
    readFileSync,
    readdirSync,
    rmSync,
    symlinkSync,
    writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { delimiter, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { stripVTControlCharacters as plain } from 'node:util';

// The real script is run, never a copy of its logic — in a directory that holds a stand-in for
// ./frankenphp, with a PATH that contains nothing but what the test put there: the real tools the
// script needs, and stand-ins for curl, uname, date and sudo. Nothing reaches the network, nothing
// is installed, and a tool the script starts calling without it being listed here fails the suite.
const SCRIPT = fileURLToPath(new URL('../update-frankenphp.sh', import.meta.url));

const REAL_TOOLS = ['bash', 'grep', 'head', 'jq', 'mktemp', 'rm', 'sed', 'cut', 'chmod', 'mv'];
const HASH_TOOLS = ['sha256sum', 'shasum'];

const DAY = 24 * 60 * 60;
// A fixed clock: the boundary of the release age is tested to the second.
const NOW = Date.UTC(2026, 9, 10, 12, 0, 0) / 1000;

const REPO = 'php/frankenphp';
const API_URL = `https://api.github.com/repos/${REPO}/releases/latest`;
const ASSET = 'frankenphp-linux-x86_64';
const CURRENT = 'v1.12.7';
const LATEST = 'v1.13.0';

// What `./frankenphp --version` prints is all the script reads from the binary, so a shell script
// that prints it is a binary as far as the script can tell.
const binaryOf = (version) => `#!/bin/sh\necho "FrankenPHP ${version} PHP 8.5.11 Caddy v2.11.7"\n`;
const CURRENT_BINARY = binaryOf(CURRENT);
const NEW_BINARY = binaryOf(LATEST);
const OTHER_BINARY = binaryOf('v9.9.9');

const sha256 = (bytes) => createHash('sha256').update(bytes).digest('hex');
const iso = (epoch) => new Date(epoch * 1000).toISOString().replace('.000Z', 'Z');
const urlOf = (tag, name, repo = REPO) => `https://github.com/${repo}/releases/download/${tag}/${name}`;

function isExecutable(path) {
    try {
        accessSync(path, constants.X_OK);
        return true;
    } catch {
        return false;
    }
}

function locate(tool) {
    const found = process.env.PATH.split(delimiter)
        .map((directory) => join(directory, tool))
        .find(isExecutable);
    if (!found) {
        throw new Error(`${tool} is needed to run scripts/update-frankenphp.sh and was not found on PATH.`);
    }
    return found;
}

const BASH = locate('bash');
const CP = locate('cp');
const CAT = locate('cat');

// Every stand-in appends its name and arguments to the log, one call per line.
const LOG = `{ printf '%s' "\${0##*/}"; printf '\\x1f%s' "$@"; printf '\\n'; } >> "$STUB_LOG"`;

const STANDINS = {
    // Behaves like curl where the script depends on it: the body goes to -o, the status code is
    // only printed for -w, a redirect is only followed with -L, and an HTTP error only fails the
    // command with --fail. Without those, a test could not tell `curl -L` from `curl` — and the
    // script needs the first to download and must not have it to ask.
    curl: `#!${BASH}
${LOG}
out=""; url=""; follow=0; fail=0; write_out=0
while (( $# )); do
  case "$1" in
    -o) out="$2"; shift ;;
    -w) write_out=1; shift ;;
    --location) follow=1 ;;
    --fail) fail=1 ;;
    --*) ;;
    -*) [[ "$1" == *L* ]] && follow=1; [[ "$1" == *f* ]] && fail=1 ;;
    *) url="$1" ;;
  esac
  shift
done
if [[ "$url" == https://api.github.com/* ]]; then
  (( STUB_API_EXIT == 0 )) || exit "$STUB_API_EXIT"
  code="$STUB_API_CODE"; body="$STUB_DIR/api.json"
  if [[ "$code" == 3* ]] && (( follow )); then code=200; body="$STUB_DIR/redirect.json"; fi
  if [[ -n "$out" ]]; then ${CP} "$body" "$out"; else ${CAT} "$body"; fi
  (( write_out )) && printf '%s' "$code"
  exit 0
fi
(( STUB_DOWNLOAD_EXIT == 0 )) || exit "$STUB_DOWNLOAD_EXIT"
# GitHub serves every release asset behind a redirect: without -L, curl saves an empty body.
(( follow )) || { : > "$out"; exit 0; }
if [[ "$STUB_DOWNLOAD_CODE" != 200 ]]; then
  (( fail )) && exit 22
  printf '<html>%s</html>' "$STUB_DOWNLOAD_CODE" > "$out"
  exit 0
fi
${CP} "$STUB_DIR/asset" "$out"
`,
    uname: `#!${BASH}
case "$1" in
  -s) printf '%s\\n' "$STUB_UNAME_S" ;;
  -m) printf '%s\\n' "$STUB_UNAME_M" ;;
  *) exit 1 ;;
esac
`,
    date: `#!${BASH}
[[ "$*" == "+%s" ]] || exit 1
printf '%s\\n' "$STUB_NOW"
`,
    sudo: `#!${BASH}
${LOG}
exit "$STUB_SUDO_EXIT"
`,
};

const sandboxes = [];

afterEach(() => {
    sandboxes.splice(0).forEach((root) => rmSync(root, { recursive: true, force: true }));
});

/**
 * Builds a project directory, its PATH and its temporary directory.
 *
 * @param {{current?: string|null, npmrc?: string|null, hashTools?: string[]}} [options]
 */
function sandbox({
    current = CURRENT_BINARY,
    npmrc = '# A comment.\nmin-release-age=3\n',
    hashTools = HASH_TOOLS,
} = {}) {
    const root = mkdtempSync(join(tmpdir(), 'update-frankenphp-'));
    sandboxes.push(root);
    const box = {
        project: join(root, 'project'),
        bin: join(root, 'bin'),
        tmp: join(root, 'tmp'),
        stub: join(root, 'stub'),
        log: join(root, 'calls.log'),
    };
    [box.project, box.bin, box.tmp, box.stub].forEach((directory) => mkdirSync(directory));

    [...REAL_TOOLS, ...hashTools].forEach((tool) => symlinkSync(locate(tool), join(box.bin, tool)));
    Object.entries(STANDINS).forEach(([tool, source]) => {
        writeFileSync(join(box.bin, tool), source);
        chmodSync(join(box.bin, tool), 0o755);
    });

    if (current !== null) {
        writeFileSync(join(box.project, 'frankenphp'), current);
        chmodSync(join(box.project, 'frankenphp'), 0o755);
    }
    if (npmrc !== null) {
        writeFileSync(join(box.project, '.npmrc'), npmrc);
    }
    return box;
}

function anAsset(name, { tag = LATEST, bytes = NEW_BINARY, ...overrides } = {}) {
    return { name, browser_download_url: urlOf(tag, name), digest: `sha256:${sha256(bytes)}`, ...overrides };
}

/**
 * A release as the GitHub API describes it, reduced to what the script reads. The binary of this
 * platform sits between two others: taking the first asset, or its digest, is a failure.
 * A key overridden with `undefined` is absent from the answer.
 *
 * @param {{tag?: string, age?: number, asset?: object}} [options]
 */
function aRelease({ tag = LATEST, age = 10 * DAY, asset = {}, ...overrides } = {}) {
    return {
        tag_name: tag,
        published_at: iso(NOW - age),
        assets: [
            anAsset('frankenphp-linux-aarch64', { tag, bytes: OTHER_BINARY }),
            anAsset(ASSET, { tag, ...asset }),
            anAsset('frankenphp-mac-arm64', { tag, bytes: OTHER_BINARY }),
            anAsset('frankenphp-mac-x86_64', { tag, bytes: OTHER_BINARY }),
        ],
        ...overrides,
    };
}

/**
 * Runs the script in a sandbox and reports what it did.
 *
 * @param {object} box       a sandbox
 * @param {object} [options] what the outside world answers
 */
function run(
    box,
    {
        args = ['--force'],
        input = '',
        release = aRelease(),
        apiBody = JSON.stringify(release),
        apiCode = 200,
        apiExit = 0,
        redirectsTo = aRelease(),
        served = NEW_BINARY,
        downloadCode = 200,
        downloadExit = 0,
        machine = ['Linux', 'x86_64'],
        sudoExit = 0,
        locale = null,
        tmp = box.tmp,
    } = {},
) {
    writeFileSync(join(box.stub, 'api.json'), apiBody);
    writeFileSync(join(box.stub, 'redirect.json'), JSON.stringify(redirectsTo));
    writeFileSync(join(box.stub, 'asset'), served);

    const result = spawnSync(join(box.bin, 'bash'), [SCRIPT, ...args], {
        cwd: box.project,
        input,
        encoding: 'utf8',
        env: {
            PATH: box.bin,
            TMPDIR: tmp,
            ...(locale ? { LC_ALL: locale, LANG: locale } : {}),
            STUB_DIR: box.stub,
            STUB_LOG: box.log,
            STUB_NOW: String(NOW),
            STUB_API_CODE: String(apiCode),
            STUB_API_EXIT: String(apiExit),
            STUB_DOWNLOAD_CODE: String(downloadCode),
            STUB_DOWNLOAD_EXIT: String(downloadExit),
            STUB_UNAME_S: machine[0],
            STUB_UNAME_M: machine[1],
            STUB_SUDO_EXIT: String(sudoExit),
        },
    });

    const calls = existsSync(box.log)
        ? readFileSync(box.log, 'utf8')
              .split('\n')
              .filter((line) => line !== '')
              .map((line) => line.split('\x1f'))
        : [];
    const requests = calls.filter(([tool]) => tool === 'curl');
    const binary = join(box.project, 'frankenphp');

    return {
        status: result.status,
        // Without the colours: what is asserted is what is said, not how it is lit.
        stdout: plain(result.stdout),
        stderr: plain(result.stderr),
        requests,
        asked: requests.filter((call) => call.at(-1).startsWith('https://api.github.com/')),
        downloads: requests.filter((call) => !call.at(-1).startsWith('https://api.github.com/')),
        elevated: calls.filter(([tool]) => tool === 'sudo'),
        binary: existsSync(binary) ? readFileSync(binary, 'utf8') : null,
        executable: isExecutable(binary),
        leftovers: readdirSync(box.tmp),
    };
}

// The binary in place is the one that was there, nothing was run as root, nothing is left behind.
function expectNothingInstalled(result, before = CURRENT_BINARY) {
    expect(result.binary).toBe(before);
    expect(result.elevated).toEqual([]);
    expect(result.leftovers).toEqual([]);
}

// A refusal: said on stderr, exit 1, and nothing installed. `set -e` alone would exit 1 in silence.
function expectRefused(result, message) {
    expect(result.stderr).toContain('Error:');
    expect(result.stderr).toContain(message);
    expect(result.status).toBe(1);
    expectNothingInstalled(result);
}

function expectInstalled(result, bytes = NEW_BINARY) {
    expect(result.stderr).toBe('');
    expect(result.status).toBe(0);
    expect(result.binary).toBe(bytes);
    expect(result.executable).toBe(true);
    expect(result.elevated).toEqual([['sudo', 'setcap', 'cap_net_bind_service=+ep', './frankenphp']]);
    expect(result.leftovers).toEqual([]);
}

describe('update-frankenphp.sh — the nominal update', () => {
    it('replaces the binary with the verified download and restores its capability', () => {
        const result = run(sandbox());

        expectInstalled(result);
        expect(result.stdout).toContain(`SHA-256 verified`);
        expect(result.stdout).toContain(sha256(NEW_BINARY));
        expect(result.stdout).toContain('cap_net_bind_service restored');
    });

    it('says how to restore the capability when it could not, without failing the update', () => {
        const result = run(sandbox(), { sudoExit: 1 });

        expect(result.status).toBe(0);
        expect(result.binary).toBe(NEW_BINARY);
        expect(result.stdout).toContain('could not restore cap_net_bind_service');
    });

    it('does nothing when the binary is already the latest release', () => {
        // Young, and without a digest: neither matters when there is nothing to install.
        const release = aRelease({ age: 60, asset: { digest: undefined } });
        const result = run(sandbox({ current: NEW_BINARY }), { release });

        expect(result.status).toBe(0);
        expect(result.stdout).toContain(`already up to date`);
        expect(result.downloads).toEqual([]);
        expectNothingInstalled(result, NEW_BINARY);
    });

    it('refuses to run where there is no binary to update', () => {
        const result = run(sandbox({ current: null }));

        expect(result.status).toBe(1);
        expect(result.stderr).toContain('./frankenphp not found');
        expect(result.requests).toEqual([]);
        expect(result.binary).toBeNull();
    });
});

describe('update-frankenphp.sh — where the binary comes from', () => {
    it('asks php/frankenphp for its latest release, and downloads from that repository', () => {
        const result = run(sandbox());

        expectInstalled(result);
        expect(result.asked.map((call) => call.at(-1))).toEqual([API_URL]);
        expect(result.downloads.map((call) => call.at(-1))).toEqual([urlOf(LATEST, ASSET)]);
    });

    it('never names dunglas/frankenphp, the name the project left', () => {
        // The old name answers with a redirect, so the script kept working after the move and
        // nothing said it was relying on one. This is the test that would have said so.
        const result = run(sandbox());

        expect(result.requests.length).toBeGreaterThan(0);
        expect(result.requests.flat().filter((argument) => argument.includes('dunglas'))).toEqual([]);
    });

    it.each([301, 302, 307, 308])('refuses a %i instead of following it: the repository has moved', (apiCode) => {
        // The stand-in follows the redirect when asked to, and what lies behind it is a perfectly
        // valid release: with -L on that request, this update goes through.
        const apiBody = JSON.stringify({ message: 'Moved Permanently', url: 'https://api.github.com/repositories/1' });
        const result = run(sandbox(), { apiCode, apiBody, redirectsTo: aRelease() });

        expectRefused(result, `GitHub answered ${apiCode} for ${REPO}: the repository has moved.`);
        expect(result.stderr).toContain('change REPO in scripts/update-frankenphp.sh');
        expect(result.downloads).toEqual([]);
    });

    it.each([204, 403, 404, 429, 500, 503, '000'])('refuses an answer with status %s', (apiCode) => {
        // The body is a valid release on purpose: the status alone has to be enough.
        const result = run(sandbox(), { apiCode });

        expectRefused(result, `GitHub answered ${apiCode} for ${API_URL}.`);
        expect(result.downloads).toEqual([]);
    });

    it('refuses when GitHub cannot be reached', () => {
        const result = run(sandbox(), { apiExit: 6 });

        expectRefused(result, 'GitHub could not be reached');
        expect(result.downloads).toEqual([]);
    });

    it.each([
        ['a page that is not JSON', '<html>rate limited</html>'],
        ['half a release', JSON.stringify(aRelease()).slice(0, 40)],
        ['a list', '[]'],
        ['a bare string', '"v1.13.0"'],
        ['a number', '200'],
    ])('refuses %s as an answer', (_, apiBody) => {
        const result = run(sandbox(), { apiBody });

        expectRefused(result, "GitHub's answer is not a release this script can read.");
        expect(result.downloads).toEqual([]);
    });

    it.each([
        ['no tag', undefined],
        ['a null tag', null],
        ['an empty tag', ''],
        ['a tag that is not a version', 'latest'],
        ['a version without its v', '1.13.0'],
        ['a two-part version', 'v1.13'],
        ['a pre-release', 'v1.13.0-rc.1'],
        ['a version followed by something else', 'v1.13.0/../../x'],
        ['a version preceded by something else', 'frankenphp-v1.13.0'],
        ['a number', 113],
    ])('refuses a release with %s', (_, tag) => {
        // Everything else in the release agrees with that tag, its download address included: with
        // addresses left on another version, the address check refuses first and this one is never
        // reached — which is how it went untested at first.
        const result = run(sandbox(), { release: aRelease({ tag, tag_name: tag }) });

        expectRefused(result, 'the latest release carries no version this script reads');
        expect(result.downloads).toEqual([]);
    });

    it('refuses an empty answer', () => {
        const result = run(sandbox(), { apiBody: '' });

        expectRefused(result, 'the latest release carries no version this script reads');
        expect(result.downloads).toEqual([]);
    });

    it.each([
        ['the repository the project left', urlOf(LATEST, ASSET, 'dunglas/frankenphp')],
        ['another repository', urlOf(LATEST, ASSET, 'someone/frankenphp')],
        ['another host', `https://github.com.example.org/${REPO}/releases/download/${LATEST}/${ASSET}`],
        ['plain http', urlOf(LATEST, ASSET).replace('https://', 'http://')],
        ['another release', urlOf('v1.12.0', ASSET)],
        ['the binary of another platform', urlOf(LATEST, 'frankenphp-linux-aarch64')],
        ['a query string', `${urlOf(LATEST, ASSET)}?token=1`],
        ['no address at all', undefined],
        ['a null address', null],
        ['a number', 42],
    ])('refuses a binary served from %s', (_, address) => {
        const release = aRelease({ asset: { browser_download_url: address } });
        const result = run(sandbox(), { release });

        expectRefused(result, `does not serve ${ASSET} from where it is expected`);
        expect(result.stderr).toContain(`expected:  ${urlOf(LATEST, ASSET)}`);
        expect(result.downloads).toEqual([]);
    });

    it.each([
        ['no asset list', { assets: undefined }, 0],
        ['an empty asset list', { assets: [] }, 0],
        ['an asset list that is not one', { assets: 'frankenphp-linux-x86_64' }, 0],
        ['only the binaries of other platforms', { assets: [anAsset('frankenphp-mac-arm64')] }, 0],
        ['two binaries of that name', { assets: [anAsset(ASSET), anAsset(ASSET, { bytes: OTHER_BINARY })] }, 2],
    ])('refuses a release with %s', (_, overrides, found) => {
        const result = run(sandbox(), { release: aRelease(overrides) });

        expectRefused(result, `expected exactly one binary named ${ASSET} in release ${LATEST}, found ${found}.`);
        expect(result.downloads).toEqual([]);
    });

    it('takes the binary of its own platform, with the digest of that binary', () => {
        // On arm64 the right file is the one the release lists first, with another digest.
        const result = run(sandbox(), { machine: ['Linux', 'aarch64'], served: OTHER_BINARY });

        expectInstalled(result, OTHER_BINARY);
        expect(result.downloads.map((call) => call.at(-1))).toEqual([urlOf(LATEST, 'frankenphp-linux-aarch64')]);
    });

    it('does not accept, on one platform, a file that carries the digest of another', () => {
        const result = run(sandbox(), { machine: ['Linux', 'aarch64'], served: NEW_BINARY });

        expectRefused(result, 'the downloaded file is not the frankenphp-linux-aarch64');
    });
});

describe('update-frankenphp.sh — the release age', () => {
    it('holds back a release one second short of the delay, and says until when', () => {
        const result = run(sandbox(), { release: aRelease({ age: 3 * DAY - 1 }) });

        expect(result.status).toBe(0);
        expect(result.stderr).toBe('');
        expect(result.stdout).toContain(`FrankenPHP ${LATEST} is held back`);
        expect(result.stdout).toContain(iso(NOW + 1));
        expect(result.stdout).toContain('a release waits 3 days (./.npmrc)');
        expect(result.stdout).toContain('--min-release-age=0');
        expect(result.downloads).toEqual([]);
        expectNothingInstalled(result);
    });

    it('installs a release that is exactly as old as the delay', () => {
        expectInstalled(run(sandbox(), { release: aRelease({ age: 3 * DAY }) }));
    });

    it('reads the delay from .npmrc rather than holding a number of its own', () => {
        const release = aRelease({ age: 4 * DAY });

        expectInstalled(run(sandbox({ npmrc: 'min-release-age=3\n' }), { release }));

        const held = run(sandbox({ npmrc: 'min-release-age=5\n' }), { release });
        expect(held.status).toBe(0);
        expect(held.stdout).toContain('a release waits 5 days (./.npmrc)');
        expect(held.stdout).toContain(iso(NOW + DAY));
        expect(held.downloads).toEqual([]);
        expectNothingInstalled(held);
    });

    it('installs at once when .npmrc sets the delay to zero', () => {
        expectInstalled(run(sandbox({ npmrc: 'min-release-age=0\n' }), { release: aRelease({ age: 1 }) }));
    });

    it('holds back a release dated in the future', () => {
        const result = run(sandbox({ npmrc: 'min-release-age=0\n' }), { release: aRelease({ age: -1 }) });

        expect(result.status).toBe(0);
        expect(result.stdout).toContain('is held back');
        expect(result.downloads).toEqual([]);
        expectNothingInstalled(result);
    });

    it('does not let --force shorten the wait', () => {
        const result = run(sandbox(), { args: ['--force'], release: aRelease({ age: DAY }) });

        expect(result.status).toBe(0);
        expect(result.stdout).toContain('is held back');
        expect(result.downloads).toEqual([]);
        expectNothingInstalled(result);
    });

    it('lets --min-release-age replace the delay for one run, in either direction', () => {
        const young = aRelease({ age: 60 });
        expectInstalled(run(sandbox(), { args: ['--force', '--min-release-age=0'], release: young }));

        const held = run(sandbox(), { args: ['--force', '--min-release-age=10'], release: aRelease({ age: 5 * DAY }) });
        expect(held.status).toBe(0);
        expect(held.stdout).toContain('a release waits 10 days (--min-release-age)');
        expect(held.downloads).toEqual([]);
        expectNothingInstalled(held);
    });

    it('does not need .npmrc when the delay is given on the command line', () => {
        expectInstalled(run(sandbox({ npmrc: null }), { args: ['--force', '--min-release-age=3'] }));
    });

    it('refuses to run without .npmrc, before asking GitHub anything', () => {
        const result = run(sandbox({ npmrc: null }));

        expectRefused(result, './.npmrc not found');
        expect(result.requests).toEqual([]);
    });

    it.each([
        ['no min-release-age', 'save-exact=true\n'],
        ['an empty file', ''],
        ['an empty value', 'min-release-age=\n'],
        ['a word', 'min-release-age=three\n'],
        ['a unit', 'min-release-age=3 days\n'],
        ['a negative number', 'min-release-age=-1\n'],
        ['a decimal', 'min-release-age=3.5\n'],
        ['a leading zero', 'min-release-age=03\n'],
        ['a number of five digits', 'min-release-age=10000\n'],
        ['spaces around the sign', 'min-release-age = 3\n'],
        ['a trailing space', 'min-release-age=3 \n'],
        ['a Windows line ending', 'min-release-age=3\r\n'],
        ['only a commented declaration', '# min-release-age=3\n'],
        ['two declarations', 'min-release-age=3\nmin-release-age=3\n'],
    ])('refuses a .npmrc with %s, before asking GitHub anything', (_, npmrc) => {
        // A young release: reading any of these as zero would install it.
        const result = run(sandbox({ npmrc }), { release: aRelease({ age: 60 }) });

        expectRefused(result, 'the release age given by ./.npmrc is not a number of days');
        expect(result.requests).toEqual([]);
    });

    it.each(['', 'three', '-1', '3.5', '03', '3 days', ' 3', '10000', '0x3', '3,5'])(
        'refuses --min-release-age=%j, before asking GitHub anything',
        (days) => {
            const result = run(sandbox(), { args: ['--force', `--min-release-age=${days}`] });

            expectRefused(result, 'the release age given by --min-release-age is not a number of days');
            expect(result.requests).toEqual([]);
        },
    );

    it.each(['--frce', '--min-release-age', '--min-release-age 0', '--force=1', '-f', '0', ''])(
        'refuses the unknown argument %j instead of ignoring it',
        (argument) => {
            const result = run(sandbox(), { args: ['--force', argument], release: aRelease({ age: 60 }) });

            expectRefused(result, `unknown argument: ${argument}`);
            expect(result.requests).toEqual([]);
        },
    );

    it.each([
        ['no publication date', undefined],
        ['a null date', null],
        ['an empty date', ''],
        ['a word', 'yesterday'],
        ['a date without a time', '2026-10-04'],
        ['a date without its T', '2026-10-04 14:01:03Z'],
        ['an offset instead of Z', '2026-10-04T14:01:03+02:00'],
        ['fractions of a second', '2026-10-04T14:01:03.000Z'],
        ['a day that does not exist', '2026-02-30T00:00:00Z'],
        ['a month that does not exist', '2026-13-01T00:00:00Z'],
        ['a number', 1790000000],
        ['a list', ['2026-10-04T14:01:03Z']],
    ])('refuses a release with %s: it cannot be shown to be old enough', (_, publishedAt) => {
        const result = run(sandbox(), { release: aRelease({ published_at: publishedAt }) });

        expectRefused(result, `release ${LATEST} carries no publication date this script reads`);
        expect(result.downloads).toEqual([]);
    });
});

describe('update-frankenphp.sh — the checksum', () => {
    it('refuses a download that differs by one byte, and leaves the binary in place', () => {
        const served = `${NEW_BINARY} `;
        const result = run(sandbox(), { served });

        expectRefused(result, `the downloaded file is not the ${ASSET} that release ${LATEST} publishes.`);
        expect(result.stderr).toContain(`expected SHA-256: ${sha256(NEW_BINARY)}`);
        expect(result.stderr).toContain(`computed SHA-256: ${sha256(served)}`);
        expect(result.stderr).toContain('./frankenphp was left untouched.');
        // It was downloaded: the refusal comes from the comparison, not from an earlier guard.
        expect(result.downloads).toHaveLength(1);
    });

    it('refuses an empty download', () => {
        const result = run(sandbox(), { served: '' });

        expectRefused(result, 'the downloaded file is not the');
        expect(result.downloads).toHaveLength(1);
    });

    it('does not let --force skip the verification', () => {
        const result = run(sandbox(), { args: ['--force'], served: OTHER_BINARY });

        expectRefused(result, 'the downloaded file is not the');
    });

    it.each([403, 404, 500])('refuses an error page served with status %i instead of the binary', (downloadCode) => {
        // Without --fail, curl saves the page and exits 0. The checksum would still refuse it, and
        // blame the file: the message has to name the download.
        const result = run(sandbox(), { downloadCode });

        expectRefused(result, `the download of ${urlOf(LATEST, ASSET)} failed.`);
        expect(result.stderr).not.toContain('SHA-256');
    });

    it('refuses when the download is cut short', () => {
        const result = run(sandbox(), { downloadExit: 56 });

        expectRefused(result, `the download of ${urlOf(LATEST, ASSET)} failed.`);
    });

    it.each([
        ['no digest', undefined],
        ['a null digest', null],
        ['an empty digest', ''],
        ['a digest with no algorithm', sha256(NEW_BINARY)],
        ['another algorithm', `sha512:${sha256(NEW_BINARY)}`],
        ['an algorithm in capitals', `SHA256:${sha256(NEW_BINARY)}`],
        ['a digest one character short', `sha256:${sha256(NEW_BINARY).slice(1)}`],
        ['a digest one character long', `sha256:${sha256(NEW_BINARY)}0`],
        ['a digest in capitals', `sha256:${sha256(NEW_BINARY).toUpperCase()}`],
        ['a digest that is not hexadecimal', `sha256:${'g'.repeat(64)}`],
        ['a digest followed by something else', `sha256:${sha256(NEW_BINARY)} ${ASSET}`],
        ['a digest preceded by something else', `not-sha256:${sha256(NEW_BINARY)}`],
        ['a number', 256],
    ])('refuses a release with %s, without downloading anything', (_, digest) => {
        // The file served is the right one: only what the release says about it is wrong.
        const result = run(sandbox(), { release: aRelease({ asset: { digest } }) });

        expectRefused(result, `release ${LATEST} publishes no SHA-256 this script reads for ${ASSET}`);
        expect(result.stderr).toContain('Nothing was downloaded');
        expect(result.downloads).toEqual([]);
    });

    // Linux ships sha256sum, macOS ships shasum, and a machine that has both hides which one ran.
    it.each(HASH_TOOLS)('verifies with %s where it is the only tool there is', (tool) => {
        expectInstalled(run(sandbox({ hashTools: [tool] })));

        const result = run(sandbox({ hashTools: [tool] }), { served: OTHER_BINARY });
        expectRefused(result, 'the downloaded file is not the');
        expect(result.stderr).toContain(`computed SHA-256: ${sha256(OTHER_BINARY)}`);
    });

    it('refuses to download what it has no tool to verify', () => {
        const result = run(sandbox({ hashTools: [] }));

        expectRefused(result, 'neither sha256sum nor shasum is installed.');
        expect(result.downloads).toEqual([]);
    });
});

describe('update-frankenphp.sh — the confirmation', () => {
    it.each(['y\n', 'Y\n', 'yes\n'])('installs after %j', (input) => {
        expectInstalled(run(sandbox(), { args: [], input }));
    });

    it.each(['n\n', '\n', 'maybe\n'])('installs nothing after %j', (input) => {
        const result = run(sandbox(), { args: [], input });

        expect(result.status).toBe(0);
        expect(result.stdout).toContain('Aborted.');
        expect(result.downloads).toEqual([]);
        expectNothingInstalled(result);
    });

    it('installs nothing when nobody is there to answer', () => {
        const result = run(sandbox(), { args: [], input: '' });

        expect(result.downloads).toEqual([]);
        expectNothingInstalled(result);
    });
});

// What follows was added on 2026-10-10, after the 97 mutations chosen by hand had all been killed.
// scripts/mutate-lines.js then deleted each line of the script in turn: 36 deletions out of 193
// went unnoticed, and these are the ones that mattered.
describe('update-frankenphp.sh — what the mechanical pass found', () => {
    it('stops at "already up to date", even when the release could be installed', () => {
        // The first version of this case used a release too young to install and without a digest,
        // "to show neither matters". It showed the opposite of what it claimed: with the `exit`
        // after the message deleted, the script went on, held the release back, and the test
        // stayed green. An old release with a valid digest is the one that would be downloaded.
        const result = run(sandbox({ current: NEW_BINARY }));

        expect(result.status).toBe(0);
        expect(result.stdout).toContain('FrankenPHP is already up to date (v1.13.0)');
        expect(result.downloads).toEqual([]);
        expectNothingInstalled(result, NEW_BINARY);
    });

    it.each([
        ['prints no version', '#!/bin/sh\necho "FrankenPHP, some build"\n'],
        ['prints nothing', '#!/bin/sh\n'],
        ['fails', '#!/bin/sh\nexit 3\n'],
    ])('refuses to update a binary that %s, before asking GitHub anything', (_, current) => {
        // Going on with an empty version would compare it with the latest, find them different,
        // and install over a file nobody could identify.
        const result = run(sandbox({ current }));

        expect(result.status).toBe(1);
        expect(result.stderr).toContain('could not determine current FrankenPHP version');
        expect(result.requests).toEqual([]);
        expect(result.binary).toBe(current);
    });

    it('says one thing, and stops, when there is no binary at all', () => {
        const result = run(sandbox({ current: null }));

        expect(result.stderr).toContain('./frankenphp not found');
        expect(result.stderr).not.toContain('could not determine');
    });

    it.each([
        [['Linux', 'aarch64'], 'frankenphp-linux-aarch64'],
        [['Darwin', 'arm64'], 'frankenphp-mac-arm64'],
        [['Darwin', 'x86_64'], 'frankenphp-mac-x86_64'],
    ])('downloads the binary named for %j', (machine, asset) => {
        const result = run(sandbox(), { machine, served: OTHER_BINARY });

        expectInstalled(result, OTHER_BINARY);
        expect(result.downloads.map((call) => call.at(-1))).toEqual([urlOf(LATEST, asset)]);
    });

    it.each([
        [['Linux', 'riscv64'], 'unsupported architecture: riscv64'],
        [['Linux', 'arm64'], 'unsupported architecture: arm64'],
        [['Darwin', 'aarch64'], 'unsupported architecture: aarch64'],
        [['Darwin', 'ppc'], 'unsupported architecture: ppc'],
        [['FreeBSD', 'amd64'], 'unsupported OS: FreeBSD'],
        [['MINGW64_NT-10.0', 'x86_64'], 'unsupported OS: MINGW64_NT-10.0'],
    ])('refuses to guess a binary for %j, before asking GitHub anything', (machine, message) => {
        const result = run(sandbox(), { machine });

        expect(result.status).toBe(1);
        expect(result.stderr).toContain(message);
        expect(result.requests).toEqual([]);
        expectNothingInstalled(result);
    });

    it('stops at a failure it did not plan for, instead of going on with an empty value', () => {
        // No temporary file can be created. Without `set -e` the script carries on with an empty
        // name where the answer of GitHub should be written.
        const result = run(sandbox(), { tmp: '/nonexistent/tmp' });

        expect(result.status).not.toBe(0);
        expect(result.requests).toEqual([]);
        expect(result.binary).toBe(CURRENT_BINARY);
    });

    it('states a refusal once, then its hints', () => {
        const result = run(sandbox(), { served: `${NEW_BINARY} ` });

        expect(result.stderr.match(/the downloaded file is not the/g)).toHaveLength(1);
        expect(result.stderr.match(/^ {2}→ /gm)).toHaveLength(3);
    });

    it('says what a readable delay looks like when it cannot read one', () => {
        const result = run(sandbox({ npmrc: 'min-release-age=three\n' }));

        expect(result.stderr).toContain('Expected exactly one whole number, as in min-release-age=3.');
        expect(result.stderr).toContain('A delay that cannot be read is not a delay of zero.');
    });
});

describe('update-frankenphp.sh — what it shows', () => {
    it('says what it is about to install, and what it did', () => {
        const { stdout } = run(sandbox());

        expect(stdout).toContain('Checking latest FrankenPHP release…');
        expect(stdout).toContain('FrankenPHP update available');
        expect(stdout).toContain('Current    v1.12.7');
        expect(stdout).toContain('Latest     v1.13.0');
        expect(stdout).toContain(`Published  ${iso(NOW - 10 * DAY)} (10 days ago)`);
        expect(stdout).toContain(`Binary     ${ASSET}`);
        expect(stdout).toContain('Downloading v1.13.0…');
        expect(stdout).toContain('Updated v1.12.7 → v1.13.0');
    });

    it('names the PHP the new binary bundles, not the version of FrankenPHP', () => {
        // "FrankenPHP v1.13.0 PHP 8.5.11": looking for "PHP v…" finds the end of the first word.
        const { stdout } = run(sandbox());

        expect(stdout).toContain('Bundled PHP 8.5.11');
        expect(stdout).not.toContain('Bundled PHP v');
    });

    it('says nothing about PHP when the new binary does not name one, and still succeeds', () => {
        const silent = '#!/bin/sh\necho "FrankenPHP v1.13.0"\n';
        const result = run(sandbox(), {
            served: silent,
            release: aRelease({ asset: { digest: `sha256:${sha256(silent)}` } }),
        });

        expectInstalled(result, silent);
        expect(result.stdout).not.toContain('Bundled');
    });

    it('asks before installing, unless told not to', () => {
        expect(run(sandbox(), { args: [], input: 'n\n' }).stdout).toContain('Proceed? [y/N]');
        expect(run(sandbox(), { args: ['--force'] }).stdout).not.toContain('Proceed?');
    });

    it('says why a release is held back, and what it would take to install it', () => {
        const { stdout } = run(sandbox(), { release: aRelease({ age: DAY }) });

        expect(stdout).toContain('Current     v1.12.7');
        expect(stdout).toContain(`Published   ${iso(NOW - DAY)}`);
        expect(stdout).toContain(
            'A compromised release is usually withdrawn within hours: waiting is what protects from',
        );
        expect(stdout).toContain(
            'one, the checksum cannot. Once its release notes are read, to install it regardless:',
        );
        expect(stdout).toContain('npm run update:frankenphp -- --min-release-age=0');
    });
});

describe('update-frankenphp.sh — run from a UTF-8 locale', () => {
    // It is: the script is typed in a terminal, in French. There, bash reads the ranges `0-9` and
    // `a-f` by collation — they match the digits of other scripts, and é.
    const LOCALE = 'en_US.UTF-8';
    const widens = (range, character) =>
        spawnSync(BASH, ['-c', `pattern='^[${range}]$'; [[ "$1" =~ $pattern ]]`, 'bash', character], {
            env: { LC_ALL: LOCALE },
        }).status === 0;

    it('is tested on a machine where that locale does widen a range, or the tests below prove nothing', () => {
        expect(widens('a-f', 'é')).toBe(true);
        expect(widens('0-9', '３')).toBe(true);
    });

    it.each([
        ['an accented letter', 'é'],
        ['a full-width digit', '３'],
    ])('still refuses a digest holding %s, without downloading anything', (_, character) => {
        const digest = `sha256:${character}${sha256(NEW_BINARY).slice(1)}`;
        const result = run(sandbox(), { locale: LOCALE, release: aRelease({ asset: { digest } }) });

        expectRefused(result, `release ${LATEST} publishes no SHA-256 this script reads for ${ASSET}`);
        expect(result.downloads).toEqual([]);
    });

    it('still refuses a version written with the digits of another script', () => {
        const tag = 'v１.13.0';
        const result = run(sandbox(), { locale: LOCALE, release: aRelease({ tag, tag_name: tag }) });

        expectRefused(result, 'the latest release carries no version this script reads');
        expect(result.downloads).toEqual([]);
    });

    it('still refuses a delay written with the digits of another script', () => {
        const result = run(sandbox({ npmrc: 'min-release-age=３\n' }), { locale: LOCALE });

        expectRefused(result, 'the release age given by ./.npmrc is not a number of days');
        expect(result.requests).toEqual([]);
    });

    it('still installs a release that is in order', () => {
        expectInstalled(run(sandbox(), { locale: LOCALE }));
    });
});
