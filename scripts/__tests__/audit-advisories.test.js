// @vitest-environment node
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { spawnSync } from 'node:child_process';
import { chmodSync, cpSync, existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
    ACCEPTED_FILE,
    AUDIT_TIMEOUT_MS,
    AUDITS,
    NPM_REPORT_VERSION,
    audit,
    cli,
    compare,
    composerAdvisories,
    ghsaIn,
    isCalendarDate,
    loadAccepted,
    npmAdvisories,
    readAudit,
    spawnIn,
    validateAccepted,
} from '../audit-advisories.js';

const REPOSITORY = fileURLToPath(new URL('../..', import.meta.url));
const SCRIPT = fileURLToPath(new URL('../audit-advisories.js', import.meta.url));
const fixture = (name) => readFileSync(fileURLToPath(new URL(`./fixtures/${name}`, import.meta.url)), 'utf8');

// What `npm audit --json` printed on 2026-10-07: 14 entries, 12 high and 2 critical, for two advisories.
const NPM_REPORT = fixture('npm-audit-2026-10-07.json');
// What `composer audit --locked --format=json` prints for a project requiring dompdf/dompdf 1.2.0.
const COMPOSER_REPORT = fixture('composer-audit-sample.json');
// What it prints for this project: nothing to report is an empty array, not an empty object.
const COMPOSER_CLEAN = '{"advisories": [], "abandoned": []}';

const BRACES = 'GHSA-vfj7-8cjw-p6xm';
const SHELL_QUOTE = 'GHSA-pqg4-j6r4-53mv';
const DOMPDF = 'GHSA-j8qw-6jw8-r297';
const SVG_LIB = 'GHSA-97m3-52wr-xvv2';
const TODAY = '2026-10-07';

const accept = (id, overrides = {}) => ({
    id,
    package: 'a-package',
    reason: 'a reason',
    acceptedOn: '2026-10-07',
    reviewBy: '2026-11-07',
    ...overrides,
});
const BOTH = [accept(BRACES), accept(SHELL_QUOTE)];

// A report in the format the script reads, around the given entries.
const npmReport = (vulnerabilities, total = Object.keys(vulnerabilities).length) => ({
    auditReportVersion: NPM_REPORT_VERSION,
    vulnerabilities,
    metadata: { vulnerabilities: { total } },
});
const advisory = (id, name = 'left-pad') => ({
    source: 1,
    name,
    severity: 'high',
    title: 'a flaw',
    url: `https://github.com/advisories/${id}`,
});

// Answers each command with the output given for it, as spawnSync would.
const answering =
    (outputs = {}) =>
    (command) => ({
        status: 1,
        stdout: { npm: NPM_REPORT, composer: COMPOSER_CLEAN, ...outputs }[command],
        stderr: '',
    });

const run = (overrides = {}) => audit({ accepted: BOTH, today: TODAY, spawn: answering(), ...overrides });

describe('ghsaIn', () => {
    it('reads the identifier out of an advisory URL', () => {
        expect(ghsaIn('https://github.com/advisories/GHSA-vfj7-8cjw-p6xm')).toBe(BRACES);
    });

    it.each(['GHSA-VFJ7-8CJW-P6XM', 'ghsa-vfj7-8cjw-p6xm', 'Ghsa-Vfj7-8cjw-P6xm'])(
        'gives %s its canonical spelling',
        (spelling) => {
            expect(ghsaIn(spelling)).toBe(BRACES);
        },
    );

    it.each([
        ['a URL without one', 'https://example.org/CVE-2026-1'],
        ['an identifier cut short', 'GHSA-vfj7-8cjw'],
        ['an empty text', ''],
    ])('answers null for %s', (_case, text) => {
        expect(ghsaIn(text)).toBeNull();
    });

    it.each([undefined, null, 42, {}, ['GHSA-vfj7-8cjw-p6xm']])('answers null for %j, which is not a text', (value) => {
        expect(ghsaIn(value)).toBeNull();
    });
});

