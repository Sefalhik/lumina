// @vitest-environment node
import { describe, it, expect, afterEach } from 'vitest';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { stripVTControlCharacters as plain } from 'node:util';
import { JOURNAL, callsIn, fillPath } from './support/sandbox.js';

// scripts/check-deploy-key.sh measures a real server; here it measures a stand-in for ssh that
// plays a server whose restrictions are listed one by one. What is tested is the judgement: that
// each missing restriction is seen, named, and enough to fail — and that a key the server simply
// rejects is never mistaken for a key that is well restricted.
const SCRIPT = fileURLToPath(new URL('../check-deploy-key.sh', import.meta.url));

const KEY = '-----BEGIN OPENSSH PRIVATE KEY-----\nNOT-A-REAL-KEY\n-----END OPENSSH PRIVATE KEY-----\n';
const SECRET = 'DB_PASSWORD=hunter2';

// A server as OpenSSH answers one. STUB_ALLOWS lists what it wrongly grants; STUB_SERVER says
// whether it accepts the key at all. What an unrestricted key reaches is a secret on purpose.
const SSH = `${JOURNAL}
terminal=0; subsystem=0; tunnel=0; port=0
words=()
while (( $# )); do
  case "$1" in
    -F|-i|-o) shift ;;
    -W) tunnel=1; shift ;;
    -R) port=1; shift ;;
    -tt) terminal=1 ;;
    -s) subsystem=1 ;;
    -T|-N) ;;
    *) words+=("$1") ;;
  esac
  shift
done
request="\${words[*]:1}"
grants() { [[ ",$STUB_ALLOWS," == *",$1,"* ]]; }
refuse() { printf '%s\\n' "$STUB_GATE_SAYS" >&2; exit "$STUB_GATE_EXIT"; }

case "$STUB_SERVER" in
  rejects-the-key) echo 'example@host: Permission denied (publickey).' >&2; exit 255 ;;
  unknown-host) echo 'Host key verification failed.' >&2; exit 255 ;;
esac

if (( tunnel )); then
  grants tunnel && { echo 'SSH-2.0-OpenSSH_9.6'; exit 0; }
  grants tunnel-kept-open && { echo 'SSH-2.0-OpenSSH_9.6'; exec sleep 60; }
  grants tunnel-to-a-closed-port && { echo 'channel 0: open failed: connect failed: Connection refused' >&2; exit 255; }
  grants tunnel-and-says-otherwise && { echo 'administratively prohibited'; echo 'SSH-2.0-OpenSSH_9.6'; exit 255; }
  echo 'channel 0: open failed: administratively prohibited: open failed' >&2
  exit 255
fi
if (( port )); then
  grants port && exec sleep 60
  grants port-then-drops && { echo 'Connection reset by peer' >&2; exit 255; }
  grants port-and-says-otherwise && { echo 'Error: remote port forwarding failed for listen port 0' >&2; exec sleep 60; }
  echo 'Error: remote port forwarding failed for listen port 0' >&2
  exit 255
fi
(( terminal )) && ! grants terminal && echo 'PTY allocation request failed on channel 0' >&2
if (( subsystem )); then
  grants sftp && { echo "sftp-server ready, ${SECRET}"; exit 0; }
  refuse
fi
case "$request" in
  php-version) printf '%s\\n' "$STUB_PHP_SAYS"; exit "$STUB_PHP_EXIT" ;;
  '') grants shell && { echo '${SECRET}'; exit 0; }; refuse ;;
  'scp -t .') grants copy && { echo '${SECRET}'; exit 0; }; refuse ;;
  echo\\ *) grants command && { echo "\${request#echo }"; echo '${SECRET}'; exit 0; }
            [[ -n "$STUB_LEAKS_MARKER" ]] && echo "\${request#echo }" >&2
            refuse ;;
esac
refuse
`;

