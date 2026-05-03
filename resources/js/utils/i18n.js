import { createI18n as vueCreateI18n } from 'vue-i18n';
import fr from '../i18n/fr.json';
import en from '../i18n/en.json';

const messages = { fr, en };

export function createI18n() {
    const raw = document.documentElement.lang || 'fr';
    const locale = raw.split('-')[0];
    return vueCreateI18n({
        legacy: false,
        locale: locale in messages ? locale : 'fr',
        fallbackLocale: 'fr',
        messages,
    });
}
