/**
 * The boot sequence (LUMN-124): what it shows, how its lines pile up, and when it plays.
 *
 * Moved out of BootSequence.vue, which is glue only now. Everything the sequence reads of the
 * browser — the clock, the user agent, the screen, the random source, the storage, the timers —
 * is handed to it, so that it is tested with stand-ins (__tests__/boot-sequence.test.js), on the
 * model of dead-channel-controller.js.
 *
 * Its one rule: the sequence is decoration, so nothing it does may keep the page from the visitor.
 * Whatever happens to the storage, a dismissed sequence leaves.
 */
import { getBrowserName, getOSName, STORAGE_KEY, toGibsonLocation } from './boot.js';
import { readStored, storeQuietly } from './storage.js';

/** The fade-out, in step with the `boot-fade` transition of BootSequence.vue. */
export const FADE_MS = 700;

/** How long the last line stays before the sequence leaves on its own. */
export const LINGER_MS = 1600;

/**
 * @typedef {object} BootEnvironment
 * @property {Date} now
 * @property {string} timeZone            the visitor's, as Intl resolves it
 * @property {string} userAgent
 * @property {number | undefined} hardwareConcurrency
 * @property {number | undefined} deviceMemory
 * @property {{ width: number, height: number }} screen
 * @property {() => number} random        in [0, 1), as Math.random
 */

/**
 * A deck serial: eight hexadecimal digits, always eight. Reading them off a random fraction, as
 * the sequence once did, gave an empty serial when the fraction was 0.
 *
 * @param {() => number} random
 */
export function deckSerial(random) {
    return (
        'ONO7-' +
        Math.floor(random() * 0x100000000)
            .toString(16)
            .toUpperCase()
            .padStart(8, '0')
    );
}

/**
 * Every line of the sequence, with the moment it appears.
 *
 * @param {object | null} geo  what the origin lookup answered, or null
 * @param {BootEnvironment} environment
 * @returns {{ text: string, type: string, delay: number, group?: string }[]}
 */