describe('npmAdvisories', () => {
    const read = (report) => npmAdvisories(report);

    it('reduces the fourteen entries of 2026-10-07 to the two advisories behind them', () => {
        const report = JSON.parse(NPM_REPORT);
        const { advisories, unreadable } = read(report);

        expect(Object.keys(report.vulnerabilities)).toHaveLength(14);
        expect(report.metadata.vulnerabilities.total).toBe(14);
        expect(advisories.map((found) => found.id).sort()).toEqual([SHELL_QUOTE, BRACES].sort());
        expect(unreadable).toEqual([]);
    });

    it('names the package the advisory is on, not the one that depends on it', () => {
        const byId = Object.fromEntries(read(JSON.parse(NPM_REPORT)).advisories.map((found) => [found.id, found]));

        expect(byId[BRACES]).toEqual({
            id: BRACES,
            package: 'braces',
            severity: 'high',
            title: 'braces vulnerable to stack-exhaustion denial of service through deeply nested patterns',
            url: `https://github.com/advisories/${BRACES}`,
            ecosystem: 'npm',
        });
        expect(byId[SHELL_QUOTE]).toMatchObject({ package: 'shell-quote', severity: 'critical' });
    });

    it('counts once an advisory that npm lists under two packages', () => {
        const report = npmReport({
            a: { via: [advisory(BRACES, 'braces')] },
            b: { via: [advisory(BRACES, 'braces')] },
        });

        expect(read(report).advisories).toHaveLength(1);
    });

    it('keeps two advisories that reach the same package', () => {
        const report = npmReport({ a: { via: [advisory(BRACES), advisory(SHELL_QUOTE)] } });

        expect(read(report).advisories.map((found) => found.id)).toEqual([BRACES, SHELL_QUOTE]);
    });

    it.each([
        ['a number', 4242, '4242'],
        ['a text', 'NPM-4242', 'NPM-4242'],
    ])(
        'falls back on the registry identifier, given as %s, when the URL carries no GHSA',
        (_case, source, expected) => {
            const report = npmReport({ left: { via: [{ source, name: 'left', url: 'https://example.org/x' }] } });

            expect(read(report)).toMatchObject({ advisories: [{ id: expected }], unreadable: [] });
        },
    );

    it('finds nothing in a clean report, and nothing unreadable either', () => {
        expect(read(npmReport({}))).toEqual({ advisories: [], unreadable: [] });
    });

    it.each([
        ['a later report version', { ...npmReport({}), auditReportVersion: NPM_REPORT_VERSION + 1 }],
        ['no report version', { vulnerabilities: {}, metadata: { vulnerabilities: { total: 0 } } }],
        ['vulnerabilities given as a list', { ...npmReport({}), vulnerabilities: [] }],
        ['vulnerabilities set to null', { ...npmReport({}), vulnerabilities: null }],
        ['no metadata', { auditReportVersion: NPM_REPORT_VERSION, vulnerabilities: {} }],
        ['a total that is not a number', npmReport({}, '14')],
        ['a total that is not whole', npmReport({}, 1.5)],
    ])('refuses a report with %s', (_case, report) => {
        expect(read(report).unreadable).toEqual([expect.stringContaining('not in the format this script reads')]);
        expect(read(report).advisories).toEqual([]);
    });

    it.each([
        ['null', null],
        ['a text', 'braces'],
        ['a list', []],
        ['an object without `via`', { severity: 'high' }],
        ['a `via` that is not a list', { via: 'braces' }],
    ])('refuses an entry that is %s', (_case, entry) => {
        expect(read(npmReport({ braces: entry })).unreadable).toEqual([expect.stringContaining('braces')]);
    });

    it.each([
        ['null', null],
        ['a number', 7],
        ['a list', []],
        ['an object with neither URL nor source', { name: 'braces' }],
        ['an object whose source is null', { name: 'braces', source: null }],
        ['an object whose source is blank', { name: 'braces', source: ' ' }],
        ['an object whose source is an object', { name: 'braces', source: {} }],
    ])('refuses an advisory that is %s rather than giving it a made-up identifier', (_case, via) => {
        const { advisories, unreadable } = read(npmReport({ braces: { via: [via] } }));

        expect(unreadable).toEqual(['an advisory reached through braces carries no identifier']);
        expect(advisories).toEqual([]);
    });

    it('still reports what it could not read next to what it could', () => {
        const report = npmReport({ a: { via: [advisory(BRACES)] }, b: { via: [{ name: 'nameless' }] } });

        expect(read(report)).toMatchObject({
            advisories: [{ id: BRACES }],
            unreadable: [expect.stringContaining('b')],
        });
    });

    it('refuses a report that counts vulnerable packages and names no advisory', () => {
        const report = npmReport({ stylelint: { via: ['micromatch'] }, micromatch: { via: ['braces'] } });

        expect(read(report)).toEqual({
            advisories: [],
            unreadable: ['it counts 2 vulnerable packages and names no advisory'],
        });
    });
});

