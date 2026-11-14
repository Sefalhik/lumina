import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { STORAGE_KEY, toGibsonLocation } from '../boot.js';
import { appendLine, buildSequence, createBootSequence, deckSerial, FADE_MS, LINGER_MS } from '../boot-sequence.js';

// The real toGibsonLocation, watched: one test needs a location it never produces today.
vi.mock('../boot.js', async (importOriginal) => {
    const actual = await importOriginal();
    return { ...actual, toGibsonLocation: vi.fn(actual.toGibsonLocation) };
});

const FREE_PARIS = {
    status: 'success',
    query: '82.64.1.2',
    city: 'Paris',
    countryCode: 'FR',
    region: 'IDF',
    as: 'AS12322 Free SAS',
};

/** A fixed browser: half past eleven at night in UTC is the next morning in Tokyo. */
function environment(overrides = {}) {
    return {
        now: new Date('2026-11-14T23:30:05Z'),
        timeZone: 'Asia/Tokyo',
        userAgent: 'Mozilla/5.0 (X11; Linux x86_64) Chrome/130.0 Safari/537.36',
        hardwareConcurrency: 8,
        deviceMemory: 16,
        screen: { width: 1920, height: 1080 },
        random: () => 0.5,
        ...overrides,
    };
}

const text = (lines, prefix) => lines.find((line) => line.text.startsWith(prefix))?.text;

/**
 * The whole sequence for FREE_PARIS in that browser: type, delay, group, text. Written out in
 * full — the visible sequence must not move (LUMN-124, criterion 3). Checked equal, serial aside,
 * to the one BootSequence.vue built before the move, on 2026-11-14.
 */
const SEQUENCE = [
    ['header', 0, null, 'ONO-SENDAI CYBERSPACE 7  —  DECK v4.1.REV-C'],
    ['header', 250, null, 'BAMA GRID / CONURB SECTOR 7  —  NODE INIT'],
    ['divider', 500, null, '══════════════════════════════════════════════════════'],
    ['blank', 620, null, ''],
    ['normal', 850, null, '[BIOS] POST initiated...'],
    ['normal', 1150, null, '[BIOS] Deck serial       : ONO7-80000000'],
    ['ok', 1450, null, '[BIOS] Neural jack       : SLOTTED           [  OK  ]'],
    ['ok', 1700, null, '[BIOS] Matrix port       : ACTIVE            [  OK  ]'],
    ['normal', 1950, null, '[BIOS] Simstim unit      : STANDBY'],
    ['blank', 2050, null, ''],
    ['info', 2300, null, '[SYS ] Date              : Sun, Nov 15, 2026'],
    ['info', 2550, null, '[SYS ] Time              : 08:30:05  /  TZ: Asia/Tokyo'],
    ['info', 2800, null, '[SYS ] Platform          : LINUX_X86_64'],
    ['info', 3050, null, '[SYS ] Interface         : CHROMIUM'],
    ['info', 3300, null, '[SYS ] CPU cores         : 8'],
    ['info', 3550, null, '[SYS ] RAM               : 16 GB'],
    ['info', 3800, null, '[SYS ] Display           : 1920×1080'],
    ['blank', 3920, null, ''],
    ['normal', 4200, null, '[NET ] Probing origin node...'],
    ['info', 4500, null, '[NET ] Source IP  : 82.64.███.███'],
    ['info', 4800, null, '[NET ] Origin     : EUROPEAN SPRAWL / PARIS CONURB'],
    ['info', 5100, null, '[NET ] Carrier    : FREE SAS'],
    ['blank', 5200, null, ''],
    ['normal', 5450, null, '[NET ] Routing to BAMA GRID...'],
    ['normal', 5750, null, '[NET ] Path      : PARIS CONURB→CHIBA_RELAY→FREESIDE_PROXY→DEST'],
    ['ok', 6050, null, '[NET ] Latency   : 0.003ms                   [  OK  ]'],
    ['warn', 6450, null, '[NET ] Handshake with Wintermute node... REFUSED'],
    ['ok', 6950, null, '[NET ] Rerouting via alternate uplink...      [  OK  ]'],
    ['blank', 7050, null, ''],
    ['warn', 7350, null, '[ICE ] Black ICE detected : TESSIER-ASHPOOL SEC v4.1'],
    ['auth', 7850, null, '[ICE ] Loading Kuang Grade Mark Eleven...'],
    ['progress', 8250, 'ice', '[ICE ] ░░░░░░░░░░░░░░░░░░░░   0%  —  PROBING DEFENSES'],
    ['progress', 8700, 'ice', '[ICE ] ████░░░░░░░░░░░░░░░░  20%  —  ANALYZING VECTORS'],
    ['progress', 9200, 'ice', '[ICE ] ████████░░░░░░░░░░░░  40%  —  EXPLOITING NULL-REF'],
    ['progress', 9750, 'ice', '[ICE ] ████████████░░░░░░░░  60%  —  FRAGMENTING ICE'],
    ['progress', 10350, 'ice', '[ICE ] ████████████████░░░░  80%  —  INJECTING PAYLOAD'],
    ['ice-ok', 10950, 'ice', '[ICE ] ████████████████████  100% —  ICE BREACHED'],
    ['ok', 11350, null, '[ICE ] Tessier-Ashpool security layer neutralized.'],
    ['blank', 11450, null, ''],
    ['auth', 11750, null, '[AUTH] Personnel file decrypted.'],
    ['auth', 12050, null, '[AUTH] Handle    : COWBOY  —  SPRAWL OPERATIVE'],
    ['auth', 12350, null, '[AUTH] Sector    : BAMA  /  CONURB EAST'],
    ['auth', 12650, null, '[AUTH] Identity  : SEFALHIK'],
    ['ok', 12950, null, '[AUTH] Clearance : ALPHA-ONE                 [  OK  ]'],
    ['blank', 13050, null, ''],
    ['divider', 13300, null, '══════════════════════════════════════════════════════'],
    ['success', 13750, null, '  CONNECTION ESTABLISHED  —  JACKED IN'],
    ['success', 14200, null, '  WELCOME BACK TO THE SPRAWL, COWBOY.'],
    ['divider', 14650, null, '══════════════════════════════════════════════════════'],
];

