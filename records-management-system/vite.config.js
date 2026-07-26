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
                'resources/css/mobileaccesspoint.css',
                'resources/css/td.css',
                'resources/css/profile/personal_details.css',
                'resources/css/admin/accounts_users.css',
                'resources/css/admin/accounts_roles.css',
                'resources/css/admin/accounts_offices.css',
                'resources/css/admin/console_dashboard.css',
                'resources/css/admin/console.css',
                'resources/css/admin/activity_logs.css',
                'resources/css/dts/receive.css',
                'resources/css/dts/list_transaction.css',
                'resources/css/dts/internal.css',
                'resources/css/dts/create.css',
                'resources/css/notifications.css',
                'resources/css/admin/subsystems.css',
                'resources/css/rdp/inventory-and-appraisal.css',
                'resources/css/rdp/records-and-disposition-schedule.css',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        cors: true,
        origin: 'http://localhost:5173',
        hmr: {
            host: 'localhost',
            port: 5173,
        },
        watch: {
            usePolling: true,
            interval: 800,
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