describe('composerAdvisories', () => {
    const read = (report) => composerAdvisories(report);
    const entry = (overrides = {}) => ({
        advisoryId: 'PKSA-1',
        packageName: 'a/b',
        sources: [],
        link: null,
        ...overrides,
    });

    it('keys an advisory on its GHSA identifier rather than on the Packagist one', () => {
        expect(read(JSON.parse(COMPOSER_REPORT))).toEqual({
            advisories: [
                expect.objectContaining({
                    id: DOMPDF,
                    package: 'dompdf/dompdf',
                    severity: 'medium',
                    ecosystem: 'composer',
                }),
                expect.objectContaining({ id: SVG_LIB, package: 'phenx/php-svg-lib', severity: 'critical' }),
            ],
            unreadable: [],
        });
    });

    it('reads the GHSA identifier from the link when no source is GitHub', () => {
        const report = { advisories: { 'a/b': [entry({ link: `https://github.com/advisories/${DOMPDF}` })] } };

        expect(read(report).advisories[0].id).toBe(DOMPDF);
    });

    it('prefers the GitHub source to the link when they disagree', () => {
        const sources = [
            { name: 'FriendsOfPHP', remoteId: 'x' },
            { name: 'GitHub', remoteId: SVG_LIB },
        ];
        const report = { advisories: { 'a/b': [entry({ sources, link: `https://github.com/advisories/${DOMPDF}` })] } };

        expect(read(report).advisories[0].id).toBe(SVG_LIB);
    });

    it('falls back on the Packagist identifier when nothing carries a GHSA', () => {
        expect(read({ advisories: { 'a/b': [entry()] } })).toMatchObject({
            advisories: [{ id: 'PKSA-1' }],
            unreadable: [],
        });
    });

    it('counts once an advisory listed under two packages', () => {
        const same = entry({ link: `https://github.com/advisories/${DOMPDF}` });

        expect(read({ advisories: { 'a/b': [same], 'c/d': [same] } }).advisories).toHaveLength(1);
    });

    it.each([
        ['the empty list of a clean report', JSON.parse(COMPOSER_CLEAN)],
        ['an empty object', { advisories: {} }],
    ])('finds nothing in %s', (_case, report) => {
        expect(read(report)).toEqual({ advisories: [], unreadable: [] });
    });

    it.each([
        ['a text', 'none'],
        ['null', null],
        ['a number', 0],
        ['undefined', undefined],
    ])('refuses advisories given as %s', (_case, advisories) => {
        expect(read({ advisories }).unreadable).toEqual([expect.stringContaining('neither a list nor an object')]);
    });

    it.each([
        ['an object', { 0: entry() }],
        ['a text', 'PKSA-1'],
        ['null', null],
    ])('refuses the advisories of a package given as %s', (_case, list) => {
        expect(read({ advisories: { 'a/b': list } })).toEqual({
            advisories: [],
            unreadable: ['the advisories of a/b are not a list'],
        });
    });

    it.each([
        ['null', null],
        ['a text', 'PKSA-1'],
        ['an object with no identifier at all', { packageName: 'a/b' }],
        ['an object whose identifier is blank', entry({ advisoryId: ' ' })],
        ['an object whose identifier is a number', entry({ advisoryId: 12 })],
        ['an object whose sources are not a list', { packageName: 'a/b', sources: 'GitHub' }],
        ['an object whose sources hold null', { packageName: 'a/b', sources: [null] }],
    ])('refuses an advisory that is %s', (_case, broken) => {
        expect(read({ advisories: { 'a/b': [broken] } })).toEqual({
            advisories: [],
            unreadable: ['an advisory on a/b carries no identifier'],
        });
    });
});

describe('the two audits', () => {
    // The commands are the contract with the tools: JSON is what gets parsed, and --locked is what
    // lets composer answer from the lockfile, without a vendor directory.
    it('are npm and composer, asked for JSON, from the lockfiles alone', () => {
        expect(AUDITS.map(({ command, args }) => [command, ...args].join(' '))).toEqual([
            'npm audit --json',
            'composer audit --locked --format=json',
        ]);
    });

    it('are each read by their own extractor, under the key their report always carries', () => {
        expect(AUDITS.map(({ key, extract }) => [key, extract])).toEqual([
            ['vulnerabilities', npmAdvisories],
            ['advisories', composerAdvisories],
        ]);
    });
});

describe('isCalendarDate', () => {
    it.each(['2026-10-07', '2024-02-29', '2026-12-31', '9999-12-31'])('accepts %s', (text) => {
        expect(isCalendarDate(text)).toBe(true);
    });

    it.each([
        ['a day that does not exist', '2026-02-30'],
        ['the 29th of February of a common year', '2026-02-29'],
        ['a thirteenth month', '2026-13-01'],
        ['a day zero', '2026-10-00'],
        ['a date without leading zeros', '2026-2-3'],
        ['a French date', '07/10/2026'],
        ['a date with a time', '2026-10-07T00:00:00Z'],
        ['a date with a space around it', ' 2026-10-07'],
        ['an empty text', ''],
        ['a number', 20261007],
        ['null', null],
    ])('refuses %s', (_case, text) => {
        expect(isCalendarDate(text)).toBe(false);
    });
});

