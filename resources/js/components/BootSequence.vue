<script setup>
/**
 * The boot sequence's overlay (LUMN-124). Glue only: what it shows, when it plays, when it leaves
 * and what it does when the browser refuses its storage are utils/boot-sequence.js. This file hands
 * the controller the browser, and shows what it reports.
 */
import { ref, onMounted, onUnmounted, nextTick } from 'vue';
import { useI18n } from 'vue-i18n';
import { fetchGeoData } from '../utils/boot';
import { createBootSequence } from '../utils/boot-sequence.js';
import { pageMotion } from '../utils/motion.js';

const { t } = useI18n();

const visible = ref(false);
const displayedLines = ref([]);
const terminalRef = ref(null);

async function scrollBottom() {
    await nextTick();
    if (terminalRef.value) {
        terminalRef.value.scrollTop = terminalRef.value.scrollHeight;
    }
}

const sequence = createBootSequence({
    storage: () => window.sessionStorage,
    motion: () => pageMotion(window),
    loadGeo: fetchGeoData,
    environment: () => ({
        now: new Date(),
        timeZone: Intl.DateTimeFormat().resolvedOptions().timeZone,
        userAgent: navigator.userAgent,
        hardwareConcurrency: navigator.hardwareConcurrency,
        deviceMemory: navigator.deviceMemory,
        screen: { width: screen.width, height: screen.height },
        random: Math.random,
    }),
    view: window,
    doc: document,
    onShow: () => {
        document.body.classList.add('booting');
        visible.value = true;
    },
    onLines: (lines) => {
        displayedLines.value = lines;
        scrollBottom();
    },
    onHide: () => {
        visible.value = false;
        document.body.classList.remove('booting');
    },
});

onMounted(() => sequence.start());

onUnmounted(() => sequence.stop());
</script>

<template>
    <Transition name="boot-fade">
        <div v-if="visible" class="boot-overlay" aria-hidden="true" @click="sequence.dismiss()">
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
