<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <title>{{ $title ?? 'RMS CSPC' }}</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="{{ asset('images/cspc.webp') }}" type="image/webp">


    @stack('styles')

    @livewireStyles
</head>
<body>
    {{ $slot }}

    @stack('scripts')

    @livewireScripts

    @auth
    @if(\DB::table('system_settings')->where('key', 'page_prewarming_enabled')->value('value') === 'true')
    <script>
        document.addEventListener("DOMContentLoaded", () => {
            const userId = "{{ auth()->id() }}";
            const prewarmKey = 'rms_prewarmed_' + userId;
            if (sessionStorage.getItem(prewarmKey)) {
                return;
            }

            const urls = [
                '/portal',
                '/profile',
                '/profile/security-logs',
                '/profile/notification-manager',
                '/dts',
                '/dts/receive',
                '/dts/create/internal',
                '/dts/create/external',
                '/dts/create/application-letters',
                '/dts/create/issuances',
                '/dts/list/internal',
                '/dts/list/external',
                '/dts/list/application-letters',
                '/dts/list/issuances',
                '/rdp',
                '/rdp/add-records/inventory-and-appraisal',
                '/rdp/add-records/records-and-disposition-schedule',
                '/rdp/reports/nap-form-1',
                '/rdp/reports/nap-form-2',
                '/rdp/reports/nap-form-3',
                '/admin/console',
                '/admin/accounts/users',
                '/admin/accounts/roles',
                '/admin/accounts/offices',
                '/admin/activity/logins',
                '/admin/activity/account-changes',
                '/admin/activity/notifications',
                '/admin/dts/transaction-logs',
                '/admin/dts/update-logs',
                '/admin/dts/transaction-flows',
                '/admin/rdp/records-logs',
                '/admin/rdp/update-logs',
                '/admin/subsystems/add',
                '/admin/subsystems/activate',
                '/admin/subsystems/deactivate',
                '/admin/subsystems/changes-logs'
            ];

            let index = 0;
            const prewarmNext = () => {
                if (index >= urls.length) {
                    sessionStorage.setItem(prewarmKey, 'true');
                    console.log('RMS Application pre-warming/health-checkup completed successfully.');
                    return;
                }
                const url = urls[index++];
                fetch(url, { priority: 'low' })
                    .then(() => {
                        setTimeout(prewarmNext, 300);
                    })
                    .catch(() => {
                        setTimeout(prewarmNext, 300);
                    });
            };

            setTimeout(prewarmNext, 2000);
        });
    </script>
    @endif
    @endauth
</body>
</html>