export function buildSequence(geo, environment) {
    const { now, timeZone } = environment;
    const date = now.toLocaleDateString('en-US', {
        weekday: 'short',
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        timeZone,
    });
    const time = now.toLocaleTimeString('en-US', { hour12: false, timeZone });
    const tz = timeZone;
    const os = getOSName(environment.userAgent);
    const browser = getBrowserName(environment.userAgent);
    const cores = environment.hardwareConcurrency ?? '??';
    const memory = environment.deviceMemory ? `${environment.deviceMemory} GB` : 'CLASSIFIED';
    const res = `${environment.screen.width}×${environment.screen.height}`;
    const serial = deckSerial(environment.random);

    const { location, maskedIp, carrier } = toGibsonLocation(geo);
    const originNode = location.split(' / ')[1] ?? location;

    return [
        // ── Header ───────────────────────────────────────────────────────────
        { text: 'ONO-SENDAI CYBERSPACE 7  —  DECK v4.1.REV-C', type: 'header', delay: 0 },
        { text: 'BAMA GRID / CONURB SECTOR 7  —  NODE INIT', type: 'header', delay: 250 },
        { text: '══════════════════════════════════════════════════════', type: 'divider', delay: 500 },
        { text: '', type: 'blank', delay: 620 },

        // ── BIOS ─────────────────────────────────────────────────────────────
        { text: '[BIOS] POST initiated...', type: 'normal', delay: 850 },
        { text: `[BIOS] Deck serial       : ${serial}`, type: 'normal', delay: 1150 },
        { text: '[BIOS] Neural jack       : SLOTTED           [  OK  ]', type: 'ok', delay: 1450 },
        { text: '[BIOS] Matrix port       : ACTIVE            [  OK  ]', type: 'ok', delay: 1700 },
        { text: '[BIOS] Simstim unit      : STANDBY', type: 'normal', delay: 1950 },
        { text: '', type: 'blank', delay: 2050 },

        // ── SYS info ─────────────────────────────────────────────────────────
        { text: `[SYS ] Date              : ${date}`, type: 'info', delay: 2300 },
        { text: `[SYS ] Time              : ${time}  /  TZ: ${tz}`, type: 'info', delay: 2550 },
        { text: `[SYS ] Platform          : ${os}`, type: 'info', delay: 2800 },
        { text: `[SYS ] Interface         : ${browser}`, type: 'info', delay: 3050 },
        { text: `[SYS ] CPU cores         : ${cores}`, type: 'info', delay: 3300 },
        { text: `[SYS ] RAM               : ${memory}`, type: 'info', delay: 3550 },
        { text: `[SYS ] Display           : ${res}`, type: 'info', delay: 3800 },
        { text: '', type: 'blank', delay: 3920 },

        // ── NET — real geo data mapped to Gibson universe ─────────────────────
        { text: '[NET ] Probing origin node...', type: 'normal', delay: 4200 },
        { text: `[NET ] Source IP  : ${maskedIp}`, type: 'info', delay: 4500 },
        { text: `[NET ] Origin     : ${location}`, type: 'info', delay: 4800 },
        { text: `[NET ] Carrier    : ${carrier}`, type: 'info', delay: 5100 },
        { text: '', type: 'blank', delay: 5200 },
        { text: '[NET ] Routing to BAMA GRID...', type: 'normal', delay: 5450 },
        { text: `[NET ] Path      : ${originNode}→CHIBA_RELAY→FREESIDE_PROXY→DEST`, type: 'normal', delay: 5750 },
        { text: '[NET ] Latency   : 0.003ms                   [  OK  ]', type: 'ok', delay: 6050 },
        { text: '[NET ] Handshake with Wintermute node... REFUSED', type: 'warn', delay: 6450 },
        { text: '[NET ] Rerouting via alternate uplink...      [  OK  ]', type: 'ok', delay: 6950 },
        { text: '', type: 'blank', delay: 7050 },

        // ── ICE cracking ─────────────────────────────────────────────────────
        { text: '[ICE ] Black ICE detected : TESSIER-ASHPOOL SEC v4.1', type: 'warn', delay: 7350 },
        { text: '[ICE ] Loading Kuang Grade Mark Eleven...', type: 'auth', delay: 7850 },
        { text: '[ICE ] ░░░░░░░░░░░░░░░░░░░░   0%  —  PROBING DEFENSES', type: 'progress', delay: 8250, group: 'ice' },
        { text: '[ICE ] ████░░░░░░░░░░░░░░░░  20%  —  ANALYZING VECTORS', type: 'progress', delay: 8700, group: 'ice' },
        {
            text: '[ICE ] ████████░░░░░░░░░░░░  40%  —  EXPLOITING NULL-REF',
            type: 'progress',
            delay: 9200,
            group: 'ice',
        },
        { text: '[ICE ] ████████████░░░░░░░░  60%  —  FRAGMENTING ICE', type: 'progress', delay: 9750, group: 'ice' },
        {
            text: '[ICE ] ████████████████░░░░  80%  —  INJECTING PAYLOAD',
            type: 'progress',
            delay: 10350,
            group: 'ice',
        },
        { text: '[ICE ] ████████████████████  100% —  ICE BREACHED', type: 'ice-ok', delay: 10950, group: 'ice' },
        { text: '[ICE ] Tessier-Ashpool security layer neutralized.', type: 'ok', delay: 11350 },
        { text: '', type: 'blank', delay: 11450 },

        // ── AUTH ─────────────────────────────────────────────────────────────
        { text: '[AUTH] Personnel file decrypted.', type: 'auth', delay: 11750 },
        { text: '[AUTH] Handle    : COWBOY  —  SPRAWL OPERATIVE', type: 'auth', delay: 12050 },
        { text: '[AUTH] Sector    : BAMA  /  CONURB EAST', type: 'auth', delay: 12350 },
        { text: '[AUTH] Identity  : SEFALHIK', type: 'auth', delay: 12650 },
        { text: '[AUTH] Clearance : ALPHA-ONE                 [  OK  ]', type: 'ok', delay: 12950 },
        { text: '', type: 'blank', delay: 13050 },

        // ── End ──────────────────────────────────────────────────────────────
        { text: '══════════════════════════════════════════════════════', type: 'divider', delay: 13300 },
        { text: '  CONNECTION ESTABLISHED  —  JACKED IN', type: 'success', delay: 13750 },
        { text: '  WELCOME BACK TO THE SPRAWL, COWBOY.', type: 'success', delay: 14200 },
        { text: '══════════════════════════════════════════════════════', type: 'divider', delay: 14650 },
    ];
}

