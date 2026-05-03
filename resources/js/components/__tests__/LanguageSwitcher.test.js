import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import LanguageSwitcher from '../LanguageSwitcher.vue';
import { createI18n } from '../../utils/i18n.js';

const mountWithI18n = () => mount(LanguageSwitcher, { global: { plugins: [createI18n()] } });

describe('LanguageSwitcher', () => {
    beforeEach(() => {
        document.documentElement.setAttribute('lang', 'fr');
    });

    it('renders all 24 locale options by default', () => {
        const wrapper = mountWithI18n();
        expect(wrapper.findAll('[role="option"]')).toHaveLength(24);
    });

    it('filters options by native name (case-insensitive)', async () => {
        const wrapper = mountWithI18n();
        await wrapper.find('input[type="search"]').setValue('deu');

        const options = wrapper.findAll('[role="option"]');
        expect(options).toHaveLength(1);
        expect(options[0].text()).toContain('Deutsch');
    });

    it('filters options by locale code', async () => {
        const wrapper = mountWithI18n();
        await wrapper.find('input[type="search"]').setValue('sv');

        const options = wrapper.findAll('[role="option"]');
        expect(options).toHaveLength(1);
        expect(options[0].text()).toContain('Svenska');
    });

    it('shows the no-results message when the filter matches nothing', async () => {
        const wrapper = mountWithI18n();
        await wrapper.find('input[type="search"]').setValue('zzz');

        expect(wrapper.findAll('[role="option"]')).toHaveLength(0);
        expect(wrapper.text()).toContain('Aucun résultat');
    });

    it('restores all options after clearing the filter', async () => {
        const wrapper = mountWithI18n();
        const input = wrapper.find('input[type="search"]');
        await input.setValue('deu');
        await input.setValue('');

        expect(wrapper.findAll('[role="option"]')).toHaveLength(24);
    });

    it('marks the current locale option as selected', () => {
        const wrapper = mountWithI18n();
        const frOption = wrapper.findAll('[role="option"]').find((li) => li.attributes('aria-selected') === 'true');
        expect(frOption).toBeDefined();
        expect(frOption.text()).toContain('Français');
    });

    it('displays the flag emoji for each option', () => {
        const wrapper = mountWithI18n();
        const firstOption = wrapper.findAll('[role="option"]')[0];
        expect(firstOption.text()).toMatch(/\p{Emoji}/u);
    });
});
