// Advisory audit — fails on a known vulnerability nobody has decided to accept.
// Usage: node scripts/audit-advisories.js   (run daily by .github/workflows/security-audit.yml)
//
// `npm audit` and `composer audit` report the whole tree, flaws without a fix included, so neither
// can gate a merge. This script turns them into a signal that only fires on something new: every
// advisory they report is either listed in accepted-advisories.json, with a reason and a review
// date, or a failure. See LUMN-73 — GitHub's own alerts had stayed silent on two advisories.
//
// Four outcomes:
//   1. an advisory that is not accepted            → failure
//   2. an acceptance whose review date has passed  → failure
//   3. an audit that did not answer, or answered
//      in a form this script does not understand   → failure, never "nothing to report"
//   4. an acceptance no audit reports any more     → notice: the entry is to be removed
//
// The third is the one that matters most. Everything here is written so that what it cannot read
// fails: a watch that turns green on an answer it did not understand is the defect being fixed.
//
// Both audits read the lockfiles alone: no `npm ci`, no `composer install`.

import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

export const ACCEPTED_FILE = 'accepted-advisories.json';

// The report format `npmAdvisories` was written against. npm states it in every report: another
// value means the structure may have moved, and that is not something to find out by reading zero
// advisories out of it.
export const NPM_REPORT_VERSION = 2;

// Long enough for a slow registry, short enough that a hung audit fails instead of holding the job.
export const AUDIT_TIMEOUT_MS = 120_000;

const ACCEPTED_FIELDS = ['id', 'package', 'reason', 'acceptedOn', 'reviewBy'];
const GHSA_PATTERN = /GHSA(?:-[0-9a-z]{4}){3}/i;

const isRecord = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);
const isFilled = (value) => typeof value === 'string' && value.trim() !== '';

/**
 * Returns the GHSA identifier found in a text, in its canonical spelling, or null.
 *
 * @param {unknown} text
 * @returns {string|null}
 */
export function ghsaIn(text) {
    const match = typeof text === 'string' ? text.match(GHSA_PATTERN) : null;
    return match ? `GHSA${match[0].slice(4).toLowerCase()}` : null;
}

/**
 * Extracts the distinct advisories from `npm audit --json`, and what could not be read in it.
 *
 * npm lists one entry per affected package: an advisory on a transitive dependency appears once
 * for the package itself, then once more for every package that depends on it — those entries
 * name it in `via` as a plain string. Only the objects in `via` are advisories, and the same one
 * can sit under several packages.
 *
 * @param {object} report
 * @returns {{advisories: object[], unreadable: string[]}}
 */
export function npmAdvisories(report) {
    const total = report.metadata?.vulnerabilities?.total;
    if (
        report.auditReportVersion !== NPM_REPORT_VERSION ||
        !isRecord(report.vulnerabilities) ||
        !Number.isInteger(total)
    ) {
        return {
            advisories: [],
            unreadable: [
                `the report is not in the format this script reads (auditReportVersion ${NPM_REPORT_VERSION}, with its vulnerabilities and their total)`,
            ],
        };
    }

    const advisories = new Map();
    const unreadable = [];
    for (const [name, entry] of Object.entries(report.vulnerabilities)) {
        if (!isRecord(entry) || !Array.isArray(entry.via)) {
            unreadable.push(`the entry for ${name} does not say what it is vulnerable through`);
            continue;
        }
        for (const via of entry.via) {
            if (typeof via === 'string') {
                continue;
            }
            const source =
                isRecord(via) && (typeof via.source === 'number' || isFilled(via.source)) ? String(via.source) : null;
            const id = isRecord(via) ? (ghsaIn(via.url) ?? source) : null;
            if (id === null) {
                unreadable.push(`an advisory reached through ${name} carries no identifier`);
                continue;
            }
            advisories.set(id, {
                id,
                package: via.name,
                severity: via.severity,
                title: via.title,
                url: via.url,
                ecosystem: 'npm',
            });
        }
    }

    // The total counts packages, not advisories, so the two numbers never have to match — but
    // vulnerable packages with no advisory behind them means the report was read wrong.
    if (total > 0 && advisories.size === 0 && unreadable.length === 0) {
        unreadable.push(`it counts ${total} vulnerable packages and names no advisory`);
    }
    return { advisories: [...advisories.values()], unreadable };
}