/**
 * The lines on screen once one more has arrived. A progress line replaces the one before it when
 * both belong to the same group — a bar that fills in place — and is appended otherwise.
 *
 * @template {{ group?: string }} Line
 * @param {Line[]} lines
 * @param {Line} line
 * @returns {Line[]} a new array; the one given is left as it was
 */
export function appendLine(lines, line) {
    const last = lines.at(-1);
    if (line.group && last?.group === line.group) return [...lines.slice(0, -1), line];
    return [...lines, line];
}

/**
 * @typedef {object} BootSequenceDependencies
 * @property {() => { getItem: (key: string) => string | null, setItem: (key: string, value: string) => void }} storage
 *   the session's storage; reaching it, reading it or writing it may throw
 * @property {() => 'on' | 'off'} motion                  whether animations run (utils/motion.js)
 * @property {() => Promise<object | null>} loadGeo       the origin lookup
 * @property {() => BootEnvironment} environment
 * @property {{ setTimeout: Function, clearTimeout: Function }} view
 * @property {{ addEventListener: Function, removeEventListener: Function }} doc  where keys are pressed
 * @property {() => void} onShow                          the overlay appears
 * @property {(lines: object[]) => void} onLines          the lines on screen changed
 * @property {() => void} onHide                          the overlay is gone, the page is the visitor's
 */

/**
 * Plays the sequence once per session — never when animations are stopped, nor when the browser
 * cannot remember having played it: a visitor whose storage is unreadable would otherwise sit
 * through it on every page (Laurent's decision, 2026-11-14).
 *
 * @param {BootSequenceDependencies} dependencies
 * @returns {{ start: () => Promise<boolean>, dismiss: () => void, stop: () => void }}
 */
export function createBootSequence({ storage, motion, loadGeo, environment, view, doc, onShow, onLines, onHide }) {
    /** idle, then playing, then fading, then done — or straight from idle to done. */
    let state = 'idle';
    const timers = [];

    /** A mark that cannot be read counts as seen: never played again on every page. */
    function seen() {
        const mark = readStored(storage, STORAGE_KEY);
        return mark === undefined || Boolean(mark);
    }

    function later(callback, delay) {
        timers.push(view.setTimeout(callback, delay));
    }

    function clearTimers() {
        timers.splice(0).forEach((timer) => view.clearTimeout(timer));
    }

    function dismiss() {
        if (state !== 'playing') return;
        state = 'fading';
        clearTimers();
        doc.removeEventListener('keydown', dismiss);
        // Refused, the next page plays it again: better than a page that never shows.
        storeQuietly(storage, STORAGE_KEY, '1');
        later(() => {
            state = 'done';
            onHide();
        }, FADE_MS);
    }

    async function start() {
        if (state !== 'idle') return false;
        if (seen() || motion() === 'off') {
            state = 'done';
            return false;
        }

        state = 'playing';
        onShow();
        doc.addEventListener('keydown', dismiss);

        let geo = null;
        try {
            geo = await loadGeo();
        } catch {
            // The origin is decoration too: its fallback says so.
        }
        // Dismissed, or stopped, while the lookup was answering.
        if (state !== 'playing') return true;

        const sequence = buildSequence(geo, environment());
        let lines = [];
        sequence.forEach((line, index) => {
            later(() => {
                lines = appendLine(lines, line);
                onLines(lines);
                if (index === sequence.length - 1) later(dismiss, LINGER_MS);
            }, line.delay);
        });
        return true;
    }

    /** Ends everything at once — the island is going away. A shown overlay leaves with it. */
    function stop() {
        const shown = state === 'playing' || state === 'fading';
        clearTimers();
        doc.removeEventListener('keydown', dismiss);
        state = 'done';
        if (shown) onHide();
    }

    return { start, dismiss, stop };
}
