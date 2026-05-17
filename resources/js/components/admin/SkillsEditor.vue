<script setup>
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import draggable from 'vuedraggable';
import { parseSkills, serializeCategory, createCategory, createTech } from '../../utils/skills-editor.js';

const props = defineProps({
    initialSkills: {
        type: String,
        default: '[]',
    },
    icons: {
        type: Array,
        default: () => [],
    },
});

const { t } = useI18n();

let uid = 0;
const nextKey = () => ++uid;

const categories = ref(parseSkills(props.initialSkills, nextKey));
const serialized = computed(() => JSON.stringify(categories.value.map(serializeCategory)));
</script>

<template>
    <input type="hidden" name="skills[fr]" :value="serialized" />

    <p
        aria-hidden="true"
        data-a11y-role="decorative"
        class="mb-3 font-mono text-[10px] tracking-[0.25em] text-secondary/60 select-none uppercase"
    >
        &gt; LIST<span class="cursor-blink">_</span>
    </p>

    <draggable
        v-model="categories"
        item-key="_key"
        handle=".drag-cat"
        :animation="150"
        ghost-class="opacity-40"
        class="space-y-4"
    >
        <template #item="{ element: category, index: catIndex }">
            <div class="border border-primary/20 bg-base-200 p-5">
                <!-- Category header row -->
                <div class="flex items-center gap-2 mb-4">
                    <span class="text-xs font-mono text-secondary/70 w-9 shrink-0 text-right select-none tabular-nums">
                        {{ (catIndex + 1) * 10 }}:
                    </span>
                    <button
                        type="button"
                        :aria-label="t('skills_editor.drag_category')"
                        class="drag-cat cursor-grab active:cursor-grabbing text-primary/30 hover:text-primary/70 font-mono text-base px-1 shrink-0 transition-colors"
                    >
                        ⠿
                    </button>
                    <select
                        v-model="category.icon"
                        :aria-label="t('skills_editor.icon_aria', { n: catIndex + 1 })"
                        class="select select-sm bg-base-300 font-mono border-primary/20 focus:border-primary/60 w-52 shrink-0"
                    >
                        <option v-for="icon in icons" :key="icon.glyph" :value="icon.glyph">
                            {{ icon.glyph }} {{ t(`skills_editor.icons.${icon.key}`) }}
                        </option>
                    </select>
                    <input
                        v-model="category.name"
                        type="text"
                        :aria-label="t('skills_editor.name_aria', { n: catIndex + 1 })"
                        placeholder="Nom de la catégorie"
                        :class="[
                            'input input-sm flex-1 bg-base-300 font-mono text-sm',
                            category.name.trim() === ''
                                ? 'border-error focus:border-error'
                                : 'border-primary/20 focus:border-primary/60',
                        ]"
                    />
                    <button
                        type="button"
                        :aria-label="t('skills_editor.remove_category', { n: catIndex + 1 })"
                        class="btn btn-ghost btn-sm text-error border border-error/20 hover:border-error/60 font-mono text-xs shrink-0"
                        @click="categories.splice(catIndex, 1)"
                    >
                        ✗
                    </button>
                </div>

                <!-- Tech list -->
                <draggable
                    v-model="category.techs"
                    item-key="_key"
                    handle=".drag-tech"
                    :animation="150"
                    ghost-class="opacity-40"
                    class="space-y-2 ml-7 mb-3"
                >
                    <template #item="{ element: tech, index: techIndex }">
                        <div class="flex items-center gap-2">
                            <span
                                class="text-xs font-mono text-secondary/70 w-9 shrink-0 text-right select-none tabular-nums"
                            >
                                {{ (techIndex + 1) * 10 }}:
                            </span>
                            <button
                                type="button"
                                :aria-label="t('skills_editor.drag_tech')"
                                class="drag-tech cursor-grab active:cursor-grabbing text-primary/30 hover:text-primary/70 font-mono text-sm px-0.5 shrink-0 transition-colors"
                            >
                                ⠿
                            </button>
                            <input
                                v-model="tech.v"
                                type="text"
                                :aria-label="t('skills_editor.tech_aria', { t: techIndex + 1, n: catIndex + 1 })"
                                placeholder="Technologie"
                                :class="[
                                    'input input-sm flex-1 bg-base-300 font-mono text-xs',
                                    tech.v.trim() === ''
                                        ? 'border-error focus:border-error'
                                        : 'border-primary/20 focus:border-primary/60',
                                ]"
                            />
                            <button
                                type="button"
                                :aria-label="t('skills_editor.remove_tech', { t: techIndex + 1 })"
                                class="btn btn-ghost btn-xs text-error/60 hover:text-error font-mono shrink-0"
                                @click="category.techs.splice(techIndex, 1)"
                            >
                                ✗
                            </button>
                        </div>
                    </template>
                </draggable>

                <button
                    type="button"
                    class="ml-7 btn btn-ghost btn-xs font-mono text-xs border border-primary/20 hover:border-primary/60 tracking-widest"
                    @click="category.techs.push(createTech(nextKey))"
                >
                    {{ t('skills_editor.add_tech') }}
                </button>
            </div>
        </template>
    </draggable>

    <button
        type="button"
        class="mt-4 btn btn-ghost btn-sm font-mono text-xs border border-primary/20 hover:border-primary/60 tracking-widest uppercase"
        @click="categories.push(createCategory(nextKey))"
    >
        {{ t('skills_editor.add_category') }}
    </button>
</template>