/**
 * Extracts the distinct advisories from `composer audit --format=json`, and what could not be read.
 *
 * `advisories` is an empty array when there is nothing to report, and an object keyed by package
 * name otherwise. Packagist carries its own identifier (PKSA-…); the GHSA one, when there is one,
 * is preferred so that both ecosystems are accepted under the same kind of key.
 *
 * @param {object} report
 * @returns {{advisories: object[], unreadable: string[]}}
 */
export function composerAdvisories(report) {
    if (!isRecord(report.advisories) && !Array.isArray(report.advisories)) {
        return { advisories: [], unreadable: ['`advisories` is neither a list nor an object keyed by package'] };
    }

    const advisories = new Map();
    const unreadable = [];
    for (const [name, list] of Object.entries(report.advisories)) {
        if (!Array.isArray(list)) {
            unreadable.push(`the advisories of ${name} are not a list`);
            continue;
        }
        for (const advisory of list) {
            const github =
                isRecord(advisory) && Array.isArray(advisory.sources)
                    ? advisory.sources.find((source) => source?.name === 'GitHub')
                    : null;
            const id = isRecord(advisory)
                ? (ghsaIn(github?.remoteId) ??
                  ghsaIn(advisory.link) ??
                  (isFilled(advisory.advisoryId) ? advisory.advisoryId : null))
                : null;
            if (id === null) {
                unreadable.push(`an advisory on ${name} carries no identifier`);
                continue;
            }
            advisories.set(id, {
                id,
                package: advisory.packageName,
                severity: advisory.severity,
                title: advisory.title,
                url: advisory.link,
                ecosystem: 'composer',
            });
        }
    }
    return { advisories: [...advisories.values()], unreadable };
}

// One definition for each audit: the command that is run is the command a message names.
export const AUDITS = [
    { command: 'npm', args: ['audit', '--json'], key: 'vulnerabilities', extract: npmAdvisories },
    {
        command: 'composer',
        args: ['audit', '--locked', '--format=json'],
        key: 'advisories',
        extract: composerAdvisories,
    },
];

/**
 * Tells whether a text is a real calendar date written YYYY-MM-DD.
 *
 * Parsing alone is not enough: JavaScript reads 2026-02-30 as the 2nd of March.
 *
 * @param {unknown} text
 * @returns {boolean}
 */
export function isCalendarDate(text) {
    // Written back and compared: the one test that settles the format and the calendar together,
    // for a text as for anything else that might sit in the JSON.
    const date = new Date(`${text}T00:00:00Z`);
    return !Number.isNaN(date.getTime()) && date.toISOString().slice(0, 10) === text;
}

/**
 * Checks the shape of the accepted list. Returns the problems found.
 *
 * An acceptance without a reason or a review date is the silent dismissal this script exists to
 * replace, so a malformed list is refused rather than read leniently. An identifier is compared
 * character for character with what the audits print: one written another way would accept
 * nothing and look like it does.
 *
 * @param {unknown} accepted
 * @returns {string[]}
 */
