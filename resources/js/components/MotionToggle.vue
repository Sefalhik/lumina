<script setup>
/**
 * The switch that stops the site's animations, offered to every visitor (LUMN-11, WCAG 2.2.2).
 * Glue only: the rule — a choice wins, both ways; without one, the system's preference — and what
 * can change the state under the page are utils/motion.js; the stylesheet applies the rule by
 * itself. This island shows the state and records a choice.
 *
 * A toggle button: its name says what it controls and never changes; `aria-pressed` says whether
 * animations run. The visible state is a repetition for sighted visitors, hidden from screen readers.
 */
import { onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { chooseMotion, pageMotion, toggledMotion, watchMotion } from '../utils/motion.js';

const { t } = useI18n();

const motion = ref(pageMotion(window));
let stopWatching = null;

function toggle() {
    motion.value = chooseMotion(toggledMotion(motion.value), {
        root: document.documentElement,
        storage: () => window.localStorage,
    });
}

onMounted(() => {
    stopWatching = watchMotion({
        view: window,
        root: document.documentElement,
        onChange: (next) => {
            motion.value = next;
        },
    });
});

onUnmounted(() => stopWatching?.());
</script>

<template>
    <button
        type="button"
        class="btn btn-ghost btn-sm gap-1 font-mono text-xs tracking-widest uppercase"
        :aria-pressed="motion === 'on' ? 'true' : 'false'"
        data-motion-toggle
        @click="toggle"
    >
        <span class="text-primary" aria-hidden="true">{{ motion === 'on' ? '▶' : '■' }}</span>
        <span class="sr-only sm:not-sr-only text-base-content/70">{{ t('motion_toggle.label') }}</span>
        <span class="hidden sm:inline text-base-content/70" aria-hidden="true">{{
            motion === 'on' ? t('motion_toggle.on') : t('motion_toggle.off')
        }}</span>
    </button>
</template>
