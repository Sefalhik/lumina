import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import BootSequence from '../BootSequence.vue';
import { createI18n } from '../../utils/i18n.js';

const mountWithI18n = (options = {}) => mount(BootSequence, { global: { plugins: [createI18n()] }, ...options });

// Mock the utils module — isolates the component from real fetch and geo logic
vi.mock('../../utils/boot', async (importOriginal) => {
    const actual = await importOriginal();
    return {
        ...actual,
        fetchGeoData: vi.fn().mockResolvedValue({
            ip: '1.2.3.4',
            country_code: 'FR',
            city: 'Paris',
            region_code: 'IDF',
            org: 'AS12322 Free SAS',
        }),
    };
});

describe('BootSequence', () => {
    beforeEach(() => {
        sessionStorage.clear();
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
        sessionStorage.clear();
    });

    // ── Visibility ────────────────────────────────────────────────────────────

    it('shows the overlay on first visit', async () => {
        const wrapper = mountWithI18n({ attachTo: document.body });
        await flushPromises();

        expect(wrapper.find('.boot-overlay').exists()).toBe(true);
        wrapper.unmount();
    });

    it('does not show the overlay when the session key is already set', async () => {
        sessionStorage.setItem('boot_sequence_played', '1');
        const wrapper = mountWithI18n({ attachTo: document.body });
        await flushPromises();

        expect(wrapper.find('.boot-overlay').exists()).toBe(false);
        wrapper.unmount();
    });

    // ── Dismiss — click ───────────────────────────────────────────────────────

    it('sets the session key immediately on click', async () => {
        const wrapper = mountWithI18n({ attachTo: document.body });
        await flushPromises();

        await wrapper.find('.boot-overlay').trigger('click');
        expect(sessionStorage.getItem('boot_sequence_played')).toBe('1');

        wrapper.unmount();
    });

    it('hides the overlay after the fade-out transition on click', async () => {
        const wrapper = mountWithI18n({ attachTo: document.body });
        await flushPromises();

        await wrapper.find('.boot-overlay').trigger('click');
        await vi.advanceTimersByTimeAsync(700);

        expect(wrapper.find('.boot-overlay').exists()).toBe(false);
        wrapper.unmount();
    });

    it('does not dismiss twice if clicked repeatedly', async () => {
        const wrapper = mountWithI18n({ attachTo: document.body });
        await flushPromises();

        await wrapper.find('.boot-overlay').trigger('click');
        await wrapper.find('.boot-overlay').trigger('click'); // second click — overlay still fading
        await vi.advanceTimersByTimeAsync(700);

        // Should only be one sessionStorage write, not two
        expect(sessionStorage.getItem('boot_sequence_played')).toBe('1');
        wrapper.unmount();
    });

    // ── Dismiss — keyboard ────────────────────────────────────────────────────

    it('sets the session key on any keydown event', async () => {
        const wrapper = mountWithI18n({ attachTo: document.body });
        await flushPromises();

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(sessionStorage.getItem('boot_sequence_played')).toBe('1');

        wrapper.unmount();
    });

    it('hides the overlay after the fade-out transition on keydown', async () => {
        const wrapper = mountWithI18n({ attachTo: document.body });
        await flushPromises();

        document.dispatchEvent(new KeyboardEvent('keydown', { key: ' ' }));
        await vi.advanceTimersByTimeAsync(700);

        expect(wrapper.find('.boot-overlay').exists()).toBe(false);
        wrapper.unmount();
    });

    // ── Fetch fallback ────────────────────────────────────────────────────────

    it('still shows the overlay when fetchGeoData returns null', async () => {
        const { fetchGeoData } = await import('../../utils/boot');
        fetchGeoData.mockResolvedValueOnce(null);

        const wrapper = mountWithI18n({ attachTo: document.body });
        await flushPromises();

        expect(wrapper.find('.boot-overlay').exists()).toBe(true);
        wrapper.unmount();
    });

    // ── Cleanup ───────────────────────────────────────────────────────────────

    it('removes the keydown listener on unmount', async () => {
        const wrapper = mountWithI18n({ attachTo: document.body });
        await flushPromises();
        wrapper.unmount();

        // Keydown after unmount must not throw and must not write to sessionStorage
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'a' }));
        expect(sessionStorage.getItem('boot_sequence_played')).toBeNull();
    });
});
