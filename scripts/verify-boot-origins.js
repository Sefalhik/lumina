// Boot origins check — every block of the boot sequence's table, against RIPEstat.
// Usage: node scripts/verify-boot-origins.js
//
// The boot sequence shows a fictitious origin, drawn from the table of
// resources/js/utils/boot-origin.js (LUMN-122). The fiction is held to the truth: each line names a
// block its carrier really holds in its country. That claim was checked once, on 2026-11-14; this
// script is how anyone checks it again — before adding a line, or when a carrier is bought.
//
// For each block, two questions to RIPEstat, the RIPE NCC's public data service, which covers the
// other registries too:
//   1. prefix-overview    → is the block originated by exactly the AS of its line?
//   2. rir-stats-country  → is it registered in exactly the country of its line?
//
// Three outcomes, as the exit code:
//   0  every block answers both questions as its line says
//   1  at least one does not — each is named, with what RIPEstat says instead
//   2  the check could not run: RIPEstat did not answer, or answered something this script does not
//      understand. Never "nothing to report": a check that turns green on an answer it did not read
//      is worse than none.
//
// It reads public routing data only, sends nothing about anyone, and runs on a developer's machine:
// never in CI, where no test may reach the network. Its requests are spaced, out of fair use of a
// free public service.

import { fileURLToPath } from 'node:url';
import { ORIGINS } from '../resources/js/utils/boot-origin.js';

export const RIPESTAT = 'https://stat.ripe.net/data';

/** Between two requests: a few hundred blocks, asked politely. */
export const PAUSE_MS = 250;

/** An answer RIPEstat did not give in a form this script reads. */
export class UnreadableAnswer extends Error {}

/**
 * The AS numbers RIPEstat says originate a block.
 *
 * @param {unknown} answer the JSON of prefix-overview
 * @returns {number[]}
 */
export function originsIn(answer) {
    const asns = answer?.data?.asns;
    if (!Array.isArray(asns) || asns.some((entry) => !Number.isInteger(entry?.asn))) {
        throw new UnreadableAnswer('prefix-overview: no list of AS numbers in the answer');
    }
    return asns.map((entry) => entry.asn).sort((a, b) => a - b);
}

/**
 * The countries RIPEstat says a block is registered in.
 *
 * @param {unknown} answer the JSON of rir-stats-country
 * @returns {string[]}
 */
export function countriesIn(answer) {
    const resources = answer?.data?.located_resources;
    if (!Array.isArray(resources) || resources.some((entry) => typeof entry?.location !== 'string')) {
        throw new UnreadableAnswer('rir-stats-country: no list of countries in the answer');
    }
    return [...new Set(resources.map((entry) => entry.location))].sort();
}

/**
 * What is wrong with one line, if anything.
 *
 * @param {{ countryCode: string, asn: number, block: string }} origin
 * @param {(url: string) => Promise<unknown>} fetchJson
 * @returns {Promise<string[]>} one sentence per disagreement; none when the line holds
 */
export async function checkOrigin(origin, fetchJson) {
    const problems = [];
    const resource = encodeURIComponent(origin.block);

    const origins = originsIn(await fetchJson(`${RIPESTAT}/prefix-overview/data.json?resource=${resource}`));
    if (origins.length !== 1 || origins[0] !== origin.asn) {
        problems.push(
            `originated by ${origins.length ? origins.map((asn) => `AS${asn}`).join(', ') : 'no AS'}, not AS${origin.asn}`,
        );
    }

    const countries = countriesIn(await fetchJson(`${RIPESTAT}/rir-stats-country/data.json?resource=${resource}`));
    if (countries.length !== 1 || countries[0] !== origin.countryCode) {
        problems.push(
            `registered in ${countries.length ? countries.join(', ') : 'no country'}, not ${origin.countryCode}`,
        );
    }

    return problems;
}

/**
 * Checks every line, one after the other.
 *
 * @param {{ origins: readonly object[], fetchJson: Function, pause: () => Promise<void> }} io
 * @returns {Promise<{ line: object, problems: string[] }[]>} the lines that do not hold
 */
export async function verify({ origins, fetchJson, pause }) {
    const failures = [];
    for (const line of origins) {
        const problems = await checkOrigin(line, fetchJson);
        if (problems.length > 0) failures.push({ line, problems });
        await pause();
    }
    return failures;
}

/**
 * GETs a URL and parses its JSON: an answer that is not a success is not read.
 *
 * @param {typeof fetch} transport
 */
export function jsonFetcher(transport) {
    return async (url) => {
        const response = await transport(url, { signal: AbortSignal.timeout(30_000) });
        if (!response.ok) throw new UnreadableAnswer(`${url} answered ${response.status}`);
        return response.json();
    };
}

/**
 * @param {{ origins: readonly object[], fetchJson: Function, pause: () => Promise<void>, out: Function, err: Function }} io
 * @returns {Promise<0 | 1 | 2>} the exit code
 */
export async function cli({ origins, fetchJson, pause, out, err }) {
    let failures;
    try {
        failures = await verify({ origins, fetchJson, pause });
    } catch (error) {
        err(`The check could not run: ${error.message}\n`);
        return 2;
    }

    if (failures.length > 0) {
        err(`${failures.length} of ${origins.length} blocks do not hold:\n`);
        for (const { line, problems } of failures) {
            err(`• ${line.countryCode} AS${line.asn} ${line.carrier} ${line.block}: ${problems.join('; ')}\n`);
        }
        return 1;
    }
    out(`All ${origins.length} blocks hold: each is originated by its AS and registered in its country.`);
    return 0;
}

// Run on a developer's machine against the real service; the test suite drives cli() with stand-ins
// instead, since no test may reach the network. Coverage, collected in the test process, cannot see
// this block.
/* v8 ignore next 8 */
if (process.argv[1] === fileURLToPath(import.meta.url)) {
    process.exitCode = await cli({
        origins: ORIGINS,
        fetchJson: jsonFetcher(fetch),
        pause: () => new Promise((resolve) => setTimeout(resolve, PAUSE_MS)),
        out: console.log,
        err: (text) => process.stderr.write(text),
    });
}
