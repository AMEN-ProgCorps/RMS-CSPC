import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/login.css',
                'resources/css/accesspoint.css',
                'resources/css/td.css',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        // Bind to all interfaces only when inside Docker (set DOCKER=true in docker-compose)
        host: process.env.DOCKER ? '0.0.0.0' : 'localhost',
        port: 5173,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
