<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <x-dcs-meta :title="$title ?? null" />
    <link rel="icon" href="{{ asset('images/cspc.webp') }}" type="image/webp">
    <title>{{ $title ?? 'CSPC - Document Control System' }}</title>
    <script>
        (function() {
            var theme = localStorage.getItem('rms-theme') || '{{ auth()->user()?->theme() ?? "light" }}';
            document.documentElement.setAttribute('data-theme', theme);
            var modalCloseKey = localStorage.getItem('rms-modal-close-key') || '{{ auth()->user()?->modalCloseKey() ?? "Escape" }}';
            window.RMS_MODAL_CLOSE_KEY = modalCloseKey;
            var sidebarToggleKey = localStorage.getItem('rms-sidebar-toggle-key') || '{{ auth()->user()?->sidebarToggleKey() ?? "" }}';
            window.RMS_SIDEBAR_TOGGLE_KEY = sidebarToggleKey;
            var actionToggleKey = localStorage.getItem('rms-action-toggle-key') || '{{ auth()->user()?->actionToggleKey() ?? "" }}';
            window.RMS_ACTION_TOGGLE_KEY = actionToggleKey;
            var notifToggleKey = localStorage.getItem('rms-notif-toggle-key') || '{{ auth()->user()?->notificationToggleKey() ?? "" }}';
            window.RMS_NOTIF_TOGGLE_KEY = notifToggleKey;
            var chatifyToggleKey = localStorage.getItem('rms-chatify-toggle-key') || '{{ auth()->user()?->chatifyToggleKey() ?? "" }}';
            window.RMS_CHATIFY_TOGGLE_KEY = chatifyToggleKey;
            var sidebar = localStorage.getItem('sidebarState');
            if (!sidebar) {
                var legacyDcs = localStorage.getItem('dcs-sidebar-collapsed');
                if (legacyDcs !== null) {
                    sidebar = legacyDcs === '1' ? 'imdown' : 'imup';
                }
            }
            if (sidebar && !document.cookie.includes('sidebarState=')) {
                document.cookie = 'sidebarState=' + encodeURIComponent(sidebar) + '; path=/; max-age=31536000; SameSite=Lax';
            }
            if (sidebar === 'imdown') {
                document.documentElement.setAttribute('data-dcs-sidebar-collapsed', '1');
            }
        })();
        window.assetPaths = {
            toggleNavSection: "{{ asset('icons/toggle-nav-section.svg') }}",
            toggleNavDefault: "{{ asset('icons/toggle-nav-default.svg') }}",
        };
        window.proccedto = window.proccedto || function(url) {
            window.location.href = url;
        };
    </script>
    <style>
        /* Avoid FOUC before the collapse checkbox is synced from localStorage. */
        html[data-dcs-sidebar-collapsed="1"] .side-nav {
            width: var(--dcs-sidebar-collapsed, 68px) !important;
        }
        html[data-dcs-sidebar-collapsed="1"] .dashboard-main,
        html[data-dcs-sidebar-collapsed="1"] .rpt-page,
        html[data-dcs-sidebar-collapsed="1"] .db-page,
        html[data-dcs-sidebar-collapsed="1"] .mf-page,
        html[data-dcs-sidebar-collapsed="1"] .st-container,
        html[data-dcs-sidebar-collapsed="1"] .reg-container,
        html[data-dcs-sidebar-collapsed="1"] .upd-container,
        html[data-dcs-sidebar-collapsed="1"] .hst-container,
        html[data-dcs-sidebar-collapsed="1"] .drr-container,
        html[data-dcs-sidebar-collapsed="1"] .rb-container,
        html[data-dcs-sidebar-collapsed="1"] .ofi-page,
        html[data-dcs-sidebar-collapsed="1"] .settings-main,
        html[data-dcs-sidebar-collapsed="1"] .dcs-top-tabs-host {
            left: var(--dcs-sidebar-collapsed, 68px) !important;
        }
    </style>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body, button, input, select, textarea, a, span, div, h1, h2, h3, h4, h5, h6, label {
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    @vite([
        'resources/css/dcs/chrome.css',
        'resources/css/notifications.css',
        'resources/js/dashboard.js',
    ])
    @if(request()->routeIs('dcs', 'dcs.dashboard'))
        @vite(['resources/css/dcs/dashboard.css'])
        @if(\App\Helpers\RegisterQueryHelper::isLimitedDcsUser())
            <link rel="stylesheet" href="{{ asset('css/dcs/office-intake.css') }}">
        @endif
    @elseif(request()->routeIs('dcs.office.*'))
        @vite(['resources/css/dcs/register.css'])
        <link rel="stylesheet" href="{{ asset('css/dcs/office-intake.css') }}">
    @elseif(request()->routeIs('dcs.settings.index'))
        @vite(['resources/css/dcs/settings.css'])
    @elseif(request()->routeIs('dcs.register.create') || request()->routeIs('dcs.register.revised'))
        @vite(['resources/css/dcs/register.css', 'resources/js/dcs/register-pdf-compare.js'])
    @elseif(request()->routeIs('dcs.register.update'))
        @vite(['resources/css/dcs/update.css'])
    @elseif(request()->routeIs('dcs.register.edit'))
        @vite(['resources/css/dcs/edit.css', 'resources/css/dcs/register.css'])
    @elseif(request()->routeIs('dcs.register.history'))
        @vite(['resources/css/dcs/history.css', 'resources/css/dcs/register.css'])
    @elseif(request()->routeIs('dcs.recycle-bin'))
        @vite(['resources/css/dcs/recycle-bin.css'])
    @elseif(request()->routeIs('dcs.review'))
        @vite(['resources/css/dcs/review.css', 'resources/js/dcs/review-pdf.js'])
    @elseif(request()->routeIs('dcs.reports.*'))
        @vite(['resources/css/dcs/reports.css'])
    @elseif(request()->routeIs('dcs.stamping.index'))
        @vite(['resources/css/dcs/stamping.css'])
    @elseif(request()->routeIs('dcs.database.index'))
        @vite(['resources/css/dcs/database.css'])
    @elseif(request()->routeIs('dcs.manage-files'))
        @vite(['resources/css/dcs/manage-files.css'])
    @endif
    @stack('styles')
    @livewireStyles
</head>
<body>
    @php
        $sidebarState = request()->cookie('sidebarState', 'imup');
        $isCollapsed = $sidebarState === 'imdown';
    @endphp
    <input type="checkbox" id="dcs-nav-open" class="dcs-chrome-toggle">
    <input type="checkbox" id="dcs-sidebar-collapsed" class="dcs-chrome-toggle" {{ $isCollapsed ? 'checked' : '' }}>
    <script>
        (function () {
            function persistState(isCollapsed) {
                var state = isCollapsed ? 'imdown' : 'imup';
                try {
                    localStorage.setItem('sidebarState', state);
                    localStorage.setItem('dcs-sidebar-collapsed', isCollapsed ? '1' : '0');
                } catch (e) {}
                document.cookie = 'sidebarState=' + encodeURIComponent(state) + '; path=/; max-age=31536000; SameSite=Lax';
            }

            function syncSidebarCollapse() {
                var el = document.getElementById('dcs-sidebar-collapsed');
                if (!el) return;
                var storedState = localStorage.getItem('sidebarState');
                var isCollapsed;
                if (storedState) {
                    isCollapsed = storedState === 'imdown';
                } else if (localStorage.getItem('dcs-sidebar-collapsed') !== null) {
                    isCollapsed = localStorage.getItem('dcs-sidebar-collapsed') === '1';
                } else {
                    var match = document.cookie.match(/(?:^|;\s*)sidebarState=([^;]*)/);
                    isCollapsed = match ? decodeURIComponent(match[1]) === 'imdown' : false;
                }
                el.checked = isCollapsed;
                if (isCollapsed) {
                    document.documentElement.setAttribute('data-dcs-sidebar-collapsed', '1');
                } else {
                    document.documentElement.removeAttribute('data-dcs-sidebar-collapsed');
                }
            }
            syncSidebarCollapse();
            document.getElementById('dcs-sidebar-collapsed')?.addEventListener('change', function () {
                persistState(this.checked);
                if (this.checked) {
                    document.documentElement.setAttribute('data-dcs-sidebar-collapsed', '1');
                } else {
                    document.documentElement.removeAttribute('data-dcs-sidebar-collapsed');
                }
            });
            document.addEventListener('livewire:navigated', syncSidebarCollapse);
        })();
    </script>

    <header class="rms-app-header">
        <label class="mobile-nav-toggle" for="dcs-nav-open" aria-label="Open navigation">
            <i class="fa-solid fa-bars"></i>
        </label>
        <div class="cspc-logo">
            <img class="ico" src="{{ asset('images/cspc.png') }}" alt="CSPC">
        </div>
        <div class="label-container">
            <span class="subtitle">Camarines Sur Polytechnic Colleges</span>
            <span class="title">Records Management System</span>
        </div>
        <div class="notification-container">
            <livewire:components.notification.notifications />
        </div>
        <span class="office_name">{{ auth()->user()?->details?->office?->office_name ?? 'Records and Freedom of Information Office' }}</span>
        <x-actions.dropdown />
    </header>

    <x-nav.dcs />

    @auth
        <livewire:components.dcs.session-guard />
    @endauth

    <div class="dcs-top-tabs-host">
        <x-nav.top-tabs system="dcs" />
    </div>

    {{ $slot }}

    <x-dcs.toast />
    <x-chatify.floating-widget />
    @stack('scripts')
    @livewireScripts
</body>
</html>
