<script setup>
import { ref, onMounted } from 'vue';

const themes = [
    { id: 'sprawl', label: 'Sprawl', icon: '⬡', hint: 'Cyberpunk terminal' },
    { id: 'steampunk', label: 'Steam', icon: '⚙', hint: 'Brass & Victorian' },
    { id: 'neon-noir', label: 'Neon Noir', icon: '◈', hint: 'Rain & neon' },
];

const current = ref('sprawl');

function apply(id) {
    current.value = id;
    document.documentElement.setAttribute('data-theme', id);
    localStorage.setItem('theme', id);
    document.getElementById('favicon').href = `/favicons/favicon-${id}.svg`;
}

onMounted(() => {
    const saved = localStorage.getItem('theme');
    if (saved && themes.some((t) => t.id === saved)) {
        current.value = saved;
    }
});
</script>

<template>
    <div class="dropdown dropdown-end">
        <button
            tabindex="0"
            class="btn btn-ghost btn-sm gap-1 font-mono text-xs tracking-widest uppercase"
            aria-label="Changer de thème"
            aria-haspopup="listbox"
        >
            <span class="text-primary">{{ themes.find((t) => t.id === current)?.icon }}</span>
            <span class="hidden sm:inline text-base-content/70">Theme</span>
            <svg class="w-3 h-3 opacity-50" fill="currentColor" viewBox="0 0 20 20">
                <path
                    fill-rule="evenodd"
                    d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                    clip-rule="evenodd"
                />
            </svg>
        </button>

        <ul
            tabindex="0"
            role="listbox"
            :aria-label="`Thème actuel : ${current}`"
            class="dropdown-content menu bg-base-200 border border-primary/30 w-44 mt-2 p-1 gap-0.5"
        >
            <li v-for="theme in themes" :key="theme.id" role="option" :aria-selected="current === theme.id">
                <button
                    class="flex items-center gap-3 w-full text-left font-mono text-xs tracking-wider px-3 py-2 transition-colors"
                    :class="
                        current === theme.id
                            ? 'text-primary bg-primary/10'
                            : 'text-base-content/70 hover:text-primary hover:bg-primary/5'
                    "
                    @click="apply(theme.id)"
                >
                    <span class="text-base leading-none">{{ theme.icon }}</span>
                    <span class="flex flex-col">
                        <span class="uppercase">{{ theme.label }}</span>
                        <span class="opacity-50 normal-case text-[10px]">{{ theme.hint }}</span>
                    </span>
                    <span v-if="current === theme.id" class="ml-auto text-primary text-xs">✓</span>
                </button>
            </li>
        </ul>
    </div>
</template>
