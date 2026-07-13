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
                'resources/css/admin/accounts_users.css',
                'resources/css/admin/accounts_roles.css',
                'resources/css/admin/accounts_offices.css',
                'resources/css/admin/console_dashboard.css',
                'resources/css/admin/activity_logs.css',
                'resources/css/dts/receive.css',
                'resources/css/dts/list_transaction.css',
                'resources/css/dts/internal.css',
                'resources/css/dts/create.css',
                'resources/css/notifications.css',
                'resources/css/admin/subsystems.css',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        host: process.env.DOCKER ? '0.0.0.0' : '192.168.1.100',
        port: 5173,
        hmr: {
            host: '192.168.1.100',
        },
        watch: {
            usePolling: true,
            interval: 800,
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