describe('the sequence', () => {
    it('is the one the overlay always showed, line for line', () => {
        const lines = buildSequence(FREE_PARIS, environment()).map((line) => [
            line.type,
            line.delay,
            line.group ?? null,
            line.text,
        ]);

        expect(lines).toEqual(SEQUENCE);
    });

    it('never schedules a line before the one above it', () => {
        const delays = buildSequence(FREE_PARIS, environment()).map((line) => line.delay);

        expect(delays).toEqual([...delays].sort((a, b) => a - b));
    });

    it("tells the date and time in the visitor's time zone", () => {
        const lines = buildSequence(null, environment({ timeZone: 'America/Los_Angeles' }));

        expect(text(lines, '[SYS ] Date')).toContain('Sat, Nov 14, 2026');
        expect(text(lines, '[SYS ] Time')).toContain('15:30:05  /  TZ: America/Los_Angeles');
    });

    it('names the platform and the browser from the user agent', () => {
        const lines = buildSequence(
            null,
            environment({
                userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:132.0) Gecko/20100101 Firefox/132.0',
            }),
        );

        expect(text(lines, '[SYS ] Platform')).toContain('WIN_NT');
        expect(text(lines, '[SYS ] Interface')).toContain('MOZILLA_FIREFOX');
    });

    it('says ?? for cores the browser does not tell, and keeps a count of zero', () => {
        expect(text(buildSequence(null, environment({ hardwareConcurrency: undefined })), '[SYS ] CPU')).toMatch(
            /: \?\?$/,
        );
        expect(text(buildSequence(null, environment({ hardwareConcurrency: 0 })), '[SYS ] CPU')).toMatch(/: 0$/);
    });

    it.each([undefined, 0])('classifies a memory the browser does not tell (%s)', (deviceMemory) => {
        expect(text(buildSequence(null, environment({ deviceMemory })), '[SYS ] RAM')).toMatch(/: CLASSIFIED$/);
    });

    it('falls back on a masked origin when the lookup answered nothing', () => {
        const lines = buildSequence(null, environment());

        expect(text(lines, '[NET ] Source IP')).toBe('[NET ] Source IP  : ███.███.███.███');
        expect(text(lines, '[NET ] Origin')).toBe('[NET ] Origin     : UNCHARTED GRID / ORIGIN MASKED');
        expect(text(lines, '[NET ] Carrier')).toBe('[NET ] Carrier    : UNKNOWN UPLINK');
        expect(text(lines, '[NET ] Path')).toContain(': ORIGIN MASKED→CHIBA_RELAY');
    });

    it('routes from the whole location when it has no node part', () => {
        toGibsonLocation.mockReturnValueOnce({ location: 'NOWHERE', maskedIp: '1.2.███.███', carrier: 'NONE' });

        expect(text(buildSequence(null, environment()), '[NET ] Path')).toContain(': NOWHERE→CHIBA_RELAY');
    });
});

