// @vitest-environment node
import { describe, it, expect, afterEach } from 'vitest';
import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { JOURNAL, callsIn, fillPath, locate } from './support/sandbox.js';

// .github/workflows/deploy.yml holds the one credential that reaches a server. Two kinds of test:
// what the file declares, read as text; and what its step does, by running the very script the
// file contains against a stand-in for ssh. See LUMN-50.
const inRepository = (path) => fileURLToPath(new URL(`../../${path}`, import.meta.url));
const WORKFLOWS = '.github/workflows';
const WORKFLOW = readFileSync(inRepository(`${WORKFLOWS}/deploy.yml`), 'utf8');

// The script of the step, as the runner would receive it: the block under `run: |`, unindented.
function stepScript() {
    const lines = WORKFLOW.split('\n');
    const start = lines.findIndex((line) => /^\s*run: \|$/.test(line));
    expect(start, 'deploy.yml no longer has a `run: |` step').toBeGreaterThan(-1);
    expect(
        lines.filter((line) => /^\s*run:/.test(line)),
        'deploy.yml is expected to hold one script',
    ).toHaveLength(1);

    const indent = lines[start].match(/^\s*/)[0].length + 2;
    const body = [];
    for (const line of lines.slice(start + 1)) {
        if (line.trim() !== '' && line.match(/^\s*/)[0].length < indent) {
            break;
        }
        body.push(line.slice(indent));
    }
    return body.join('\n');
}

// What `on:` declares, at the first level.
const triggers = () => {
    const block = WORKFLOW.match(/^on:\n((?: {2}.*\n|\n)+)/m);
    return block ? [...block[1].matchAll(/^ {2}(\w+):/gm)].map((match) => match[1]) : [];
};

const KEY = '-----BEGIN OPENSSH PRIVATE KEY-----\nNOT-A-REAL-KEY\n-----END OPENSSH PRIVATE KEY-----';
const KNOWN_HOSTS = 'ssh-example.alwaysdata.net ssh-ed25519 AAAANOTAREALHOSTKEY';
const SHA = '0123456789abcdef0123456789abcdef01234567';

// Records how it was called, and what the two files it was pointed at held at that moment: they
// are gone by the time the step has ended, which is the point.
const SSH = `${JOURNAL}
while (( $# )); do
  case "$1" in
    -i) printf '%s' "$(< "$2")" > "$STUB_DIR/key-seen"; stat -c '%a' "$2" > "$STUB_DIR/key-mode"; shift ;;
    -o) [[ "$2" == UserKnownHostsFile=* ]] && printf '%s' "$(< "\${2#*=}")" > "$STUB_DIR/known-hosts-seen"; shift ;;
  esac
  shift
done
printf 'PHP 8.5.99\\n'
exit "$STUB_SSH_EXIT"
`;

// The real rm, which says it was called: a step that refuses must refuse before it has written
// anything, and a file written then removed leaves the same empty directory as no file at all.
const RM = `${JOURNAL}
exec ${locate('rm')} "$@"
`;

const sandboxes = [];

afterEach(() => {
    sandboxes.splice(0).forEach((root) => rmSync(root, { recursive: true, force: true }));
});

/**
 * Runs the step the way the runner does, with `ssh` replaced.
 *
 * @param {Record<string, string>} [overrides]  what the workflow's `env:` would hold
 * @param {{sshExit?: number, flags?: string[]}} [options]
 */
