<script setup>
import { computed, ref, onMounted, onUnmounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { urlFor, writeLocaleToStorage } from '../utils/language-switcher.js';

const { t, locale } = useI18n();

const locales = [
    { code: 'bg', name: 'Български', flag: '🇧🇬' },
    { code: 'cs', name: 'Čeština', flag: '🇨🇿' },
    { code: 'da', name: 'Dansk', flag: '🇩🇰' },
    { code: 'de', name: 'Deutsch', flag: '🇩🇪' },
    { code: 'el', name: 'Ελληνικά', flag: '🇬🇷' },
    { code: 'en', name: 'English', flag: '🇬🇧' },
    { code: 'es', name: 'Español', flag: '🇪🇸' },
    { code: 'et', name: 'Eesti', flag: '🇪🇪' },
    { code: 'fi', name: 'Suomi', flag: '🇫🇮' },
    { code: 'fr', name: 'Français', flag: '🇫🇷' },
    { code: 'ga', name: 'Gaeilge', flag: '🇮🇪' },
    { code: 'hr', name: 'Hrvatski', flag: '🇭🇷' },
    { code: 'hu', name: 'Magyar', flag: '🇭🇺' },
    { code: 'it', name: 'Italiano', flag: '🇮🇹' },
    { code: 'lt', name: 'Lietuvių', flag: '🇱🇹' },
    { code: 'lv', name: 'Latviešu', flag: '🇱🇻' },
    { code: 'mt', name: 'Malti', flag: '🇲🇹' },
    { code: 'nl', name: 'Nederlands', flag: '🇳🇱' },
    { code: 'pl', name: 'Polski', flag: '🇵🇱' },
    { code: 'pt', name: 'Português', flag: '🇵🇹' },
    { code: 'ro', name: 'Română', flag: '🇷🇴' },
    { code: 'sk', name: 'Slovenčina', flag: '🇸🇰' },
    { code: 'sl', name: 'Slovenščina', flag: '🇸🇮' },
    { code: 'sv', name: 'Svenska', flag: '🇸🇪' },
];

const filter = ref('');

const filtered = computed(() => {
    const q = filter.value.trim().toLowerCase();
    if (!q) return locales;
    return locales.filter((l) => l.name.toLowerCase().includes(q) || l.code.includes(q));
});

const current = computed(() => locale.value);
const currentLocale = computed(() => locales.find((l) => l.code === current.value));

function hrefFor(lang) {
    return urlFor(window.location.pathname, lang, window.location.search);
}

function onLocaleClick(code) {
    writeLocaleToStorage(code);
}

function onStorageChange(event) {
    if (event.key === 'locale' && event.newValue && locales.some((l) => l.code === event.newValue)) {
        window.location.href = hrefFor(event.newValue);
    }
}

onMounted(() => {
    window.addEventListener('storage', onStorageChange);
});

onUnmounted(() => {
    window.removeEventListener('storage', onStorageChange);
});
</script>

<template>
    <div class="dropdown dropdown-end">
        <button
            tabindex="0"
            class="btn btn-ghost btn-sm gap-1.5"
            :aria-label="t('language_switcher.aria_label')"
            aria-haspopup="listbox"
        >
            <span class="text-base leading-none">{{ currentLocale?.flag }}</span>
            <span class="font-mono text-xs tracking-widest uppercase text-primary">{{ current }}</span>
            <svg class="w-3 h-3 opacity-50 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path
                    fill-rule="evenodd"
                    d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                    clip-rule="evenodd"
                />
            </svg>
        </button>

        <div tabindex="0" class="dropdown-content z-50 bg-base-200 border border-primary/30 w-52 mt-2 shadow-lg">
            <div class="p-2 border-b border-primary/10">
                <input
                    v-model="filter"
                    type="search"
                    :placeholder="t('language_switcher.filter_placeholder')"
                    class="input input-xs w-full bg-base-300 border-primary/20 focus:border-primary/50 font-mono text-xs"
                    autocomplete="off"
                    spellcheck="false"
                />
            </div>

            <ul role="listbox" :aria-label="t('language_switcher.aria_label')" class="max-h-52 overflow-y-auto p-1">
                <li v-for="lang in filtered" :key="lang.code" role="option" :aria-selected="lang.code === current">
                    <a
                        :href="hrefFor(lang.code)"
                        :hreflang="lang.code"
                        :lang="lang.code"
                        @click="onLocaleClick(lang.code)"
                        class="flex items-center gap-2 px-2 py-1.5 rounded-sm text-xs font-mono tracking-wide transition-colors"
                        :class="
                            lang.code === current
                                ? 'text-primary bg-primary/10'
                                : 'text-base-content/70 hover:text-primary hover:bg-primary/5'
                        "
                    >
                        <span class="text-sm leading-none w-5 text-center shrink-0">{{ lang.flag }}</span>
                        <span class="flex-1 truncate">{{ lang.name }}</span>
                        <span v-if="lang.code === current" class="text-primary shrink-0">✓</span>
                    </a>
                </li>

                <li
                    v-if="filtered.length === 0"
                    class="px-3 py-2 text-xs text-base-content/40 font-mono text-center italic"
                >
                    {{ t('language_switcher.no_results') }}
                </li>
            </ul>
        </div>
    </div>
</template>
