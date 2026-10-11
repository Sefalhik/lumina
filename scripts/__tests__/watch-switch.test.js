// @vitest-environment node
import { describe, it, expect, afterEach } from 'vitest';
import { spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { JOURNAL, callsIn, fillPath } from './support/sandbox.js';

// scripts/watch-switch.sh measures a real site; here it measures a stand-in for curl that plays a
// site through a switch, one line of a scenario per request for the page:
//
//   <status> <release> <files the page names, comma separated> <files that do not answer>
//
// What is tested is the judgement: which answers count as a broken page, and that none is missed.
const SCRIPT = fileURLToPath(new URL('../watch-switch.sh', import.meta.url));

const CURL = `${JOURNAL}
config='' headers='' output='' url=''
while (( $# )); do
  case "$1" in
    --config) config="$2"; shift ;;
    --dump-header) headers="$2"; shift ;;
    --output) output="$2"; shift ;;
    --max-time|--write-out) shift ;;
    --silent) ;;
    *) url="$1" ;;
  esac
  shift
done
cat "$config" >> "$STUB_DIR/credentials"
if [[ "$url" == */build/assets/* ]]; then
  read -r _ _ _ missing < "$STUB_DIR/tick"
  [[ ",$missing," == *",\${url##*/},"* ]] && printf '404' || printf '200'
  exit 0
fi
count=$(cat "$STUB_DIR/count" 2> /dev/null || echo 0)
count=$((count + 1))
printf '%s' "$count" > "$STUB_DIR/count"
sed -n "\${count}p" "$STUB_DIR/scenario" > "$STUB_DIR/tick"
read -r status release files _ < "$STUB_DIR/tick"
[[ "$status" == 'dead' ]] && exit 7
[[ "$release" != '-' ]] && printf 'HTTP/2 %s\\r\\nx-release: %s\\r\\n\\r\\n' "$status" "$release" > "$headers" || printf 'HTTP/2 %s\\r\\n\\r\\n' "$status" > "$headers"
: > "$output"
for file in \${files//,/ }; do
  [[ "$file" != '-' ]] && printf '<script src="https://site.example/build/assets/%s"></script>\\n' "$file" >> "$output"
done
printf '%s' "$status"
`;

const sandboxes = [];

afterEach(() => {
    sandboxes.splice(0).forEach((root) => rmSync(root, { recursive: true, force: true }));
});

/**
 * @param {string[]} scenario  one line per request for the page
 * @param {{args?: string[], env?: Record<string, string>}} [options]
 */
function watch(scenario, { args, env = {} } = {}) {
    const root = mkdtempSync(join(tmpdir(), 'watch-switch-'));
    sandboxes.push(root);
    const bin = join(root, 'bin');
    fillPath(bin, {
        real: ['bash', 'cat', 'sed', 'tr', 'tail', 'grep', 'sort', 'mktemp', 'rm', 'date'],
        standins: { curl: CURL, sleep: JOURNAL },
    });
    writeFileSync(join(root, 'scenario'), `${scenario.join('\n')}\n`);

    const result = spawnSync(
        join(bin, 'bash'),
        [SCRIPT, ...(args ?? ['https://site.example/fr', String(scenario.length)])],
        {
            cwd: root,
            encoding: 'utf8',
            env: { PATH: bin, TMPDIR: root, STUB_LOG: join(root, 'calls.log'), STUB_DIR: root, ...env },
        },
    );
    const calls = callsIn(join(root, 'calls.log'));

    return {
        root,
        status: result.status,
        stdout: result.stdout,
        stderr: result.stderr,
        lines: result.stdout
            .split('\n')
            .filter((line) => /^\d\d:\d\d:\d\d {2}/.test(line))
            .map((line) => line.slice(10)),
        asked: calls.filter(([tool]) => tool === 'curl').map((call) => call.at(-1)),
        pauses: calls.filter(([tool]) => tool === 'sleep').map(([, duration]) => duration),
        credentials: existsSync(join(root, 'credentials')) ? readFileSync(join(root, 'credentials'), 'utf8') : '',
        leftBehind: readdirSync(root).filter((entry) => entry.startsWith('tmp.')),
    };
}

const OLD = 'aaaaaaa';
const NEW = 'bbbbbbb';

describe('watch-switch.sh — a switch nobody would notice', () => {
    it('follows the page from one release to the next, and finds every file it names', () => {
        const result = watch([`200 ${OLD} old.js,old.css -`, `200 ${OLD} old.js,old.css -`, `200 ${NEW} new.js -`]);

        expect(result.stderr).toBe('');
        expect(result.status).toBe(0);
        expect(result.lines).toEqual([`request 1  release ${OLD}`, `request 3  release ${NEW}`]);
        expect(result.stdout).toContain(`3 requests, releases seen: ${OLD} → ${NEW}`);
        expect(result.stdout).toContain('None of them would have shown a visitor a broken page.');
    });

    it('asks for the page, then for each file it names, once, on the same site', () => {
        const result = watch([`200 ${OLD} b.js,a.css,b.js -`]);

        expect(result.asked).toEqual([
            'https://site.example/fr',
            'https://site.example/build/assets/a.css',
            'https://site.example/build/assets/b.js',
        ]);
    });

    it('asks five times a second, and as many times as it is told', () => {
        const result = watch(Array(4).fill(`200 ${OLD} - -`));

        expect(result.pauses).toEqual(['0.2', '0.2', '0.2', '0.2']);
        expect(result.asked).toHaveLength(4);
    });

    it('says so when no release is announced: there is then nothing to tell a switch by', () => {
        const result = watch(['200 - a.js -']);

        expect(result.lines).toEqual(['request 1  release none announced']);
        expect(result.stdout).toContain('releases seen: none');
    });
});

describe('watch-switch.sh — a switch a visitor would notice', () => {
    it('counts a page whose file does not answer, and says which release sent that page', () => {
        // What a switch looks like when PHP still answers from the release that was replaced: the
        // page of the old release, and its files already gone.
        const result = watch([`200 ${OLD} old.js -`, `200 ${OLD} old.js,old.css old.js`, `200 ${NEW} new.js -`]);

        expect(result.status).toBe(1);
        expect(result.lines).toContain(`request 2  BROKEN, served by ${OLD}: /build/assets/old.js 404`);
        expect(result.stderr).toContain('1 of them would have shown a visitor a broken page.');
        expect(result.stdout).not.toContain('None of them');
    });

    it('names every file that is missing on a page, and counts the page once', () => {
        const result = watch([`200 ${OLD} a.js,b.css,c.js a.js,c.js`]);

        expect(result.lines).toContain(
            `request 1  BROKEN, served by ${OLD}: /build/assets/a.js 404 /build/assets/c.js 404`,
        );
        expect(result.stderr).toContain('1 of them');
    });

    it.each([
        ['an error of the application', '500'],
        ['a gateway that gave up', '503'],
        ['a redirect', '302'],
    ])('counts %s as a broken page, and does not look for its files', (_, status) => {
        const result = watch([`${status} ${OLD} a.js -`]);

        expect(result.status).toBe(1);
        expect(result.lines).toContain(`request 1  BROKEN, served by ${OLD}: page ${status}`);
        expect(result.asked).toEqual(['https://site.example/fr']);
    });

    it('counts a request that got no answer at all, and goes on watching', () => {
        const result = watch([`200 ${OLD} - -`, 'dead', `200 ${NEW} - -`]);

        expect(result.status).toBe(1);
        expect(result.stdout).toMatch(/request 2 {2}BROKEN, served by .*: page /);
        expect(result.asked).toHaveLength(3);
        expect(result.stderr).toContain('1 of them');
    });

    it('counts every broken request', () => {
        const result = watch([`200 ${OLD} a.js a.js`, `200 ${OLD} a.js -`, `500 ${OLD} - -`]);

        expect(result.stderr).toContain('2 of them would have shown a visitor a broken page.');
    });
});

describe('watch-switch.sh — how it is called', () => {
    it('sends the Basic credentials through a file, never on a command line', () => {
        const result = watch([`200 ${OLD} a.js -`], {
            env: { SMOKE_BASIC_USER: 'visitor', SMOKE_BASIC_PASSWORD: 'hunter2' },
        });

        expect(result.credentials).toBe('user = "visitor:hunter2"\n'.repeat(2));
        expect(readFileSync(join(result.root, 'calls.log'), 'utf8')).not.toContain('hunter2');
    });

    it('sends none when none is given', () => {
        expect(watch([`200 ${OLD} - -`]).credentials).toBe('');
    });

    it('leaves nothing behind, credentials least of all', () => {
        const result = watch([`200 ${OLD} - -`], {
            env: { SMOKE_BASIC_USER: 'visitor', SMOKE_BASIC_PASSWORD: 'hunter2' },
        });

        expect(result.leftBehind).toEqual([]);
    });

    it.each([
        ['no argument', []],
        ['three arguments', ['https://site.example/fr', '3', 'extra']],
    ])('explains itself and asks nothing with %s', (_, args) => {
        const result = watch([], { args });

        expect(result.status).toBe(2);
        expect(result.stderr).toContain('Usage: bash scripts/watch-switch.sh <page URL> [<number of requests>]');
        expect(result.asked).toEqual([]);
    });

    it.each(['site.example/fr', 'ftp://site.example/fr', '/fr', 'https://'])(
        'refuses %j: not the URL of a page',
        (page) => {
            const result = watch([], { args: [page] });

            expect(result.status).toBe(2);
            expect(result.stderr).toContain('is not the URL of a page.');
            expect(result.asked).toEqual([]);
        },
    );

    it.each(['0', '-3', 'ten', '1.5', '03', ''])('refuses %j as a number of requests', (requests) => {
        const result = watch([], { args: ['https://site.example/fr', requests] });

        expect(result.status).toBe(2);
        expect(result.stderr).toContain('the number of requests has to be a number above zero.');
        expect(result.asked).toEqual([]);
    });

    it('watches a page at the root of a site', () => {
        const result = watch([`200 ${OLD} a.js -`], { args: ['http://localhost:8000', '1'] });

        expect(result.asked).toEqual(['http://localhost:8000', 'http://localhost:8000/build/assets/a.js']);
    });
    // Last, because it is the slow one: three hundred requests, each a few processes.
    it('watches three hundred requests unless told otherwise', () => {
        const result = watch([`200 ${OLD} - -`], { args: ['https://site.example/fr'] });

        // The scenario has one line: the requests after it get an empty answer.
        expect(result.asked).toHaveLength(300);
    }, 60000);
});
