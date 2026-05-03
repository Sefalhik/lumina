import './bootstrap';
import { createApp } from 'vue';
import { createI18n } from './utils/i18n.js';
import BootSequence from './components/BootSequence.vue';
import LanguageSwitcher from './components/LanguageSwitcher.vue';
import ThemeSwitcher from './components/ThemeSwitcher.vue';

const islands = {
    'boot-sequence': BootSequence,
    'language-switcher': LanguageSwitcher,
    'theme-switcher': ThemeSwitcher,
};

Object.entries(islands).forEach(([id, component]) => {
    const el = document.getElementById(id);
    if (el) createApp(component).use(createI18n()).mount(el);
});