function runStep(overrides = {}, { sshExit = 0, flags = ['-e'] } = {}) {
    const root = mkdtempSync(join(tmpdir(), 'deploy-workflow-'));
    sandboxes.push(root);
    const box = { bin: join(root, 'bin'), runnerTemp: join(root, 'runner-temp'), log: join(root, 'calls.log') };
    mkdirSync(box.runnerTemp);
    fillPath(box.bin, { real: ['bash', 'stat'], standins: { ssh: SSH, rm: RM } });
    writeFileSync(join(root, 'step.sh'), stepScript());

    // What the runner does with a step that names no shell: `bash -e {0}`.
    const result = spawnSync(join(box.bin, 'bash'), [...flags, join(root, 'step.sh')], {
        cwd: root,
        encoding: 'utf8',
        env: {
            PATH: box.bin,
            RUNNER_TEMP: box.runnerTemp,
            STUB_LOG: box.log,
            STUB_DIR: root,
            STUB_SSH_EXIT: String(sshExit),
            REQUEST: 'deploy',
            RELEASE_SHA: SHA,
            DEPLOY_SSH_KEY: KEY,
            DEPLOY_SSH_KNOWN_HOSTS: KNOWN_HOSTS,
            DEPLOY_SSH_HOST: 'ssh-example.alwaysdata.net',
            DEPLOY_SSH_USER: 'example',
            ...overrides,
        },
    });
    const read = (name) => (existsSync(join(root, name)) ? readFileSync(join(root, name), 'utf8') : null);

    return {
        status: result.status,
        output: result.stdout + result.stderr,
        connections: callsIn(box.log)
            .filter(([tool]) => tool === 'ssh')
            .map(([, ...args]) => args),
        removals: callsIn(box.log).filter(([tool]) => tool === 'rm'),
        keySeen: read('key-seen'),
        keyMode: read('key-mode')?.trim() ?? null,
        knownHostsSeen: read('known-hosts-seen'),
        leftOnRunner: readdirSync(box.runnerTemp),
    };
}

// Nothing was sent, and nothing was written: the key never left the environment variable.
function expectNothingSent(result) {
    expect(result.status).toBe(1);
    expect(result.connections).toEqual([]);
    expect(result.leftOnRunner).toEqual([]);
    // Refused before the cleanup was even armed: no file had been written for it to remove.
    expect(result.removals).toEqual([]);
    expect(result.output).not.toContain('NOT-A-REAL-KEY');
}

// Every key of the file down to the script, with its indentation.
const outline = () => {
    const lines = WORKFLOW.split('\n');
    return lines
        .slice(0, lines.findIndex((line) => /^\s*run: \|$/.test(line)) + 1)
        .filter((line) => /^\s*(?:- )?[\w-]+:/.test(line))
        .map((line) => line.match(/^(\s*(?:- )?[\w-]+):/)[1]);
};