describe('the deck serial', () => {
    it.each([
        [0, 'ONO7-00000000'],
        [1 / 0x100000000, 'ONO7-00000001'],
        [0.5, 'ONO7-80000000'],
        [1 - Number.EPSILON, 'ONO7-FFFFFFFF'],
    ])('is eight hexadecimal digits, even at the edges (%s)', (fraction, serial) => {
        expect(deckSerial(() => fraction)).toBe(serial);
    });
});

describe('the lines on screen', () => {
    const plain = (text) => ({ text, type: 'normal' });
    const progress = (text, group = 'ice') => ({ text, type: 'progress', group });

    it('append a plain line', () => {
        expect(appendLine([plain('a')], plain('b'))).toEqual([plain('a'), plain('b')]);
    });

    it('replace a progress line by the next one of its group: a bar that fills in place', () => {
        expect(appendLine([plain('a'), progress('0%')], progress('20%'))).toEqual([plain('a'), progress('20%')]);
    });

    it('append a progress line after a line of another group, or of none', () => {
        expect(appendLine([progress('0%', 'ice')], progress('0%', 'net'))).toHaveLength(2);
        expect(appendLine([plain('a')], progress('0%'))).toHaveLength(2);
        expect(appendLine([], progress('0%'))).toEqual([progress('0%')]);
    });

    it('append a plain line after a progress line', () => {
        expect(appendLine([progress('100%')], plain('done'))).toHaveLength(2);
    });

    it('treat an empty group as none', () => {
        expect(appendLine([{ text: 'a', group: '' }], { text: 'b', group: '' })).toHaveLength(2);
    });

    it('leave the lines they were given as they were', () => {
        const lines = [plain('a'), progress('0%')];
        const copy = structuredClone(lines);

        appendLine(lines, progress('20%'));
        appendLine(lines, plain('b'));

        expect(lines).toEqual(copy);
    });
});

/** A listener registry standing in for addEventListener / removeEventListener. */
function events() {
    const listeners = new Map();
    return {
        addEventListener: vi.fn((type, listener) => listeners.set(type, [...(listeners.get(type) ?? []), listener])),
        removeEventListener: vi.fn((type, listener) =>
            listeners.set(
                type,
                (listeners.get(type) ?? []).filter((each) => each !== listener),
            ),
        ),
        fire: (type) => (listeners.get(type) ?? []).forEach((listener) => listener({ type })),
        count: () => [...listeners.values()].reduce((total, list) => total + list.length, 0),
    };
}

/** A lookup that answers when the test says so. */
function deferred() {
    let answer;
    const loadGeo = vi.fn(
        () =>
            new Promise((resolve) => {
                answer = resolve;
            }),
    );
    return { loadGeo, answer: (value) => answer(value) };
}

