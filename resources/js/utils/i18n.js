import { createI18n as vueCreateI18n } from 'vue-i18n';

const modules = import.meta.glob('../i18n/*.json', { eager: true });
const messages = Object.fromEntries(
    Object.entries(modules).map(([path, mod]) => [path.match(/\/(\w+)\.json$/)[1], mod.default]),
);

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
