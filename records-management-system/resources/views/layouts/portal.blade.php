<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <title>{{ $title ?? 'RMS CSPC' }}</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="{{ asset('images/cspc.webp') }}" type="image/webp">


    <!-- Google Fonts Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body, button, input, select, textarea, a, span, div, h1, h2, h3, h4, h5, h6, label {
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
        }
        /* Hide scrollbars globally for layout files */
        ::-webkit-scrollbar {
            display: none !important;
            width: 0 !important;
            height: 0 !important;
        }
        * {
            -ms-overflow-style: none !important;
            scrollbar-width: none !important;
        }
    </style>

    @stack('styles')

    @livewireStyles
</head>
<body>
    {{ $slot }}

    @stack('scripts')

    @livewireScripts

    @auth
    @if(\DB::table('system_settings')->where('key', 'page_prewarming_enabled')->value('value') === 'true')
        @php
            $prewarmUrls = ['/portal', '/profile', '/profile/security-logs', '/profile/notification-manager'];
            $perms = auth()->user()?->permissions;
            if ($perms) {
                if ($perms->is_sadm || $perms->can_access_dts) {
                    $prewarmUrls[] = '/dts';
                    if ($perms->is_sadm || $perms->can_dts_user_received) {
                        $prewarmUrls[] = '/dts/receive';
                    }
                    if ($perms->is_sadm || $perms->can_dts_use_internal) {
                        $prewarmUrls[] = '/dts/create/internal';
                        $prewarmUrls[] = '/dts/list/internal';
                    }
                    if ($perms->is_sadm || $perms->can_dts_use_external) {
                        $prewarmUrls[] = '/dts/create/external';
                        $prewarmUrls[] = '/dts/list/external';
                    }
                    if ($perms->is_sadm || $perms->can_dts_use_application) {
                        $prewarmUrls[] = '/dts/create/application-letters';
                        $prewarmUrls[] = '/dts/list/application-letters';
                    }
                    if ($perms->is_sadm || $perms->can_dts_use_issuance) {
                        $prewarmUrls[] = '/dts/create/issuances';
                        $prewarmUrls[] = '/dts/list/issuances';
                    }
                }
                if ($perms->is_sadm || $perms->can_access_rdp) {
                    $prewarmUrls[] = '/rdp';
                    $prewarmUrls[] = '/rdp/add-records/inventory-and-appraisal';
                    $prewarmUrls[] = '/rdp/add-records/records-and-disposition-schedule';
                    $prewarmUrls[] = '/rdp/reports/nap-form-1';
                    $prewarmUrls[] = '/rdp/reports/nap-form-2';
                    $prewarmUrls[] = '/rdp/reports/nap-form-3';
                }
                if ($perms->is_sadm || $perms->can_access_dcs) {
                    $prewarmUrls[] = '/dcs';
                    $prewarmUrls[] = '/dcs/register';
                    $prewarmUrls[] = '/dcs/register/update';
                    $prewarmUrls[] = '/dcs/reports/masterlist';
                    $prewarmUrls[] = '/dcs/reports/monitoring';
                    $prewarmUrls[] = '/dcs/reports/opcr';
                    $prewarmUrls[] = '/dcs/reports/others';
                    $prewarmUrls[] = '/dcs/stamping';
                    $prewarmUrls[] = '/dcs/database';
                    $prewarmUrls[] = '/dcs/settings';
                }
                if ($perms->is_sadm || $perms->is_admin) {
                    $prewarmUrls[] = '/admin/console';
                    $prewarmUrls[] = '/admin/accounts/users';
                    $prewarmUrls[] = '/admin/accounts/roles';
                    $prewarmUrls[] = '/admin/accounts/offices';
                    $prewarmUrls[] = '/admin/activity/logins';
                    $prewarmUrls[] = '/admin/activity/account-changes';
                    $prewarmUrls[] = '/admin/activity/notifications';
                    $prewarmUrls[] = '/admin/dts/transaction-logs';
                    $prewarmUrls[] = '/admin/dts/update-logs';
                    $prewarmUrls[] = '/admin/dts/transaction-flows';
                    $prewarmUrls[] = '/admin/rdp/records-logs';
                    $prewarmUrls[] = '/admin/rdp/update-logs';
                    $prewarmUrls[] = '/admin/subsystems/add';
                    $prewarmUrls[] = '/admin/subsystems/activate';
                    $prewarmUrls[] = '/admin/subsystems/deactivate';
                    $prewarmUrls[] = '/admin/subsystems/changes-logs';
                }
            }
        @endphp
    <script>
        document.addEventListener("DOMContentLoaded", () => {
            const userId = "{{ auth()->id() }}";
            const prewarmKey = 'rms_prewarmed_' + userId;
            if (sessionStorage.getItem(prewarmKey)) {
                return;
            }

            const urls = {!! json_encode($prewarmUrls) !!};

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
    <x-chatify.floating-widget />
    <livewire:components.scanner-modal />
</body>
</html>