/** Everything the controller is handed, as stand-ins a test can drive. */
function stage({
    stored = null,
    motion = () => 'on',
    loadGeo = vi.fn(async () => FREE_PARIS),
    storage = undefined,
} = {}) {
    const values = new Map(stored === null ? [] : [[STORAGE_KEY, stored]]);
    const store = {
        getItem: vi.fn((key) => (values.has(key) ? values.get(key) : null)),
        setItem: vi.fn((key, value) => values.set(key, value)),
    };
    const doc = events();
    const onShow = vi.fn();
    const onLines = vi.fn();
    const onHide = vi.fn();
    const makeEnvironment = vi.fn(() => environment());
    const sequence = createBootSequence({
        storage: storage ?? (() => store),
        motion,
        loadGeo,
        environment: makeEnvironment,
        view: { setTimeout: (callback, delay) => setTimeout(callback, delay), clearTimeout: (id) => clearTimeout(id) },
        doc,
        onShow,
        onLines,
        onHide,
    });
    return { sequence, store, values, doc, onShow, onLines, onHide, makeEnvironment, loadGeo };
}

/** When the last line appears. */
const LAST = SEQUENCE.at(-1)[1];

/** The sequence once every line has arrived: the six steps of the ICE bar are one line. */
const ON_SCREEN = SEQUENCE.length - 5;

beforeEach(() => {
    vi.useFakeTimers();
});

afterEach(() => {
    vi.useRealTimers();
});

describe('a first visit', () => {
    it('shows the overlay, plays every line, lingers, fades, then gives the page back', async () => {
        const { sequence, values, doc, onShow, onLines, onHide } = stage();

        expect(await sequence.start()).toBe(true);
        expect(onShow).toHaveBeenCalledTimes(1);
        expect(doc.count()).toBe(1);

        await vi.advanceTimersByTimeAsync(LAST);
        expect(onLines).toHaveBeenCalledTimes(SEQUENCE.length);
        expect(onLines.mock.lastCall[0]).toHaveLength(ON_SCREEN);

        await vi.advanceTimersByTimeAsync(LINGER_MS - 1);
        expect(values.has(STORAGE_KEY)).toBe(false);
        await vi.advanceTimersByTimeAsync(1);
        expect(values.get(STORAGE_KEY)).toBe('1');
        expect(doc.count()).toBe(0);

        await vi.advanceTimersByTimeAsync(FADE_MS - 1);
        expect(onHide).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(1);
        expect(onHide).toHaveBeenCalledTimes(1);
    });

    it('shows each line at its moment, and the ICE bar filling in place', async () => {
        const { sequence, onLines } = stage();
        await sequence.start();

        await vi.advanceTimersByTimeAsync(0);
        expect(onLines.mock.lastCall[0].map((line) => line.text)).toEqual([SEQUENCE[0][3]]);

        await vi.advanceTimersByTimeAsync(8250);
        const atZero = onLines.mock.lastCall[0];
        await vi.advanceTimersByTimeAsync(450);
        const atTwenty = onLines.mock.lastCall[0];

        expect(atTwenty).toHaveLength(atZero.length);
        expect(atZero.at(-1).text).toContain('  0%');
        expect(atTwenty.at(-1).text).toContain(' 20%');
        // Each report is a new array: what the overlay holds is never changed under it.
        expect(atTwenty).not.toBe(atZero);
    });

    it('reads the browser once the origin has answered, not before', async () => {
        const { loadGeo, answer } = deferred();
        const { sequence, makeEnvironment } = stage({ loadGeo });

        const started = sequence.start();
        await vi.advanceTimersByTimeAsync(0);
        expect(makeEnvironment).not.toHaveBeenCalled();

        answer(FREE_PARIS);
        await started;
        expect(makeEnvironment).toHaveBeenCalledTimes(1);
    });

    it('plays with the masked origin when the lookup fails outright', async () => {
        const { sequence, onLines } = stage({ loadGeo: vi.fn(async () => Promise.reject(new Error('offline'))) });

        expect(await sequence.start()).toBe(true);
        await vi.advanceTimersByTimeAsync(LAST);

        expect(onLines.mock.lastCall[0].map((line) => line.text)).toContain(
            '[NET ] Origin     : UNCHARTED GRID / ORIGIN MASKED',
        );
    });

    it('plays with the masked origin when the lookup throws before it even answers', async () => {
        const loadGeo = vi.fn(() => {
            throw new TypeError('route is not defined');
        });
        const { sequence, onLines } = stage({ loadGeo });

        expect(await sequence.start()).toBe(true);
        await vi.advanceTimersByTimeAsync(LAST);

        expect(onLines.mock.lastCall[0].map((line) => line.text)).toContain(
            '[NET ] Origin     : UNCHARTED GRID / ORIGIN MASKED',
        );
    });

    it('plays once: a second start does nothing', async () => {
        const { sequence, onShow, doc } = stage();

        await sequence.start();
        expect(await sequence.start()).toBe(false);

        expect(onShow).toHaveBeenCalledTimes(1);
        expect(doc.count()).toBe(1);
    });
});

