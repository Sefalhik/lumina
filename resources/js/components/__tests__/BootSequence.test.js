import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import BootSequence from '../BootSequence.vue';
import { ORIGINS } from '../../utils/boot-origin.js';
import { createI18n } from '../../utils/i18n.js';

/**
 * The overlay's wiring (LUMN-124). What the sequence shows, when it plays, when it leaves and what
 * it does when storage is refused are proven on the controller, utils/__tests__/boot-sequence.test.js.
 * These prove the component hands it the real browser, and shows what it reports: one case per wire.
 */

const mountWithI18n = (options = {}) => mount(BootSequence, { global: { plugins: [createI18n()] }, ...options });

describe('BootSequence', () => {
    beforeEach(() => {
        sessionStorage.clear();
        document.documentElement.removeAttribute('data-motion');
        document.body.classList.remove('booting');
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
        sessionStorage.clear();
        document.documentElement.removeAttribute('data-motion');
    });

    it('shows the overlay on a first visit, and holds the page while it plays', async () => {
        const wrapper = mountWithI18n({ attachTo: document.body });
        await flushPromises();

        expect(wrapper.find('.boot-overlay').exists()).toBe(true);
        expect(document.body.classList.contains('booting')).toBe(true);
        wrapper.unmount();
    });

    it('shows the lines as they arrive', async () => {
        const wrapper = mountWithI18n({ attachTo: document.body });
        await flushPromises();

        await vi.advanceTimersByTimeAsync(300);

        expect(wrapper.findAll('.boot-line').map((line) => line.text())).toEqual([
            'ONO-SENDAI CYBERSPACE 7  —  DECK v4.1.REV-C',
            'BAMA GRID / CONURB SECTOR 7  —  NODE INIT',
        ]);
        expect(wrapper.find('.boot-line--header').exists()).toBe(true);
        wrapper.unmount();
    });

    it("hands the controller the browser's own clock, screen and random source", async () => {
        vi.setSystemTime(new Date('2026-11-14T10:00:00Z'));
        const wrapper = mountWithI18n({ attachTo: document.body });
        await flushPromises();

        await vi.advanceTimersByTimeAsync(3800);
        const texts = wrapper.findAll('.boot-line').map((line) => line.text());

        expect(texts).toContain(`[SYS ] Display           : ${screen.width}×${screen.height}`);
        expect(texts.find((line) => line.startsWith('[SYS ] Date'))).toContain('2026');
        expect(texts.find((line) => line.startsWith('[BIOS] Deck serial'))).toMatch(/: ONO7-[0-9A-F]{8}$/);

        // And the origin is drawn from the table (LUMN-122): its line comes at 4.5 s.
        await vi.advanceTimersByTimeAsync(700);
        const source = wrapper.findAll('.boot-line').find((line) => line.text().startsWith('[NET ] Source IP'));
        const shown = source.text().match(/: (\d{1,3}\.\d{1,3})\.███\.███$/)[1];
        const table = new Set(ORIGINS.map((origin) => origin.block.split('.').slice(0, 2).join('.')));
        expect(table.has(shown), shown).toBe(true);
        wrapper.unmount();
    });

    it('reads the mark in this session: a sequence seen does not show again', async () => {
        sessionStorage.setItem('boot_sequence_played', '1');
        const wrapper = mountWithI18n({ attachTo: document.body });
        await flushPromises();

        expect(wrapper.find('.boot-overlay').exists()).toBe(false);
        wrapper.unmount();
    });

    it('does not show when animations are stopped (LUMN-11)', async () => {
        document.documentElement.setAttribute('data-motion', 'off');
        const wrapper = mountWithI18n({ attachTo: document.body });
        await flushPromises();

        expect(wrapper.find('.boot-overlay').exists()).toBe(false);
        wrapper.unmount();
    });

    it('leaves on a click, keeps its mark, and gives the page back after the fade', async () => {
        const wrapper = mountWithI18n({ attachTo: document.body });
        await flushPromises();

        await wrapper.find('.boot-overlay').trigger('click');
        expect(sessionStorage.getItem('boot_sequence_played')).toBe('1');
        await vi.advanceTimersByTimeAsync(700);

        expect(wrapper.find('.boot-overlay').exists()).toBe(false);
        expect(document.body.classList.contains('booting')).toBe(false);
        wrapper.unmount();
    });

    it('leaves on a key pressed anywhere on the page', async () => {
        const wrapper = mountWithI18n({ attachTo: document.body });
        await flushPromises();

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await vi.advanceTimersByTimeAsync(700);

        expect(wrapper.find('.boot-overlay').exists()).toBe(false);
        wrapper.unmount();
    });

    it('gives the page back when it goes away, and hears no key after', async () => {
        const wrapper = mountWithI18n({ attachTo: document.body });
        await flushPromises();

        wrapper.unmount();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'a' }));

        expect(document.body.classList.contains('booting')).toBe(false);
        expect(sessionStorage.getItem('boot_sequence_played')).toBeNull();
    });
});
