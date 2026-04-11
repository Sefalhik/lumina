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
    server: {
        host: 'dev.cardascia-it.org',
        https: true,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
