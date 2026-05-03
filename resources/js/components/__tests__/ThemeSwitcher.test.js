import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import ThemeSwitcher from '../ThemeSwitcher.vue';
import { createI18n } from '../../utils/i18n.js';

const mountWithI18n = (options = {}) => mount(ThemeSwitcher, { global: { plugins: [createI18n()] }, ...options });

describe('ThemeSwitcher', () => {
    let favicon;

    beforeEach(() => {
        localStorage.clear();
        document.documentElement.removeAttribute('data-theme');

        favicon = document.createElement('link');
        favicon.id = 'favicon';
        favicon.setAttribute('href', '/favicons/favicon-sprawl.svg');
        document.head.appendChild(favicon);
    });

    afterEach(() => {
        favicon.remove();
    });

    it('defaults to sprawl theme', () => {
        const wrapper = mountWithI18n();
        expect(wrapper.vm.current).toBe('sprawl');
    });

    it('applies theme, updates data-theme attribute, localStorage and favicon on click', async () => {
        const wrapper = mountWithI18n();

        await wrapper.findAll('[role="option"] button')[1].trigger('click'); // steampunk

        expect(wrapper.vm.current).toBe('steampunk');
        expect(document.documentElement.getAttribute('data-theme')).toBe('steampunk');
        expect(localStorage.getItem('theme')).toBe('steampunk');
        expect(favicon.getAttribute('href')).toContain('favicon-steampunk.svg');
    });

    it('switches to each theme without error', async () => {
        const wrapper = mountWithI18n();
        const buttons = wrapper.findAll('[role="option"] button');

        for (const [index, id] of ['sprawl', 'steampunk', 'neon-noir'].entries()) {
            await buttons[index].trigger('click');
            expect(wrapper.vm.current).toBe(id);
            expect(document.documentElement.getAttribute('data-theme')).toBe(id);
        }
    });

    it('restores valid theme from localStorage on mount', async () => {
        localStorage.setItem('theme', 'neon-noir');
        const wrapper = mountWithI18n();
        await flushPromises();
        expect(wrapper.vm.current).toBe('neon-noir');
    });

    it('ignores unknown theme stored in localStorage', async () => {
        localStorage.setItem('theme', 'invalid-theme');
        const wrapper = mountWithI18n();
        await flushPromises();
        expect(wrapper.vm.current).toBe('sprawl');
    });
});