describe('a sequence that does not play', () => {
    it('stays away once seen in this session', async () => {
        const { sequence, onShow, doc, loadGeo } = stage({ stored: '1' });

        expect(await sequence.start()).toBe(false);

        expect(onShow).not.toHaveBeenCalled();
        expect(loadGeo).not.toHaveBeenCalled();
        expect(doc.count()).toBe(0);
    });

    it('plays when the stored mark is empty: only a mark that says something counts', async () => {
        const { sequence } = stage({ stored: '' });

        expect(await sequence.start()).toBe(true);
    });

    it('stays away when animations are stopped (LUMN-11)', async () => {
        const { sequence, onShow, loadGeo } = stage({ motion: () => 'off' });

        expect(await sequence.start()).toBe(false);

        expect(onShow).not.toHaveBeenCalled();
        expect(loadGeo).not.toHaveBeenCalled();
    });

    it('stays away when the storage cannot be read: it would otherwise play on every page', async () => {
        const store = {
            getItem: () => {
                throw new DOMException('blocked', 'SecurityError');
            },
            setItem: vi.fn(),
        };
        const { sequence, onShow } = stage({ storage: () => store });

        expect(await sequence.start()).toBe(false);
        expect(onShow).not.toHaveBeenCalled();
    });

    it('stays away when the storage cannot even be reached', async () => {
        const { sequence, onShow } = stage({
            storage: () => {
                throw new DOMException('blocked', 'SecurityError');
            },
        });

        expect(await sequence.start()).toBe(false);
        expect(onShow).not.toHaveBeenCalled();
    });

    it('decides once per page: animations turned back on later do not start it', async () => {
        let motion = 'off';
        const { sequence, onShow } = stage({ motion: () => motion });
        expect(await sequence.start()).toBe(false);

        motion = 'on';

        expect(await sequence.start()).toBe(false);
        expect(onShow).not.toHaveBeenCalled();
    });

    it('cannot be dismissed before it starts', async () => {
        const { sequence, onHide, values } = stage();

        sequence.dismiss();
        await vi.advanceTimersByTimeAsync(FADE_MS);

        expect(onHide).not.toHaveBeenCalled();
        expect(values.has(STORAGE_KEY)).toBe(false);
    });
});

