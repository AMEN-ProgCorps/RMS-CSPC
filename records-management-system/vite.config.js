import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/login.css',
                'resources/css/dashboard.css',
                'resources/js/dashboard.js',
                'resources/css/accesspoint.css',
                'resources/css/td.css',
                'resources/css/profile/personal_details.css',
                'resources/css/dts/receive.css',
                'resources/css/dts/list_trasaction.css',
                'resources/css/dts/internal.css',
                'resources/css/dts/create.css',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        host: process.env.DOCKER ? '0.0.0.0' : 'localhost',
        port: 5173,
        hmr: {
            host: 'localhost',
        },
        watch: {
            usePolling: true,
            interval: 800,
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