export function validateAccepted(accepted) {
    if (!Array.isArray(accepted)) {
        return [`${ACCEPTED_FILE} must hold a list of accepted advisories.`];
    }

    const problems = [];
    const seen = new Set();
    accepted.forEach((entry, index) => {
        const label = `${ACCEPTED_FILE}, entry ${index + 1}${isRecord(entry) && isFilled(entry.id) ? ` (${entry.id})` : ''}`;
        if (!isRecord(entry)) {
            problems.push(`${label}: must be an object with ${ACCEPTED_FIELDS.join(', ')}.`);
            return;
        }
        const missing = ACCEPTED_FIELDS.filter((field) => !isFilled(entry[field]));
        if (missing.length > 0) {
            problems.push(`${label}: missing ${missing.join(', ')}.`);
            return;
        }

        const canonical = ghsaIn(entry.id) ?? entry.id.trim();
        if (entry.id !== canonical) {
            problems.push(`${label}: the identifier must be written exactly as the audits print it, ${canonical}.`);
        }
        const dated = ['acceptedOn', 'reviewBy'].filter((field) => !isCalendarDate(entry[field]));
        dated.forEach((field) => problems.push(`${label}: ${field} must be a real date written YYYY-MM-DD.`));
        if (dated.length === 0 && entry.reviewBy < entry.acceptedOn) {
            problems.push(`${label}: reviewBy comes before acceptedOn.`);
        }
        if (seen.has(entry.id)) {
            problems.push(`${label}: listed twice.`);
        }
        seen.add(entry.id);
    });
    return problems;
}

/**
 * Sorts what the audits found against what has been accepted.
 *
 * @param {{id: string}[]} found     advisories the audits report
 * @param {{id: string, reviewBy: string}[]} accepted
 * @param {string} today              YYYY-MM-DD
 * @returns {{unaccepted: object[], expired: object[], stale: object[]}}
 */
export function compare(found, accepted, today) {
    const acceptedIds = new Set(accepted.map((entry) => entry.id));
    const foundIds = new Set(found.map((advisory) => advisory.id));

    return {
        unaccepted: found.filter((advisory) => !acceptedIds.has(advisory.id)),
        // The review date is the last day the acceptance holds.
        expired: accepted.filter((entry) => entry.reviewBy < today),
        stale: accepted.filter((entry) => !foundIds.has(entry.id)),
    };
}

/**
 * Reads the advisories of one audit. Returns them, or the reason the audit did not answer.
 *
 * Both tools exit non-zero as soon as they find something, so the exit code says nothing: only
 * the JSON does. An audit that printed no JSON, a JSON without the expected key — npm answers
 * `{"error": …}` when the registry is unreachable — or a JSON the extractor could not read in
 * full has not answered.
 *
 * @param {{command: string, args: string[], key: string, extract: (report: object) => {advisories: object[], unreadable: string[]}}} definition
 * @param {(command: string, args: string[]) => {stdout?: string|null, stderr?: string|null, error?: Error}} spawn
 * @returns {{advisories: object[], problem: string|null}}
 */
export function readAudit({ command, args, key, extract }, spawn) {
    const name = [command, ...args].join(' ');
    const refuse = (detail) => ({
        advisories: [],
        problem:
            `\`${name}\` did not answer${detail ? `: ${detail}` : '.'}\n` +
            '  An audit that cannot be read is not an audit that found nothing.\n' +
            `  → run \`${name}\` by hand and read what it says.`,
    });

    // A command that could not be started, or was killed on the timeout, reports it in `error`
    // and may leave both streams null.
    const result = spawn(command, args);
    if (result.error) {
        return refuse(result.error.message);
    }

    let report;
    try {
        report = JSON.parse(result.stdout ?? '');
    } catch {
        report = null;
    }
    if (!isRecord(report) || !(key in report)) {
        const firstLine = (result.stderr ?? '').trim().split('\n')[0];
        return refuse(isFilled(report?.error?.summary) ? report.error.summary : firstLine);
    }

    const { advisories, unreadable } = extract(report);
    return unreadable.length > 0 ? refuse(unreadable.join('; ')) : { advisories, problem: null };
}

/**
 * Runs the whole audit. Returns the problems that fail it and the notices that do not.
 *
 * @param {{
 *   accepted: unknown,
 *   today: string,
 *   spawn: (command: string, args: string[]) => {stdout?: string|null, stderr?: string|null, error?: Error},
 * }} input
 * @returns {{problems: string[], notices: string[]}}
 */
