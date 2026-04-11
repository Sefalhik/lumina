import './bootstrap';
import { createApp } from 'vue';
import ThemeSwitcher from './components/ThemeSwitcher.vue';

// Mount Vue islands individually — each component targets its own DOM node.
const islands = {
    'theme-switcher': ThemeSwitcher,
};

Object.entries(islands).forEach(([id, component]) => {
    const el = document.getElementById(id);
    if (el) createApp(component).mount(el);
});
