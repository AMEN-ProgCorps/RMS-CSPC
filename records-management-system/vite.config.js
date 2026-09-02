import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

// Set DOCKER=true in docker-compose.yml (already done on the node service).
// Locally, this env var is absent, so isDocker = false.
const isDocker = process.env.DOCKER === 'true';

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
                'resources/css/admin/record-series.css',
                'resources/css/admin/activity_logs.css',
                'resources/css/dts/receive.css',
                'resources/css/dts/list_transaction.css',
                'resources/css/dts/internal.css',
                'resources/css/dts/create.css',
                'resources/css/notifications.css',
                'resources/css/admin/subsystems.css',
                'resources/css/rdp/inventory-and-appraisal.css',
                'resources/css/rdp/records-and-disposition-schedule.css',
                'resources/css/dcs/chrome.css',
                'resources/css/dcs/actions-dropdown.css',
                'resources/css/dcs/dashboard.css',
                'resources/css/dcs/settings.css',
                'resources/css/dcs/register.css',
                'resources/css/dcs/update.css',
                'resources/css/dcs/edit.css',
                'resources/css/dcs/history.css',
                'resources/css/dcs/recycle-bin.css',
                'resources/css/dcs/review.css',
                'resources/js/dcs/review-pdf.js',
                'resources/js/dcs/register-pdf-compare.js',
                'resources/css/dcs/reports.css',
                'resources/css/dcs/stamping.css',
                'resources/css/dcs/database.css',
                'resources/css/dcs/manage-files.css',
                'resources/css/dcs/office-intake.css',
                'resources/js/dcs/office-intake-modal.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        // Docker: bind to all interfaces so the container exposes port 5173.
        // Local:  bind to localhost only (default, faster).
        host: isDocker ? '0.0.0.0' : 'localhost',
        port: 5173,
        strictPort: true,
        cors: true,

        // Docker-only overrides — not needed (and harmful) when running locally.
        ...(isDocker && {
            // 'origin' must match the URL the *browser* uses to reach Vite.
            // On Docker + Windows the port is forwarded, so the browser hits localhost:5173.
            origin: process.env.VITE_DEV_SERVER_URL ?? 'http://localhost:5173',
            hmr: {
                host: 'localhost',
                port: 5173,
                protocol: 'ws',
            },
            watch: {
                // Polling is required on Docker + Windows (no inotify support).
                usePolling: true,
                interval: 500,
                ignored: ['**/vendor/**', '**/storage/framework/views/**'],
            },
        }),
    },
});