describe('validateAccepted', () => {
    it('accepts a well-formed list, and an empty one', () => {
        expect(validateAccepted(BOTH)).toEqual([]);
        expect(validateAccepted([])).toEqual([]);
    });

    it('accepts an identifier that is not a GHSA one, as composer can print', () => {
        expect(validateAccepted([accept('PKSA-cv56-2228-pzr6')])).toEqual([]);
    });

    it('tolerates a field it does not know', () => {
        expect(validateAccepted([accept(BRACES, { ticket: 'LUMN-73' })])).toEqual([]);
    });

    it.each([
        ['an object', {}],
        ['null', null],
        ['a text', BRACES],
        ['a number', 1],
        ['undefined', undefined],
    ])('refuses %s in place of the list', (_case, value) => {
        expect(validateAccepted(value)).toEqual([`${ACCEPTED_FILE} must hold a list of accepted advisories.`]);
    });

    it.each([
        ['null', null],
        ['a text', BRACES],
        ['a list', [BRACES]],
        ['a number', 3],
    ])('refuses an entry that is %s', (_case, entry) => {
        expect(validateAccepted([entry])).toEqual([expect.stringContaining('entry 1: must be an object')]);
    });

    it.each(['id', 'package', 'reason', 'acceptedOn', 'reviewBy'])('refuses an entry without %s', (field) => {
        expect(validateAccepted([accept(BRACES, { [field]: undefined })])).toEqual([
            expect.stringContaining(`missing ${field}`),
        ]);
    });

    it.each([
        ['blank', '   '],
        ['empty', ''],
        ['a number', 5],
        ['null', null],
        ['a list', ['x']],
    ])('refuses a reason that is %s', (_case, reason) => {
        expect(validateAccepted([accept(BRACES, { reason })])).toEqual([expect.stringContaining('missing reason')]);
    });

    it('names every missing field at once, and the entry they are missing from', () => {
        const problems = validateAccepted([accept(BRACES), { id: SHELL_QUOTE }]);

        expect(problems).toEqual([
            `${ACCEPTED_FILE}, entry 2 (${SHELL_QUOTE}): missing package, reason, acceptedOn, reviewBy.`,
        ]);
    });

    it.each([
        ['in lower case', 'ghsa-vfj7-8cjw-p6xm'],
        ['in upper case', 'GHSA-VFJ7-8CJW-P6XM'],
        ['with a space after it', `${BRACES} `],
        ['as a URL', `https://github.com/advisories/${BRACES}`],
    ])('refuses an identifier written %s, which would accept nothing', (_case, id) => {
        expect(validateAccepted([accept(id)])).toEqual([
            expect.stringContaining(`exactly as the audits print it, ${BRACES}`),
        ]);
    });

    it.each(['acceptedOn', 'reviewBy'])('refuses a %s that is not a real date', (field) => {
        expect(validateAccepted([accept(BRACES, { [field]: '2026-02-30' })])).toEqual([
            expect.stringContaining(`${field} must be a real date written YYYY-MM-DD`),
        ]);
    });

    it('refuses a review date that comes before the acceptance', () => {
        expect(validateAccepted([accept(BRACES, { acceptedOn: '2026-10-07', reviewBy: '2026-10-06' })])).toEqual([
            expect.stringContaining('reviewBy comes before acceptedOn'),
        ]);
    });

    it('accepts a review date on the day of the acceptance', () => {
        expect(validateAccepted([accept(BRACES, { acceptedOn: '2026-10-07', reviewBy: '2026-10-07' })])).toEqual([]);
    });

    it('refuses an advisory listed twice', () => {
        expect(validateAccepted([accept(BRACES), accept(SHELL_QUOTE), accept(BRACES)])).toEqual([
            `${ACCEPTED_FILE}, entry 3 (${BRACES}): listed twice.`,
        ]);
    });

    it('holds for the list the repository ships', () => {
        expect(validateAccepted(loadAccepted(REPOSITORY).accepted)).toEqual([]);
    });
});

describe('compare', () => {
    const found = [{ id: BRACES }, { id: SHELL_QUOTE }];

    it('reports an advisory nobody accepted', () => {
        expect(compare(found, [accept(BRACES)], TODAY)).toEqual({
            unaccepted: [{ id: SHELL_QUOTE }],
            expired: [],
            stale: [],
        });
    });

    it('reports nothing when every advisory is accepted', () => {
        expect(compare(found, BOTH, TODAY)).toEqual({ unaccepted: [], expired: [], stale: [] });
    });

    it('reports every advisory when nothing is accepted', () => {
        expect(compare(found, [], TODAY).unaccepted).toEqual(found);
    });

    it('reports an acceptance that no audit reports any more', () => {
        const gone = accept('GHSA-aaaa-bbbb-cccc');

        expect(compare(found, [...BOTH, gone], TODAY).stale).toEqual([gone]);
    });

    it('calls every acceptance stale when the audits report nothing', () => {
        expect(compare([], BOTH, TODAY).stale).toEqual(BOTH);
    });

    it('holds an acceptance until the end of its review date, and no longer', () => {
        const accepted = [accept(BRACES, { reviewBy: '2026-11-07' }), accept(SHELL_QUOTE, { reviewBy: '2027-01-07' })];

        expect(compare(found, accepted, '2026-11-06').expired).toEqual([]);
        expect(compare(found, accepted, '2026-11-07').expired).toEqual([]);
        expect(compare(found, accepted, '2026-11-08').expired).toEqual([accepted[0]]);
        expect(compare(found, accepted, '2027-01-08').expired).toEqual(accepted);
    });

    it('lets an acceptance be both expired and stale', () => {
        const gone = accept('GHSA-aaaa-bbbb-cccc', { reviewBy: '2026-01-01' });

        expect(compare(found, [...BOTH, gone], TODAY)).toMatchObject({ expired: [gone], stale: [gone] });
    });
});

