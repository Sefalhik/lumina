<script setup>
/**
 * The dead channel's snow behind Sprawl's pages (LUMN-103). Glue only: when it runs, what it
 * re-reads and how it fails are decided by utils/dead-channel-controller.js; the grain by
 * utils/dead-channel.js. This file hands the controller the browser — and the two things only a
 * browser can do: read a theme colour, and watch the theme attribute.
 *
 * `data-frames` counts the frames drawn: the end-to-end tests read it to prove the snow stops.
 */
import { onBeforeUnmount, onMounted, useTemplateRef } from 'vue';
import { createDeadChannel } from '../utils/dead-channel-controller.js';
import { colourChannels } from '../utils/dead-channel.js';

const canvas = useTemplateRef('canvas');
let snow = null;

/** A theme colour as 0–255 channels; one the browser cannot draw throws, and stops the snow. */
function readColour(token) {
    const probe = document.createElement('canvas').getContext('2d', { willReadFrequently: true });
    return colourChannels(getComputedStyle(document.documentElement).getPropertyValue(token).trim(), probe);
}

function observeTheme(callback) {
    const observer = new MutationObserver(callback);
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    return () => observer.disconnect();
}

onMounted(() => {
    snow = createDeadChannel({
        canvas: canvas.value,
        view: window,
        doc: document,
        readColour,
        observeTheme,
        random: Math.random,
        onError: (error) => console.error('The dead channel snow stopped:', error),
    });
    snow.start();
});

onBeforeUnmount(() => snow?.stop());
</script>

<template>
    <canvas ref="canvas" class="dead-channel" aria-hidden="true" data-a11y-role="decorative" data-frames="0" />
</template>
