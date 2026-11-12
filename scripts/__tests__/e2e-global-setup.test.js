// @vitest-environment node
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * tests/e2e/global-setup.js drops and recreates databases. It is tested here, and mutated here,
 * with child_process replaced: nothing runs for real.
 *
 * On 2026-11-12 a line-deletion pass ran this file for real, through Playwright. Deleting the line
 * that passed the environment let `migrate:fresh --force` read .env, and wiped the developer's
 * database — while the suite stayed green. These tests are what notices that deletion now, and the
 * only place this file may be mutated (docs/testing-conventions.md, "The mechanical pass").
 */
vi.mock('child_process', () => ({ execFileSync: vi.fn() }));

const { execFileSync } = await import('child_process');
const { default: globalSetup, artisanEnv } = await import('../../tests/e2e/global-setup.js');
const { databaseFor, slots, TEMPLATE_DATABASE } = await import('../../tests/e2e/helpers/workers.js');

const shellDatabase = process.env.DB_DATABASE;
afterEach(() => {
    if (shellDatabase === undefined) {
        delete process.env.DB_DATABASE;
    } else {
        process.env.DB_DATABASE = shellDatabase;
    }
});

it('runs against a replaced child_process, never the real one', () => {
    expect(vi.isMockFunction(execFileSync)).toBe(true);
});

/** @returns {{ args: string[], env: Record<string, string> | undefined }[]} */
function artisanCalls() {
    return execFileSync.mock.calls.map(([command, args, options]) => {
        expect(command).toBe('php');
        expect(args[0]).toBe('artisan');
        return { args: args.slice(1), env: options?.env };
    });
}

describe('global-setup.js', () => {
    beforeEach(() => {
        execFileSync.mockReset();
        // What a developer's shell may well hold: it must never decide the database.
        process.env.DB_DATABASE = 'cardascia_it';
        globalSetup();
    });

    it('passes an explicit database to every artisan call', () => {
        const calls = artisanCalls();

        expect(calls.length).toBeGreaterThan(0);
        for (const { args, env } of calls) {
            // Without an env, artisan reads .env: the developer's database.
            expect(env, `artisan ${args[0]} ran without an explicit environment`).toBeDefined();
            expect([TEMPLATE_DATABASE, 'postgres'], `artisan ${args[0]} on ${env.DB_DATABASE}`).toContain(
                env.DB_DATABASE,
            );
        }
    });

    it('migrates and seeds the template, and only the template', () => {
        const destructive = artisanCalls().filter(({ args }) => args[0] === 'migrate:fresh' || args[0] === 'db:seed');

        expect(destructive.map(({ args }) => args.join(' '))).toEqual([
            'migrate:fresh --force',
            'db:seed --class=E2eSiteIdentitySeeder --force',
            'db:seed --class=HomepageContentSeeder --force',
            'db:seed --class=E2eExperienceSeeder --force',
        ]);
        for (const { env } of destructive) {
            expect(env.DB_DATABASE).toBe(TEMPLATE_DATABASE);
        }
    });

    it('migrates before it seeds, and copies only once the template is ready', () => {
        const order = artisanCalls().map(({ args }) => args[0]);

        expect(order[0]).toBe('migrate:fresh');
        expect(order.at(-1)).toBe('tinker');
        expect(order.filter((command) => command === 'tinker')).toHaveLength(1);
    });

    it('drops and recreates exactly the slot databases, from the maintenance database', () => {
        const [copy] = artisanCalls().filter(({ args }) => args[0] === 'tinker');
        const code = copy.args[2];
        const dropped = [...code.matchAll(/DROP DATABASE IF EXISTS "([^"]+)"/g)].map((match) => match[1]);
        const created = [...code.matchAll(/CREATE DATABASE "([^"]+)" TEMPLATE "([^"]+)"/g)];

        expect(copy.env.DB_DATABASE).toBe('postgres');
        expect(dropped).toEqual(slots().map(databaseFor));
        expect(created.map((match) => match[1])).toEqual(slots().map(databaseFor));
        expect(new Set(created.map((match) => match[2]))).toEqual(new Set([TEMPLATE_DATABASE]));
        // Never the template, never the developer's database, never anything else.
        expect(dropped).not.toContain(TEMPLATE_DATABASE);
        expect(dropped).not.toContain('cardascia_it');
    });
});

describe('artisanEnv()', () => {
    it.each([['cardascia_it'], ['cardascia_it_test'], [''], [undefined], ['cardascia_it_e2e_0']])(
        'refuses %s before anything runs',
        (database) => {
            expect(() => artisanEnv(database)).toThrow(/refuses to run artisan/);
        },
    );

    it('overrides whatever database the shell holds', () => {
        process.env.DB_DATABASE = 'cardascia_it';

        expect(artisanEnv(TEMPLATE_DATABASE).DB_DATABASE).toBe(TEMPLATE_DATABASE);
        expect(artisanEnv('postgres').DB_DATABASE).toBe('postgres');
    });
});