describe('deploy.yml — what it declares', () => {
    it('has exactly the shape the tests below were written for', () => {
        // They read the file as text, by its indentation. A line that moves or disappears —
        // `env:`, `steps:`, `jobs:` — can leave each of them green in front of a file GitHub would
        // refuse, or run differently. This one cannot stay green.
        expect(outline()).toEqual([
            'name',
            'on',
            '  workflow_dispatch',
            '    inputs',
            '      request',
            '        description',
            '        type',
            '        options',
            '        default',
            'permissions',
            'concurrency',
            '  group',
            '  cancel-in-progress',
            'jobs',
            '  preprod',
            '    name',
            '    runs-on',
            '    environment',
            '    timeout-minutes',
            '    steps',
            '      - name',
            '        env',
            '          REQUEST',
            '          RELEASE_SHA',
            '          DEPLOY_SSH_KEY',
            '          DEPLOY_SSH_KNOWN_HOSTS',
            '          DEPLOY_SSH_HOST',
            '          DEPLOY_SSH_USER',
            '        run',
        ]);
        // And nothing follows the script: no second step, no second job.
        expect(WORKFLOW.trimEnd().endsWith(stepScript().trimEnd().split('\n').at(-1).trim())).toBe(true);
    });

    it('runs on a machine GitHub provides, in the shell GitHub chooses', () => {
        // A self-hosted runner would hold the key on a machine this project does not control, and
        // a `shell:` line can be `bash -x`, which prints every command — the key with them.
        expect(WORKFLOW).toMatch(/^ {4}runs-on: ubuntu-latest$/m);
        expect(WORKFLOW).not.toMatch(/^\s*shell:/m);
    });

    it('proposes the request that changes nothing unless told otherwise', () => {
        expect(WORKFLOW).toMatch(/^ {8}default: php-version$/m);
    });

    it('starts by hand, and from nothing a stranger can cause', () => {
        // The repository is public. pull_request_target and workflow_run are how a pull request
        // from a fork reaches secrets; a push or a schedule would deploy without anyone asking.
        expect(triggers()).toEqual(['workflow_dispatch']);
    });

    it('offers two requests, as a closed list', () => {
        const options = WORKFLOW.match(
            /^ {6}request:\n(?: {8}.*\n)*? {8}type: choice\n {8}options:\n((?: {10}- .+\n)+)/m,
        );

        expect(options, 'the `request` input is no longer a choice').not.toBeNull();
        expect([...options[1].matchAll(/- (.+)/g)].map((match) => match[1])).toEqual(['php-version', 'deploy']);
        expect(WORKFLOW.match(/^ {6}\w+:$/gm)).toEqual(['      request:']);
    });

    it('uses no action at all', () => {
        // Not even a pinned one. renovate.json5 merges action updates without approval because every
        // action is exercised by a pull request first; one used here alone never would be, and it
        // would run next to the key.
        expect(WORKFLOW).not.toMatch(/^\s*(?:- )?uses:/m);
    });

    it('asks for no permission on the repository', () => {
        expect(WORKFLOW).toMatch(/^permissions: \{\}$/m);
        expect(WORKFLOW.match(/^\s*permissions:/gm)).toHaveLength(1);
    });

    it('reads its secrets from the preprod environment', () => {
        expect(WORKFLOW).toMatch(/^ {4}environment: preprod$/m);
        expect(WORKFLOW.match(/^\s*environment:/gm)).toHaveLength(1);
    });

    it('is bounded in time, and never runs twice at once or gets cancelled halfway', () => {
        expect(WORKFLOW).toMatch(/^ {4}timeout-minutes: \d+$/m);
        expect(WORKFLOW).toMatch(/^concurrency:\n {2}group: deploy-preprod\n {2}cancel-in-progress: false$/m);
    });

    it('lets nothing through that failed', () => {
        expect(WORKFLOW).not.toMatch(/continue-on-error/);
        expect(WORKFLOW).not.toMatch(/\|\|\s*true/);
    });

    it('hands every value to the script through the environment, never as text', () => {
        // `${{ … }}` is substituted before the shell starts: inside a script, a value becomes code.
        const expressions = WORKFLOW.split('\n').filter((line) => line.includes('${{'));

        expect(stepScript()).not.toContain('${{');
        expect(expressions.map((line) => line.trim())).toEqual([
            'REQUEST: ${{ inputs.request }}',
            'RELEASE_SHA: ${{ github.sha }}',
            'DEPLOY_SSH_KEY: ${{ secrets.DEPLOY_SSH_KEY }}',
            'DEPLOY_SSH_KNOWN_HOSTS: ${{ vars.DEPLOY_SSH_KNOWN_HOSTS }}',
            'DEPLOY_SSH_HOST: ${{ vars.DEPLOY_SSH_HOST }}',
            'DEPLOY_SSH_USER: ${{ vars.DEPLOY_SSH_USER }}',
        ]);
    });

    it('never asks the network who the server is, and never traces its commands', () => {
        const script = stepScript();

        expect(script).not.toMatch(/ssh-keyscan/);
        expect(script).not.toMatch(/StrictHostKeyChecking=(?!yes\b)/);
        expect(script).not.toMatch(/set -[a-z]*x|xtrace/);
    });

    it('is the only workflow that names the deployment environment or its key', () => {
        const others = readdirSync(inRepository(WORKFLOWS)).filter((file) => file !== 'deploy.yml');

        expect(others).not.toHaveLength(0);
        others.forEach((file) => {
            const workflow = readFileSync(inRepository(`${WORKFLOWS}/${file}`), 'utf8');
            expect(workflow, file).not.toMatch(/^\s*environment:/m);
            expect(workflow, file).not.toContain('DEPLOY_SSH');
        });
    });

    it('lives in a repository where no workflow runs on pull_request_target', () => {
        readdirSync(inRepository(WORKFLOWS)).forEach((file) => {
            expect(readFileSync(inRepository(`${WORKFLOWS}/${file}`), 'utf8'), file).not.toContain(
                'pull_request_target',
            );
        });
    });
});

