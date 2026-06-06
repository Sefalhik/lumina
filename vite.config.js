import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import mkcert from 'vite-plugin-mkcert';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/css/scss/main.scss', 'resources/js/app.js'],
            refresh: true,
        }),
        vue(),
        tailwindcss(),
        mkcert(),
    ],
    optimizeDeps: {
        // vue-i18n pre-bundled by esbuild generates a broken init_runtime_dom_esm_bundler
        // reference when its chunk loads before Vue's chunk (Vite 8 + vue-i18n 11 regression).
        // Excluding it makes Vite serve it as native ESM — imports are rewritten inline by
        // Vite's middleware in the correct order, no init_* reference generated.
        exclude: ['vue-i18n'],
        // Pre-bundle vue-i18n's intlify deps explicitly to avoid a runtime reload on first page
        // load (Vite would otherwise discover them lazily when vue-i18n is first imported).
        include: ['@intlify/core-base', '@intlify/shared'],
    },
    server: {
        host: 'dev.cardascia-it.org',
        https: true,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