const PROBES = [
    ['shell', 'a shell is refused', 'a shell is NOT refused'],
    ['command', 'a command is refused', 'a command is NOT refused'],
    ['terminal', 'a terminal is not granted', 'a terminal IS granted, or the command was not refused'],
    ['sftp', 'the sftp subsystem is refused', 'the sftp subsystem is NOT refused'],
    ['copy', 'a file copy is refused', 'a file copy is NOT refused'],
    ['tunnel', 'a tunnel through the server is refused', 'a tunnel through the server is NOT refused'],
    ['port', 'a port opened on the server is refused', 'a port opened on the server is NOT refused'],
];

const sandboxes = [];

afterEach(() => {
    sandboxes.splice(0).forEach((root) => rmSync(root, { recursive: true, force: true }));
});

/**
 * Runs the check against a server described by what it wrongly grants.
 *
 * @param {object} [server]
 * @param {string[]} [server.allows]        restrictions that are missing
 * @param {string} [server.kind]            'restricted', 'rejects-the-key' or 'unknown-host'
 * @param {string} [server.phpSays]
 * @param {number} [server.phpExit]
 * @param {string} [server.gateSays]
 * @param {number} [server.gateExit]
 * @param {boolean} [server.leaksMarker]    the refusal repeats what was asked
 * @param {string[]|null} [server.args]     the arguments of the script, when not the usual three
 */
function check({
    allows = [],
    kind = 'restricted',
    phpSays = 'PHP 8.5.99',
    phpExit = 0,
    gateSays = 'Refused: this is not a request the key accepts.',
    gateExit = 1,
    leaksMarker = false,
    args = null,
} = {}) {
    const root = mkdtempSync(join(tmpdir(), 'check-deploy-key-'));
    sandboxes.push(root);
    const bin = join(root, 'bin');
    const log = join(root, 'calls.log');
    fillPath(bin, { real: ['bash', 'timeout', 'sleep'], standins: { ssh: SSH } });
    writeFileSync(join(root, 'key'), KEY);
    writeFileSync(join(root, 'known-hosts'), 'ssh-example.alwaysdata.net ssh-ed25519 AAAANOTAREALHOSTKEY\n');

    const result = spawnSync(
        join(bin, 'bash'),
        [SCRIPT, ...(args ?? ['example@ssh-example.alwaysdata.net', join(root, 'key'), join(root, 'known-hosts')])],
        {
            cwd: root,
            encoding: 'utf8',
            env: {
                PATH: bin,
                CHECK_DEPLOY_KEY_TIMEOUT: '1',
                STUB_LOG: log,
                STUB_SERVER: kind,
                STUB_ALLOWS: allows.join(','),
                STUB_PHP_SAYS: phpSays,
                STUB_PHP_EXIT: String(phpExit),
                STUB_GATE_SAYS: gateSays,
                STUB_GATE_EXIT: String(gateExit),
                STUB_LEAKS_MARKER: leaksMarker ? '1' : '',
            },
        },
    );

    return {
        root,
        status: result.status,
        stdout: plain(result.stdout),
        stderr: plain(result.stderr),
        passed:
            plain(result.stdout)
                .match(/^ {2}✔ {2}(.+)$/gm)
                ?.map((line) => line.slice(5)) ?? [],
        failed:
            plain(result.stdout)
                .match(/^ {2}✘ {2}(.+)$/gm)
                ?.map((line) => line.slice(5)) ?? [],
        connections: callsIn(log).map(([, ...args]) => args),
    };
}

