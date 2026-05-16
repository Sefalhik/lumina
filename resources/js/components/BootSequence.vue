<script setup>
import { ref, onMounted, onUnmounted, nextTick } from 'vue';
import { useI18n } from 'vue-i18n';
import { STORAGE_KEY, fetchGeoData, toGibsonLocation, getBrowserName, getOSName } from '../utils/boot';

const { t } = useI18n();

const visible = ref(false);
const fadingOut = ref(false);
const displayedLines = ref([]);
const terminalRef = ref(null);
const timers = [];

// ─── Sequence builder ────────────────────────────────────────────────────────
// Kept here (not in utils) because it depends on browser globals and is
// tightly coupled to the display data structure.

function buildSequence(geo) {
    const now = new Date();
    const date = now.toLocaleDateString('en-US', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' });
    const time = now.toLocaleTimeString('en-US', { hour12: false });
    const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
    const os = getOSName();
    const browser = getBrowserName();
    const cores = navigator.hardwareConcurrency ?? '??';
    const memory = navigator.deviceMemory ? `${navigator.deviceMemory} GB` : 'CLASSIFIED';
    const res = `${screen.width}×${screen.height}`;
    const serial = 'ONO7-' + Math.random().toString(16).substring(2, 10).toUpperCase();

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

// ─── Core logic ──────────────────────────────────────────────────────────────

async function scrollBottom() {
    await nextTick();
    if (terminalRef.value) {
        terminalRef.value.scrollTop = terminalRef.value.scrollHeight;
    }
}

function dismiss() {
    if (fadingOut.value) return;
    fadingOut.value = true;
    timers.forEach(clearTimeout);
    sessionStorage.setItem(STORAGE_KEY, '1');
    setTimeout(() => {
        visible.value = false;
        document.body.classList.remove('booting');
    }, 700);
}

onMounted(async () => {
    if (sessionStorage.getItem(STORAGE_KEY)) return;

    document.body.classList.add('booting');
    visible.value = true;
    document.addEventListener('keydown', dismiss);

    // Fetch geo data before building sequence — typically <500ms, well before
    // [NET] lines appear at ~4.2s. Max 3s timeout with CLASSIFIED fallback.
    const geo = await fetchGeoData();
    const sequence = buildSequence(geo);

    sequence.forEach((line, i) => {
        const t = setTimeout(async () => {
            // Progress lines with a group key update in place
            if (line.group) {
                const last = displayedLines.value.at(-1);
                if (last?.group === line.group) {
                    displayedLines.value.splice(displayedLines.value.length - 1, 1, line);
                } else {
                    displayedLines.value.push(line);
                }
            } else {
                displayedLines.value.push(line);
            }
            await scrollBottom();
            if (i === sequence.length - 1) setTimeout(dismiss, 1600);
        }, line.delay);
        timers.push(t);
    });
});

onUnmounted(() => {
    document.removeEventListener('keydown', dismiss);
    timers.forEach(clearTimeout);
});
</script>

<template>
    <Transition name="boot-fade">
        <div v-if="visible" class="boot-overlay" aria-hidden="true" @click="dismiss">
            <div ref="terminalRef" class="boot-terminal">
                <div
                    v-for="(line, i) in displayedLines"
                    :key="line.group ? line.group : i"
                    :class="['boot-line', `boot-line--${line.type}`]"
                >
                    {{ line.text }}
                </div>
                <span class="boot-cursor">█</span>
            </div>
            <div class="boot-skip">{{ t('boot.skip') }}</div>
        </div>
    </Transition>
</template>

<style scoped lang="scss">
.boot-overlay {
    cursor: pointer;
    display: flex;
    flex-direction: column;
    font-family: 'Share Tech Mono', monospace;
    font-size: clamp(0.65rem, 1.4vw, 0.85rem);
    inset: 0;
    justify-content: flex-end;
    line-height: 1.75;
    overflow: hidden;
    padding: 2rem clamp(1rem, 5vw, 4rem);
    position: fixed;
    z-index: 10000;
    background: var(--color-base-100);

    // Scanlines
    &::before {
        background: repeating-linear-gradient(
            to bottom,
            transparent 0,
            transparent 3px,
            color-mix(in oklch, var(--color-primary) 100%, transparent) 3px,
            color-mix(in oklch, var(--color-primary) 100%, transparent) 4px
        );
        content: '';
        inset: 0;
        opacity: 0.04;
        pointer-events: none;
        position: absolute;
        z-index: 1;
    }
}

.boot-terminal {
    max-height: 87vh;
    overflow-y: auto;
    position: relative;
    scrollbar-width: none;
    z-index: 2;

    &::-webkit-scrollbar {
        display: none;
    }
}

.boot-line {
    color: var(--color-base-content);
    white-space: pre;

    &--header {
        color: var(--color-primary);
        font-size: 1.05em;
        letter-spacing: 0.04em;
    }

    &--divider {
        color: var(--color-primary);
        opacity: 0.35;
    }

    &--blank {
        height: 0.5em;
        user-select: none;
    }

    &--ok {
        color: var(--color-success);
    }
    &--info {
        color: var(--color-info);
    }
    &--auth {
        color: var(--color-primary);
    }
    &--warn {
        color: var(--color-warning);
    }
    &--progress {
        color: var(--color-info);
    }

    &--ice-ok {
        color: var(--color-success);
        font-weight: bold;
    }

    &--success {
        color: var(--color-primary);
        font-size: 1.1em;
        letter-spacing: 0.1em;
    }
}

.boot-cursor {
    animation: blink 1s step-end infinite;
    color: var(--color-primary);
    position: relative;
    z-index: 2;
}

.boot-skip {
    color: var(--color-base-content);
    font-size: 0.7em;
    letter-spacing: 0.25em;
    margin-top: 1.25rem;
    opacity: 0.25;
    position: relative;
    text-align: right;
    text-transform: uppercase;
    z-index: 2;
}

.boot-fade-leave-active {
    transition: opacity 0.7s ease;
}

.boot-fade-leave-to {
    opacity: 0;
}

@keyframes blink {
    0%,
    100% {
        opacity: 1;
    }

    50% {
        opacity: 0;
    }
}
</style>
