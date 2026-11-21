// PostgreSQL version — reads the two places that state it: CI's service images and the documentation.
// Imported by scripts/__tests__/postgres-version.test.js, which keeps them on the same major.
//
// CI runs the PostgreSQL major production runs (LUMN-55). The version of the hosting account is
// measured and written in docs/deployment/postgresql-version.md; CI's images are in the workflows.
// Each reader refuses what it cannot read rather than return less: a line it skipped would leave a
// version unchecked, and a document read as 0 would pass for a version.

/**
 * The postgres images of a workflow. Every `image:` line that mentions postgres, however written —
 * quoted, with its registry — must read `postgres:<major>@sha256:<digest>`, nothing else: a line
 * written another way would otherwise not be read at all, and its version go unchecked.
 *
 * @param {string} workflow
 * @returns {{ major: number, digest: string }[]}
 */
export function postgresImages(workflow) {
    const lines = [...workflow.matchAll(/^\s*image:\s*(.*?)\s*$/gm)].map(([, image]) => image);
    return lines
        .filter((image) => /postgres/i.test(image))
        .map((image) => {
            const pinned = /^postgres:(\d+)@sha256:([0-9a-f]{64})$/.exec(image);
            if (pinned === null) {
                throw new Error(`${image} is not a major pinned by digest (postgres:<major>@sha256:<digest>)`);
            }
            return { major: Number(pinned[1]), digest: pinned[2] };
        });
}

/**
 * The major the documentation measured on preprod — the hosting account's, production's too.
 *
 * @param {string} documentation
 * @returns {number}
 */
export function documentedMajor(documentation) {
    const row = /^\| Preprod \| \*\*(\d+)(?:\.\d+)*\*\* \|/m.exec(documentation);
    if (row === null) {
        throw new Error('docs/deployment/postgresql-version.md states no preprod version');
    }
    return Number(row[1]);
}