describe('deploy.yml — what its step does', () => {
    it('asks the server to deploy the commit the workflow runs from', () => {
        const result = runStep({ REQUEST: 'deploy' });

        expect(result.status).toBe(0);
        expect(result.connections).toHaveLength(1);
        expect(result.connections[0].slice(-2)).toEqual(['example@ssh-example.alwaysdata.net', `deploy ${SHA}`]);
    });

    it('asks for the PHP version, and relays the answer', () => {
        const result = runStep({ REQUEST: 'php-version' });

        expect(result.status).toBe(0);
        expect(result.connections[0].at(-1)).toBe('php-version');
        expect(result.output).toContain('PHP 8.5.99');
    });

    it('connects with the pinned host key and nothing else to go by', () => {
        const [options] = runStep().connections;
        const setting = (name) =>
            options.filter((option, index) => options[index - 1] === '-o' && option.startsWith(`${name}=`));

        expect(setting('StrictHostKeyChecking')).toEqual(['StrictHostKeyChecking=yes']);
        expect(setting('GlobalKnownHostsFile')).toEqual(['GlobalKnownHostsFile=/dev/null']);
        expect(setting('UserKnownHostsFile')).toHaveLength(1);
        expect(setting('BatchMode')).toEqual(['BatchMode=yes']);
        expect(setting('IdentitiesOnly')).toEqual(['IdentitiesOnly=yes']);
        expect(setting('ForwardAgent')).toEqual(['ForwardAgent=no']);
        expect(setting('ConnectTimeout')).toEqual(['ConnectTimeout=20']);
        // The runner's own ssh configuration is not read either.
        expect(options.slice(0, 2)).toEqual(['-F', '/dev/null']);
    });

    it('gives ssh the key and the host key as they were stored', () => {
        const result = runStep();

        expect(result.keySeen).toBe(KEY);
        expect(result.knownHostsSeen).toBe(KNOWN_HOSTS);
    });

    it('keeps the key readable by its owner alone while it exists', () => {
        expect(runStep().keyMode).toBe('600');
    });

    it('leaves neither the key nor the host key on the runner, and never prints the key', () => {
        const result = runStep();

        expect(result.leftOnRunner).toEqual([]);
        expect(result.output).not.toContain('NOT-A-REAL-KEY');
    });

    it('fails when the server refuses, and still removes the key', () => {
        const result = runStep({}, { sshExit: 255 });

        expect(result.status).toBe(255);
        expect(result.connections).toHaveLength(1);
        expect(result.leftOnRunner).toEqual([]);
    });

    it('connects to nothing when the key cannot be written', () => {
        // Going on after a failed write would start ssh with whatever it finds by itself.
        const result = runStep({ RUNNER_TEMP: '/nonexistent/runner-temp' });

        expect(result.status).not.toBe(0);
        expect(result.connections).toEqual([]);
        expect(result.output).not.toContain('NOT-A-REAL-KEY');
    });

    it('stops on a failure by itself, without counting on the flags the runner starts it with', () => {
        // `bash -e` is the runner's default today, and a default is a setting somebody else owns.
        const result = runStep({ RUNNER_TEMP: '/nonexistent/runner-temp' }, { flags: [] });

        expect(result.status).not.toBe(0);
        expect(result.connections).toEqual([]);
    });

    it.each([
        '',
        'shell',
        'DEPLOY',
        'deploy ',
        'deploy main',
        'deploy; id',
        'php-version; id',
        '$(id)',
        'php-version\ndeploy',
    ])('sends nothing for the request %j', (request) => {
        const result = runStep({ REQUEST: request });

        expectNothingSent(result);
        expect(result.output).toContain('::error::Unknown request');
    });

    it.each(['DEPLOY_SSH_KEY', 'DEPLOY_SSH_KNOWN_HOSTS', 'DEPLOY_SSH_HOST', 'DEPLOY_SSH_USER'])(
        'sends nothing when %s is empty, and names it',
        (setting) => {
            // An environment that is not configured yields empty strings, not an error: ssh would
            // then be started with no host key to check, or no host at all.
            const result = runStep({ [setting]: '' });

            expectNothingSent(result);
            expect(result.output).toContain(`::error::${setting} is empty`);
        },
    );
});