describe('check-deploy-key.sh — a key that is well restricted', () => {
    it('passes the eight probes, in order, and says what the key is left with', () => {
        const result = check();

        expect(result.stderr).toBe('');
        expect(result.status).toBe(0);
        expect(result.passed).toEqual(['php-version answers', ...PROBES.map(([, refused]) => refused)]);
        expect(result.failed).toEqual([]);
        expect(result.stdout).toContain('Checking what this key can do on example@ssh-example.alwaysdata.net');
        expect(result.stdout).toContain('The key can ask for php-version and a deployment, and nothing else.');
        expect(result.connections).toHaveLength(8);
    });

    it('believes nothing about the server but the pinned host key, and offers this key alone', () => {
        const { root, connections } = check();

        connections.forEach((options) => {
            const setting = (name) =>
                options.filter((option, index) => options[index - 1] === '-o' && option.startsWith(`${name}=`));

            expect(options.slice(0, 4)).toEqual(['-F', '/dev/null', '-i', join(root, 'key')]);
            expect(setting('StrictHostKeyChecking')).toEqual(['StrictHostKeyChecking=yes']);
            expect(setting('UserKnownHostsFile')).toEqual([`UserKnownHostsFile=${join(root, 'known-hosts')}`]);
            expect(setting('GlobalKnownHostsFile')).toEqual(['GlobalKnownHostsFile=/dev/null']);
            expect(setting('IdentitiesOnly')).toEqual(['IdentitiesOnly=yes']);
            expect(setting('BatchMode')).toEqual(['BatchMode=yes']);
            expect(options).toContain('example@ssh-example.alwaysdata.net');
        });
    });

    it('asks for what each restriction is there to refuse', () => {
        const asked = check().connections.map((options) =>
            options.slice(options.indexOf('ConnectTimeout=15') + 1).join(' '),
        );

        expect(asked).toEqual([
            '-T example@ssh-example.alwaysdata.net php-version',
            '-T example@ssh-example.alwaysdata.net',
            expect.stringMatching(
                /^-T example@ssh-example\.alwaysdata\.net echo check-deploy-key-reached-a-shell-\d+$/,
            ),
            expect.stringMatching(
                /^-tt example@ssh-example\.alwaysdata\.net echo check-deploy-key-reached-a-shell-\d+$/,
            ),
            '-s example@ssh-example.alwaysdata.net sftp',
            '-T example@ssh-example.alwaysdata.net scp -t .',
            '-W 127.0.0.1:22 example@ssh-example.alwaysdata.net',
            '-N -o ExitOnForwardFailure=yes -R 127.0.0.1:0:127.0.0.1:22 example@ssh-example.alwaysdata.net',
        ]);
    });
});

describe('check-deploy-key.sh — a restriction that is missing', () => {
    it.each(PROBES)('fails when the server grants %s, and names it', (granted) => {
        // A server that runs commands runs the one sent through a terminal too: that probe asks for
        // both, and says so when it fails.
        const seen = granted === 'command' ? ['command', 'terminal'] : [granted];
        const result = check({ allows: [granted] });

        expect(result.status).toBe(1);
        expect(result.failed).toEqual(
            PROBES.filter(([name]) => seen.includes(name)).map(([, , notRefused]) => notRefused),
        );
        expect(result.passed).toEqual([
            'php-version answers',
            ...PROBES.filter(([name]) => !seen.includes(name)).map(([, refused]) => refused),
        ]);
        expect(result.stderr).toContain(`${seen.length} restriction(s) missing.`);
        expect(result.stderr).toContain('Do not give this key to the pipeline');
        expect(result.stdout).not.toContain('nothing else');
    });

    it.each(PROBES)('never shows what the key reached when the server grants %s', (granted) => {
        const result = check({ allows: [granted] });
        const shown = result.stdout + result.stderr;

        expect(shown).not.toContain('hunter2');
        expect(shown).not.toContain('SSH-2.0');
        expect(shown).not.toContain('reached-a-shell');
    });

    it('counts every missing restriction on a key with none', () => {
        const result = check({ allows: PROBES.map(([name]) => name) });

        expect(result.status).toBe(1);
        expect(result.failed).toEqual(PROBES.map(([, , notRefused]) => notRefused));
        expect(result.stderr).toContain('7 restriction(s) missing.');
    });

    it.each([
        ['is granted and kept open', 'tunnel-kept-open'],
        ['is granted towards a port nothing listens on', 'tunnel-to-a-closed-port'],
        ['is granted while something says "prohibited"', 'tunnel-and-says-otherwise'],
    ])('fails when a tunnel %s: a failure is not a refusal', (_, granted) => {
        // The second one is what an allowed tunnel looks like whenever its far end is closed: a
        // non-zero exit, no banner. Counting that as refused would pass a key with no `restrict`.
        const result = check({ allows: [granted] });

        expect(result.status).toBe(1);
        expect(result.failed).toEqual(['a tunnel through the server is NOT refused']);
    });

    it.each([
        ['is granted and the session then drops', 'port-then-drops'],
        ['is granted while ssh reports another forwarding as failed', 'port-and-says-otherwise'],
    ])('fails when a port %s', (_, granted) => {
        const result = check({ allows: [granted] });

        expect(result.status).toBe(1);
        expect(result.failed).toEqual(['a port opened on the server is NOT refused']);
    });

    it("does not take any refusal for the gate's: the exit code has to be the gate's too", () => {
        // sshd closing the connection, or a shell failing on its own, also "refuses".
        const result = check({ gateExit: 255 });

        expect(result.status).toBe(1);
        expect(result.failed).toEqual(PROBES.slice(0, 5).map(([, , notRefused]) => notRefused));
    });

    it('does not take a failure for a refusal when the gate did not say so', () => {
        const result = check({ gateSays: 'bash: line 1: echo: command not found' });

        expect(result.status).toBe(1);
        expect(result.failed).toEqual(PROBES.slice(0, 5).map(([, , notRefused]) => notRefused));
    });

    it('fails when a refusal comes with what was asked: something read it and ran with it', () => {
        const result = check({ leaksMarker: true });

        expect(result.status).toBe(1);
        expect(result.failed).toEqual([PROBES[1][2], PROBES[2][2]]);
    });
});

