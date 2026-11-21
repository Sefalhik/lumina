// @vitest-environment node
import { describe, expect, it } from 'vitest';
import { readdirSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { documentedMajor, postgresImages } from '../postgres-version.js';

/**
 * CI runs the PostgreSQL major production runs (LUMN-55). Production is the reference; the version
 * of the hosting account is written in docs/deployment/postgresql-version.md, measured, and CI's
 * service images in .github/workflows/ci.yml. Two places, and nothing but this test makes them agree:
 * a CI on another major would stay green while testing a runtime nobody deploys. The readers live in
 * scripts/postgres-version.js; their refusals are proven here first, on fixed input.
 */

const repository = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const read = (path) => readFileSync(resolve(repository, path), 'utf8');

const DIGEST = 'a'.repeat(64);

describe('postgresImages', () => {
    it('reads every postgres service image, its major and its digest', () => {
        const workflow = `services:\n  postgres:\n    image: postgres:18@sha256:${DIGEST}\n  other:\n    image: redis:7\n`;

        expect(postgresImages(workflow)).toEqual([{ major: 18, digest: DIGEST }]);
    });

    it.each([
        ['a tag without digest', 'postgres:18'],
        ['a floating tag', `postgres:latest@sha256:${DIGEST}`],
        ['a minor in the tag', `postgres:18.7@sha256:${DIGEST}`],
        ['a short digest', 'postgres:18@sha256:abc'],
        ['a quoted image', `"postgres:18@sha256:${DIGEST}"`],
        ['a registry prefix', `docker.io/library/postgres:18@sha256:${DIGEST}`],
        ['another case', `Postgres:18@sha256:${DIGEST}`],
    ])('refuses %s', (_case, image) => {
        expect(() => postgresImages(`    image: ${image}\n`)).toThrow('pinned by digest');
    });
});

describe('documentedMajor', () => {
    it.each([['18.7'], ['18'], ['18.7.1']])('reads the major of the measured preprod version %s', (version) => {
        expect(documentedMajor(`| Preprod | **${version}** | \`php artisan db:show\` |\n`)).toBe(18);
    });

    it('reads the preprod row, not the version of another environment above it', () => {
        const documentation =
            '| Developer machine | **19.0** | `pg_lsclusters` |\n| Preprod | **18.7** | `db:show` |\n';

        expect(documentedMajor(documentation)).toBe(18);
    });

    it('reads the row that starts with Preprod, not one that mentions it in a later cell', () => {
        const documentation = '| Note | Preprod | **17.0** | moved |\n| Preprod | **18.7** | `db:show` |\n';

        expect(documentedMajor(documentation)).toBe(18);
    });

    it('refuses a document whose only version is another environment\u2019s', () => {
        expect(() => documentedMajor('| Developer machine | **19.0** | `pg_lsclusters` |\n')).toThrow(
            'no preprod version',
        );
    });

    it('refuses a document that states none, rather than read it as 0', () => {
        expect(() => documentedMajor('| Production | **no database yet** |\n')).toThrow('no preprod version');
    });
});

describe('the repository', () => {
    // Every workflow: a PostgreSQL service added to another one is held to the same version.
    const workflows = readdirSync(resolve(repository, '.github/workflows')).filter((name) => /\.ya?ml$/.test(name));
    const images = workflows.flatMap((name) => postgresImages(read(`.github/workflows/${name}`)));

    it('runs every CI job on one and the same PostgreSQL image', () => {
        expect(workflows).toContain('ci.yml');
        expect(images.length).toBeGreaterThan(0);
        expect(new Set(images.map(({ major, digest }) => `${major}@${digest}`)).size).toBe(1);
    });

    it('runs CI on the major production runs', () => {
        expect(images[0].major).toBe(documentedMajor(read('docs/deployment/postgresql-version.md')));
    });
});
