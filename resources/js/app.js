import './bootstrap';
import { createApp } from 'vue';
import { createI18n } from './utils/i18n.js';
import BootSequence from './components/BootSequence.vue';
import LanguageSwitcher from './components/LanguageSwitcher.vue';
import ThemeSwitcher from './components/ThemeSwitcher.vue';
import SkillsEditor from './components/admin/SkillsEditor.vue';

const islands = {
    'boot-sequence': BootSequence,
    'language-switcher': LanguageSwitcher,
    'theme-switcher': ThemeSwitcher,
};

Object.entries(islands).forEach(([id, component]) => {
    const el = document.getElementById(id);
    if (el) createApp(component).use(createI18n()).mount(el);
});

// Admin islands — mounted with props from data attributes
const skillsEditorEl = document.getElementById('skills-editor');
if (skillsEditorEl) {
    createApp(SkillsEditor, {
        initialSkills: skillsEditorEl.dataset.initialSkills ?? '[]',
        icons: JSON.parse(skillsEditorEl.dataset.icons ?? '[]'),
    })
        .use(createI18n())
        .mount(skillsEditorEl);
}