describe('readAudit', () => {
    const [npm, composer] = AUDITS;
    const read = (result, definition = npm) => readAudit(definition, () => result);

    it('starts the command it is given, with its arguments', () => {
        const started = [];
        readAudit(composer, (command, args) => {
            started.push([command, args]);
            return { stdout: COMPOSER_CLEAN };
        });

        expect(started).toEqual([['composer', ['audit', '--locked', '--format=json']]]);
    });

    it.each([0, 1, 2])('reads the advisories whatever the exit code, here %i', (status) => {
        expect(read({ status, stdout: NPM_REPORT, stderr: '' })).toMatchObject({ problem: null, advisories: [{}, {}] });
    });

    it('reads a clean composer report', () => {
        expect(read({ stdout: COMPOSER_CLEAN }, composer)).toEqual({ advisories: [], problem: null });
    });

    it.each([
        ['plain text', 'npm error code ENOTFOUND'],
        ['nothing', ''],
        ['a JSON list', '[]'],
        ['a JSON number', '42'],
        ['a JSON null', 'null'],
        ['a JSON text', '"ok"'],
        ['a JSON cut short', NPM_REPORT.slice(0, 200)],
        ['an object without the expected key', '{"metadata": {}}'],
    ])('refuses %s in place of a report', (_case, stdout) => {
        const { advisories, problem } = read({ stdout, stderr: '' });

        expect(problem).toContain('`npm audit --json` did not answer');
        expect(problem).toContain('→ run `npm audit --json` by hand');
        expect(advisories).toEqual([]);
    });

    it('quotes the summary npm gives when the registry is unreachable', () => {
        const unreachable = JSON.stringify({ error: { code: 'ENOTFOUND', summary: 'request to registry failed' } });

        expect(read({ stdout: unreachable, stderr: 'ignored' }).problem).toContain(
            'did not answer: request to registry failed',
        );
    });

    it.each([
        ['no summary', { error: {} }],
        ['a blank summary', { error: { summary: ' ' } }],
        ['a summary that is not a text', { error: { summary: 404 } }],
    ])('quotes the first line of the error stream when the error carries %s', (_case, report) => {
        const problem = read({
            stdout: JSON.stringify(report),
            stderr: '\nnpm error network down\nsecond line\n',
        }).problem;

        expect(problem).toContain('did not answer: npm error network down\n');
        expect(problem).not.toContain('second line');
    });

    it('says so plainly when there is nothing to quote', () => {
        expect(read({ stdout: '', stderr: '' }).problem).toContain('`npm audit --json` did not answer.\n');
    });

    it.each([
        [
            'could not be started',
            { stdout: null, stderr: null, error: new Error('spawnSync composer ENOENT') },
            'ENOENT',
        ],
        [
            'was killed on the timeout',
            { stdout: NPM_REPORT, stderr: '', error: new Error('spawnSync npm ETIMEDOUT') },
            'ETIMEDOUT',
        ],
    ])('refuses a command that %s, even if it printed a report', (_case, result, expected) => {
        expect(read(result)).toMatchObject({ advisories: [], problem: expect.stringContaining(expected) });
    });

    it('refuses a report with null streams and no error', () => {
        expect(read({ stdout: null, stderr: null }).problem).toContain('did not answer.');
    });

    it('refuses a report it could only read in part, and says which part', () => {
        const report = npmReport({ a: { via: [advisory(BRACES)] }, b: { via: [{ name: 'nameless' }] }, c: 'broken' });
        const { advisories, problem } = read({ stdout: JSON.stringify(report) });

        expect(problem).toContain(
            'did not answer: an advisory reached through b carries no identifier; the entry for c does not say what it is vulnerable through',
        );
        expect(advisories).toEqual([]);
    });
});