describe('check-deploy-key.sh — a key that does not get through', () => {
    it.each([
        ['the server rejects the key', { kind: 'rejects-the-key' }],
        ['the host key is not the pinned one', { kind: 'unknown-host' }],
        ['php-version answers something else', { phpSays: 'Welcome to this server' }],
        ['php-version answers nothing', { phpSays: '' }],
        ['php-version names PHP without a version', { phpSays: 'PHP is not installed' }],
        ['php-version fails after answering', { phpExit: 1 }],
        [
            'the gate refuses php-version too',
            { phpSays: 'Refused: this is not a request the key accepts.', phpExit: 1 },
        ],
    ])('stops at the first probe when %s, instead of counting seven refusals', (_, server) => {
        // Every other probe expects a refusal, and a key that is not accepted is refused everything.
        const result = check(server);

        expect(result.status).toBe(1);
        expect(result.stderr).toContain('php-version did not answer');
        expect(result.stderr).toContain('Nothing else was tried');
        expect(result.connections).toHaveLength(1);
        expect(result.passed).toEqual([]);
        expect(result.stdout).not.toContain('nothing else');
    });
});

describe('check-deploy-key.sh — how it is called', () => {
    it.each([
        ['no argument', []],
        ['the server alone', ['example@host']],
        ['no known hosts file', ['example@host', '/etc/hostname']],
        ['a fourth argument', ['example@host', '/etc/hostname', '/etc/hostname', 'extra']],
    ])('explains itself and connects to nothing with %s', (_, args) => {
        const result = check({ args });

        expect(result.status).toBe(2);
        expect(result.stderr).toContain(
            'Usage: bash scripts/check-deploy-key.sh <user@host> <private key file> <known hosts file>',
        );
        expect(result.connections).toEqual([]);
    });

    it.each([
        ['the key', ['example@host', '/nonexistent/key', '/etc/hostname'], '/nonexistent/key is not a file.'],
        [
            'the known hosts',
            ['example@host', '/etc/hostname', '/nonexistent/hosts'],
            '/nonexistent/hosts is not a file.',
        ],
        ['the key, a directory', ['example@host', '/etc', '/etc/hostname'], '/etc is not a file.'],
    ])('connects to nothing when %s cannot be read', (_, args, message) => {
        const result = check({ args });

        expect(result.status).toBe(2);
        expect(result.stderr).toContain(message);
        expect(result.connections).toEqual([]);
    });
});
