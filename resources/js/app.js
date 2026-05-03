import './bootstrap';
import { createApp } from 'vue';
import { createI18n } from './utils/i18n.js';
import BootSequence from './components/BootSequence.vue';
import ThemeSwitcher from './components/ThemeSwitcher.vue';

const islands = {
    'boot-sequence': BootSequence,
    'theme-switcher': ThemeSwitcher,
};

Object.entries(islands).forEach(([id, component]) => {
    const el = document.getElementById(id);
    if (el) createApp(component).use(createI18n()).mount(el);
});