export function audit({ accepted, today, spawn }) {
    const malformed = validateAccepted(accepted);
    if (malformed.length > 0) {
        return { problems: malformed, notices: [] };
    }

    const answers = AUDITS.map((definition) => readAudit(definition, spawn));
    const unanswered = answers.map((answer) => answer.problem).filter((problem) => problem !== null);
    const found = answers.flatMap((answer) => answer.advisories);

    const { unaccepted, expired, stale } = compare(found, accepted, today);

    const problems = [
        ...unanswered,
        ...unaccepted.map(
            (advisory) =>
                `${advisory.id} (${advisory.severity}) in ${advisory.package}, reported by ${advisory.ecosystem}: ${advisory.title}\n` +
                `  ${advisory.url}\n` +
                `  → update the package, or accept the advisory in ${ACCEPTED_FILE} with a reason and a review date.`,
        ),
        ...expired.map(
            (entry) =>
                `The acceptance of ${entry.id} (${entry.package}) was to be reviewed by ${entry.reviewBy}.\n` +
                `  Reason given on ${entry.acceptedOn}: ${entry.reason}\n` +
                `  → check whether it still holds, then remove the entry or give it a new review date in ${ACCEPTED_FILE}.`,
        ),
    ];

    // An audit that did not answer reports nothing, so every acceptance would look stale.
    const notices =
        unanswered.length > 0
            ? []
            : stale.map(
                  (entry) =>
                      `${entry.id} (${entry.package}) is accepted in ${ACCEPTED_FILE} but no audit reports it any more: remove its entry.`,
              );

    return { problems, notices };
}

/**
 * Reads the accepted list from disk. A file that is missing or is not JSON comes back as a value
 * `validateAccepted` refuses, with the reason, rather than as a stack trace.
 *
 * @param {string} root
 * @returns {{accepted: unknown, problem: string|null}}
 */
export function loadAccepted(root) {
    try {
        return { accepted: JSON.parse(readFileSync(join(root, ACCEPTED_FILE), 'utf8')), problem: null };
    } catch (error) {
        return { accepted: null, problem: `${ACCEPTED_FILE} could not be read: ${error.message}` };
    }
}

/**
 * Builds the function that runs an audit command from the project root, bounded in time.
 *
 * @param {string} root
 * @param {typeof spawnSync} [run]
 * @returns {(command: string, args: string[]) => object}
 */
export function spawnIn(root, run = spawnSync) {
    return (command, args) => run(command, args, { cwd: root, encoding: 'utf8', timeout: AUDIT_TIMEOUT_MS });
}

/**
 * The command line: audits the project under `root`, prints the verdict, returns the exit code.
 *
 * @param {{
 *   root: string,
 *   now: Date,
 *   env: Record<string, string|undefined>,
 *   spawn: (command: string, args: string[]) => object,
 *   out: (line: string) => void,
 *   err: (line: string) => void,
 * }} options
 * @returns {number}
 */
export function cli({ root, now, env, spawn, out, err }) {
    const { accepted, problem } = loadAccepted(root);
    const { problems, notices } =
        problem === null
            ? audit({ accepted, today: now.toISOString().slice(0, 10), spawn })
            : { problems: [problem], notices: [] };

    // GitHub Actions turns this prefix into an annotation on the run; elsewhere it is plain text.
    const notice = env.GITHUB_ACTIONS ? '::warning::' : '• ';
    notices.forEach((message) => out(`${notice}${message}`));

    if (problems.length > 0) {
        err('The advisory audit failed:\n');
        problems.forEach((message) => err(`• ${message}\n`));
        return 1;
    }
    out('No advisory outside the accepted list.');
    return 0;
}

// Run as a child process by the test suite, which is the only way to prove that the exit code
// leaves the script and that the real commands are the ones started: coverage, collected in the
// test process, cannot see this block.
/* v8 ignore next 5 */
if (process.argv[1] === fileURLToPath(import.meta.url)) {
    const root = fileURLToPath(new URL('..', import.meta.url));
    const io = { now: new Date(), env: process.env, out: console.log, err: console.error };
    process.exitCode = cli({ root, spawn: spawnIn(root), ...io });
}