describe('audit', () => {
    it('passes when every advisory is accepted', () => {
        expect(run()).toEqual({ problems: [], notices: [] });
    });

    it('passes on a clean tree with nothing accepted', () => {
        expect(run({ accepted: [], spawn: answering({ npm: JSON.stringify(npmReport({})) }) })).toEqual({
            problems: [],
            notices: [],
        });
    });

    it('fails on a new advisory, and says what it is, where, and what to do', () => {
        const { problems } = run({ accepted: [accept(BRACES)] });

        expect(problems).toEqual([
            `${SHELL_QUOTE} (critical) in shell-quote, reported by npm: ` +
                'shell-quote: `quote()` command injection via a line terminator in a token after a `{ comment }` token\n' +
                `  https://github.com/advisories/${SHELL_QUOTE}\n` +
                `  → update the package, or accept the advisory in ${ACCEPTED_FILE} with a reason and a review date.`,
        ]);
    });

    it('fails on a composer advisory as it does on an npm one', () => {
        const { problems } = run({ spawn: answering({ composer: COMPOSER_REPORT }) });

        expect(problems.map((problem) => problem.split(' ')[0])).toEqual([DOMPDF, SVG_LIB]);
        expect(problems[0]).toContain('in dompdf/dompdf, reported by composer');
    });

    it('lets one acceptance cover an advisory both ecosystems report', () => {
        const npm = JSON.stringify(npmReport({ shared: { via: [advisory(DOMPDF, 'shared')] } }));
        const composer = JSON.stringify({
            advisories: { 'dompdf/dompdf': JSON.parse(COMPOSER_REPORT).advisories['dompdf/dompdf'] },
        });

        expect(run({ accepted: [accept(DOMPDF)], spawn: answering({ npm, composer }) })).toEqual({
            problems: [],
            notices: [],
        });
        expect(run({ accepted: [], spawn: answering({ npm, composer }) }).problems).toHaveLength(2);
    });

    it('fails once the review date has passed, with the reason that was given', () => {
        const accepted = [
            accept(BRACES, { package: 'braces', reason: 'no fixed version exists' }),
            accept(SHELL_QUOTE, { reviewBy: '2027-01-07' }),
        ];

        expect(run({ accepted, today: '2026-11-07' }).problems).toEqual([]);
        expect(run({ accepted, today: '2026-11-08' }).problems).toEqual([
            `The acceptance of ${BRACES} (braces) was to be reviewed by 2026-11-07.\n` +
                '  Reason given on 2026-10-07: no fixed version exists\n' +
                `  → check whether it still holds, then remove the entry or give it a new review date in ${ACCEPTED_FILE}.`,
        ]);
    });

    it('fails on an expired acceptance even when its advisory is gone', () => {
        const gone = accept('GHSA-aaaa-bbbb-cccc', { acceptedOn: '2025-12-01', reviewBy: '2026-01-01' });
        const { problems, notices } = run({ accepted: [...BOTH, gone] });

        expect(problems).toEqual([expect.stringContaining('GHSA-aaaa-bbbb-cccc')]);
        expect(notices).toEqual([expect.stringContaining('GHSA-aaaa-bbbb-cccc')]);
    });

    it('only mentions an acceptance that has nothing left to cover', () => {
        const { problems, notices } = run({ accepted: [...BOTH, accept('GHSA-aaaa-bbbb-cccc', { package: 'gone' })] });

        expect(problems).toEqual([]);
        expect(notices).toEqual([
            `GHSA-aaaa-bbbb-cccc (gone) is accepted in ${ACCEPTED_FILE} but no audit reports it any more: remove its entry.`,
        ]);
    });

    it.each(['npm', 'composer'])(
        'fails when %s does not answer, without calling the accepted list stale',
        (command) => {
            const { problems, notices } = run({ spawn: answering({ [command]: '' }) });

            expect(problems).toEqual([expect.stringContaining(`\`${command} audit`)]);
            expect(notices).toEqual([]);
        },
    );

    it('names both audits when neither answers', () => {
        expect(run({ spawn: answering({ npm: '', composer: 'Killed' }) }).problems).toHaveLength(2);
    });

    it('still names a new advisory when the other audit did not answer', () => {
        const { problems } = run({ accepted: [accept(BRACES)], spawn: answering({ composer: '' }) });

        expect(problems).toEqual([expect.stringContaining('composer audit'), expect.stringContaining(SHELL_QUOTE)]);
    });

    // The defect LUMN-73 is about, at the scale of this script: an answer it does not understand
    // must not read as a clean tree.
    it.each([
        ['a later report version', { ...JSON.parse(NPM_REPORT), auditReportVersion: 3 }],
        ['fourteen vulnerable packages and no advisory', npmReport({ stylelint: { via: ['micromatch'] } }, 14)],
        [
            'an advisory without an identifier',
            npmReport({ braces: { via: [{ name: 'braces', severity: 'critical' }] } }),
        ],
    ])('fails rather than turning green on %s', (_case, report) => {
        const { problems, notices } = run({ accepted: [], spawn: answering({ npm: JSON.stringify(report) }) });

        expect(problems).toEqual([expect.stringContaining('`npm audit --json` did not answer')]);
        expect(notices).toEqual([]);
    });

    it('refuses a malformed list before starting anything', () => {
        const never = () => {
            throw new Error('the audit must not run');
        };

        expect(run({ accepted: [accept(BRACES, { reason: '' })], spawn: never }).problems).toEqual([
            expect.stringContaining('missing reason'),
        ]);
    });
});

