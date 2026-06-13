import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/login.css',
                'resources/css/dashboard.css',
                'resources/css/accesspoint.css',
                'resources/css/td.css',
                'resources/css/profile/personal_details.css',
                'resources/css/dts/list_transaction.css',
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