describe('dismissing it', () => {
    it('with a key: the lines stop, the mark is kept, the page comes back after the fade', async () => {
        const { sequence, doc, values, onLines, onHide } = stage();
        await sequence.start();
        await vi.advanceTimersByTimeAsync(1000);
        const shown = onLines.mock.calls.length;

        doc.fire('keydown');

        expect(values.get(STORAGE_KEY)).toBe('1');
        expect(doc.count()).toBe(0);
        await vi.advanceTimersByTimeAsync(FADE_MS);
        expect(onHide).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(LAST + LINGER_MS);
        expect(onLines).toHaveBeenCalledTimes(shown);
    });

    it('twice changes nothing: one fade, one goodbye', async () => {
        const { sequence, store, onHide } = stage();
        await sequence.start();

        sequence.dismiss();
        sequence.dismiss();
        await vi.advanceTimersByTimeAsync(FADE_MS * 2);

        expect(onHide).toHaveBeenCalledTimes(1);
        expect(store.setItem).toHaveBeenCalledTimes(1);
    });

    it('gives the page back even when the storage refuses the mark', async () => {
        // Before LUMN-124, a refused write stopped dismiss() before the overlay was removed: the
        // page stayed covered, and would not scroll, for good.
        const store = {
            getItem: () => null,
            setItem: () => {
                throw new DOMException('quota', 'QuotaExceededError');
            },
        };
        const { sequence, onHide, doc } = stage({ storage: () => store });
        await sequence.start();

        doc.fire('keydown');
        await vi.advanceTimersByTimeAsync(FADE_MS);

        expect(onHide).toHaveBeenCalledTimes(1);
        expect(doc.count()).toBe(0);
    });

    it('gives the page back when the storage cannot be reached any more', async () => {
        let reachable = true;
        const store = { getItem: () => null, setItem: vi.fn() };
        const { sequence, onHide } = stage({
            storage: () => {
                if (!reachable) throw new DOMException('blocked', 'SecurityError');
                return store;
            },
        });
        await sequence.start();
        reachable = false;

        sequence.dismiss();
        await vi.advanceTimersByTimeAsync(FADE_MS);

        expect(onHide).toHaveBeenCalledTimes(1);
    });

    it('while the origin is still answering: no line ever shows', async () => {
        const { loadGeo, answer } = deferred();
        const { sequence, onLines, onHide, makeEnvironment } = stage({ loadGeo });
        const started = sequence.start();

        sequence.dismiss();
        answer(FREE_PARIS);
        // It was shown: start() says so, though no line came.
        expect(await started).toBe(true);
        await vi.advanceTimersByTimeAsync(LAST + LINGER_MS + FADE_MS);

        expect(onLines).not.toHaveBeenCalled();
        expect(makeEnvironment).not.toHaveBeenCalled();
        expect(onHide).toHaveBeenCalledTimes(1);
    });
});

describe('stopping it — the island goes away', () => {
    it('while it plays: the page is given back at once, and nothing runs after', async () => {
        const { sequence, doc, onLines, onHide } = stage();
        await sequence.start();
        await vi.advanceTimersByTimeAsync(1000);
        const shown = onLines.mock.calls.length;

        sequence.stop();

        expect(onHide).toHaveBeenCalledTimes(1);
        expect(doc.count()).toBe(0);
        await vi.advanceTimersByTimeAsync(LAST + LINGER_MS + FADE_MS);
        expect(onLines).toHaveBeenCalledTimes(shown);
        expect(onHide).toHaveBeenCalledTimes(1);
    });

    it('while it fades: the page is given back once, not twice', async () => {
        const { sequence, onHide } = stage();
        await sequence.start();
        sequence.dismiss();

        sequence.stop();
        await vi.advanceTimersByTimeAsync(FADE_MS);

        expect(onHide).toHaveBeenCalledTimes(1);
    });

    it('while the origin is still answering: nothing is scheduled afterwards', async () => {
        const { loadGeo, answer } = deferred();
        const { sequence, onLines, onHide } = stage({ loadGeo });
        const started = sequence.start();

        sequence.stop();
        answer(FREE_PARIS);
        expect(await started).toBe(true);
        await vi.advanceTimersByTimeAsync(LAST + LINGER_MS + FADE_MS);

        expect(onLines).not.toHaveBeenCalled();
        expect(onHide).toHaveBeenCalledTimes(1);
    });

    it('when it never showed, or has already gone: nothing to give back', async () => {
        const never = stage({ stored: '1' });
        await never.sequence.start();
        never.sequence.stop();
        expect(never.onHide).not.toHaveBeenCalled();

        const gone = stage();
        await gone.sequence.start();
        gone.sequence.dismiss();
        await vi.advanceTimersByTimeAsync(FADE_MS);
        gone.sequence.stop();
        expect(gone.onHide).toHaveBeenCalledTimes(1);
    });

    it('a stopped sequence cannot start', async () => {
        const { sequence, onShow } = stage();

        sequence.stop();

        expect(await sequence.start()).toBe(false);
        expect(onShow).not.toHaveBeenCalled();
    });
});