describe('spawnIn', () => {
    it('runs the command from the project root, as text, with a time limit', () => {
        const calls = [];
        const spawn = spawnIn('/a/root', (...call) => {
            calls.push(call);
            return { stdout: 'printed' };
        });

        expect(spawn('npm', ['audit', '--json'])).toEqual({ stdout: 'printed' });
        expect(calls).toEqual([
            ['npm', ['audit', '--json'], { cwd: '/a/root', encoding: 'utf8', timeout: AUDIT_TIMEOUT_MS }],
        ]);
    });

    it('bounds an audit to a couple of minutes', () => {
        expect(AUDIT_TIMEOUT_MS).toBe(120_000);
    });

    it('really starts a process when no runner is given', () => {
        const result = spawnIn(REPOSITORY)(process.execPath, ['--eval', 'process.stdout.write(process.cwd())']);

        expect(result).toMatchObject({ status: 0, stdout: REPOSITORY.replace(/\/$/, '') });
    });
});

describe('with a project on disk', () => {
    let root;

    const write = (accepted) =>
        writeFileSync(join(root, ACCEPTED_FILE), typeof accepted === 'string' ? accepted : JSON.stringify(accepted));

    beforeEach(() => {
        root = mkdtempSync(join(tmpdir(), 'audit-advisories-'));
    });

    afterEach(() => {
        rmSync(root, { recursive: true, force: true });
    });

    describe('loadAccepted', () => {
        it('reads the list', () => {
            write(BOTH);

            expect(loadAccepted(root)).toEqual({ accepted: BOTH, problem: null });
        });

        it('reports a missing file instead of throwing', () => {
            expect(loadAccepted(root)).toEqual({
                accepted: null,
                problem: expect.stringMatching(new RegExp(`^${ACCEPTED_FILE} could not be read: ENOENT`)),
            });
        });

        it('reports a file that is not JSON instead of throwing', () => {
            write('[{"id": "GHSA-vfj7-8cjw-p6xm",]');

            expect(loadAccepted(root).problem).toContain(`${ACCEPTED_FILE} could not be read: `);
        });
    });

    describe('cli', () => {
        const invoke = (overrides = {}) => {
            const printed = { out: [], err: [] };
            const code = cli({
                root,
                now: new Date('2026-10-07T12:00:00Z'),
                env: {},
                spawn: answering(),
                out: (line) => printed.out.push(line),
                err: (line) => printed.err.push(line),
                ...overrides,
            });
            return { code, ...printed };
        };

        it('exits 0 and says so when nothing is outside the list', () => {
            write(BOTH);

            expect(invoke()).toEqual({ code: 0, out: ['No advisory outside the accepted list.'], err: [] });
        });

        it('exits 1 and prints every problem on the error stream', () => {
            write([]);
            const { code, out, err } = invoke();

            expect(code).toBe(1);
            expect(out).toEqual([]);
            expect(err).toEqual([
                'The advisory audit failed:\n',
                expect.stringMatching(/^• GHSA-/),
                expect.stringMatching(/^• GHSA-/),
            ]);
            expect(err.join('')).toContain(BRACES);
            expect(err.join('')).toContain(SHELL_QUOTE);
        });

        it('takes today from the clock it is given, in UTC', () => {
            write([accept(BRACES, { reviewBy: '2026-11-07' }), accept(SHELL_QUOTE, { reviewBy: '2027-01-07' })]);

            expect(invoke({ now: new Date('2026-11-07T23:59:59Z') }).code).toBe(0);
            expect(invoke({ now: new Date('2026-11-08T00:00:00Z') })).toMatchObject({
                code: 1,
                err: ['The advisory audit failed:\n', expect.stringContaining('was to be reviewed by 2026-11-07')],
            });
        });

        it('prints a notice as plain text on a terminal, and still exits 0', () => {
            write([...BOTH, accept('GHSA-aaaa-bbbb-cccc')]);

            expect(invoke()).toEqual({
                code: 0,
                out: [
                    expect.stringMatching(/^• GHSA-aaaa-bbbb-cccc .* remove its entry\.$/),
                    'No advisory outside the accepted list.',
                ],
                err: [],
            });
        });

        it('prints a notice as an annotation on GitHub Actions', () => {
            write([...BOTH, accept('GHSA-aaaa-bbbb-cccc')]);

            expect(invoke({ env: { GITHUB_ACTIONS: 'true' } }).out[0]).toMatch(/^::warning::GHSA-aaaa-bbbb-cccc /);
        });

        it.each([
            ['is missing', null],
            ['is not JSON', '{'],
            ['is not a list', '{}'],
        ])('exits 1 without starting an audit when the list %s', (_case, content) => {
            if (content !== null) {
                write(content);
            }
            const never = () => {
                throw new Error('the audit must not run');
            };

            expect(invoke({ spawn: never })).toMatchObject({
                code: 1,
                out: [],
                err: ['The advisory audit failed:\n', expect.stringContaining(ACCEPTED_FILE)],
            });
        });
    });

    // The assembly: the real script, started as the workflow starts it, against stand-ins for npm
    // and composer. Nothing above can tell whether the exit code leaves the process, nor whether
    // the commands really started are the ones the unit tests describe.
    describe('run as a process', () => {
        const tool = (name, { stdout, status = 1 }) => {
            const path = join(root, 'bin', name);
            writeFileSync(
                path,
                '#!/usr/bin/env node\n' +
                    "import { appendFileSync } from 'node:fs';\n" +
                    `appendFileSync(${JSON.stringify(join(root, 'calls.log'))}, ` +
                    `[${JSON.stringify(name)}, ...process.argv.slice(2), 'in', process.cwd()].join(' ') + '\\n');\n` +
                    `process.stdout.write(${JSON.stringify(stdout)});\n` +
                    `process.exitCode = ${status};\n`,
            );
            chmodSync(path, 0o755);
        };

        const start = (env = {}) =>
            spawnSync(process.execPath, [join(root, 'scripts', 'audit-advisories.js')], {
                encoding: 'utf8',
                // Nothing but the stand-ins and node itself: a real npm or composer cannot be reached.
                env: { PATH: `${join(root, 'bin')}:${dirname(process.execPath)}`, ...env },
            });

        const calls = () =>
            existsSync(join(root, 'calls.log')) ? readFileSync(join(root, 'calls.log'), 'utf8').trim().split('\n') : [];

        const never = { reviewBy: '9999-12-31' };

        beforeEach(() => {
            mkdirSync(join(root, 'bin'));
            mkdirSync(join(root, 'scripts'));
            cpSync(SCRIPT, join(root, 'scripts', 'audit-advisories.js'));
            // The copy sits outside the repository, whose package.json is what makes .js a module.
            writeFileSync(join(root, 'package.json'), '{"type": "module"}');
            tool('npm', { stdout: NPM_REPORT });
            tool('composer', { stdout: COMPOSER_CLEAN, status: 0 });
        });

        it('exits 0 when both tools report only accepted advisories, though npm itself exited 1', () => {
            write([accept(BRACES, never), accept(SHELL_QUOTE, never)]);
            const result = start();

            expect(result).toMatchObject({ status: 0, stdout: 'No advisory outside the accepted list.\n', stderr: '' });
        });

        it('starts npm and composer with the documented arguments, from the project root', () => {
            write([accept(BRACES, never), accept(SHELL_QUOTE, never)]);
            start();

            expect(calls()).toEqual([
                `npm audit --json in ${root}`,
                `composer audit --locked --format=json in ${root}`,
            ]);
        });

        it('exits 1 and names the advisory when one is not accepted', () => {
            write([accept(BRACES, never)]);
            const result = start();

            expect(result.status).toBe(1);
            expect(result.stderr).toContain('The advisory audit failed:');
            expect(result.stderr).toContain(SHELL_QUOTE);
            expect(result.stdout).toBe('');
        });

        it('exits 1 when an acceptance is past its review date', () => {
            write([accept(BRACES, never), accept(SHELL_QUOTE, { acceptedOn: '2000-01-01', reviewBy: '2000-01-02' })]);

            expect(start()).toMatchObject({
                status: 1,
                stderr: expect.stringContaining('was to be reviewed by 2000-01-02'),
            });
        });

        it('exits 1 when a tool prints nothing', () => {
            write([accept(BRACES, never), accept(SHELL_QUOTE, never)]);
            tool('npm', { stdout: '' });

            expect(start()).toMatchObject({
                status: 1,
                stderr: expect.stringContaining('`npm audit --json` did not answer'),
            });
        });

        it('exits 1 when a tool is not installed', () => {
            write([accept(BRACES, never), accept(SHELL_QUOTE, never)]);
            rmSync(join(root, 'bin', 'composer'));

            expect(start()).toMatchObject({
                status: 1,
                stderr: expect.stringMatching(/`composer audit --locked --format=json` did not answer: .*ENOENT/),
            });
        });

        it('exits 1 when the accepted list is missing', () => {
            const result = start();

            expect(result).toMatchObject({
                status: 1,
                stderr: expect.stringContaining(`${ACCEPTED_FILE} could not be read`),
            });
            expect(calls()).toEqual([]);
        });

        it('annotates the run on GitHub Actions and still exits 0 for an acceptance with nothing to cover', () => {
            write([accept(BRACES, never), accept(SHELL_QUOTE, never), accept('GHSA-aaaa-bbbb-cccc', never)]);

            expect(start({ GITHUB_ACTIONS: 'true' })).toMatchObject({
                status: 0,
                stdout: expect.stringMatching(
                    /^::warning::GHSA-aaaa-bbbb-cccc .*\nNo advisory outside the accepted list\.\n$/,
                ),
            });
        });
    });
});
