<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="{{ asset('images/cspc.webp') }}" type="image/webp">
    
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
            var followScrollEnabled = localStorage.getItem('rms-follow-scroll-enabled') !== null
                ? localStorage.getItem('rms-follow-scroll-enabled') === 'true'
                : {{ auth()->user()?->followScrollEnabled() ? 'true' : 'false' }};
            window.RMS_FOLLOW_SCROLL_ENABLED = followScrollEnabled;
            var followScrollSide = localStorage.getItem('rms-follow-scroll-side') !== null
                ? localStorage.getItem('rms-follow-scroll-side') === 'true'
                : {{ auth()->user()?->followScrollSide() ? 'true' : 'false' }};
            window.RMS_FOLLOW_SCROLL_SIDE = followScrollSide;
            var followScrollVertical = localStorage.getItem('rms-follow-scroll-vertical') !== null
                ? localStorage.getItem('rms-follow-scroll-vertical') === 'true'
                : {{ auth()->user()?->followScrollVertical() ? 'true' : 'false' }};
            window.RMS_FOLLOW_SCROLL_VERTICAL = followScrollVertical;
            var sidebar = localStorage.getItem('sidebarState');
            if (sidebar && !document.cookie.includes('sidebarState=')) {
                document.cookie = 'sidebarState=' + encodeURIComponent(sidebar) + '; path=/; max-age=31536000; SameSite=Lax';
            }
        })();
        window.assetPaths = {
            toggleNavSection: "{{ asset('icons/toggle-nav-section.svg') }}",
            toggleNavDefault: "{{ asset('icons/toggle-nav-default.svg') }}",
        };
        window.proccedto = window.proccedto || function(url) {
            window.location.href = url;
        };
        window.showButtonSection = window.showButtonSection || function(button_target) {
            const navigation = document.getElementById('navigation');
            const pla = document.getElementById(button_target);
            if (navigation && pla && navigation.classList.contains('imup')) {
                pla.classList.toggle('show');
            }
        };
    </script>
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
        @media screen and (min-width: 769px) {
            :root {
                zoom: clamp(0.72, calc(100vw / 1920), 1);
            }
        }
        body.rdp-appraisal-only .navigation,
        body.rdp-appraisal-only .top-nav-tabs-wrapper,
        body.rdp-appraisal-only .dcs-top-tabs-host {
            display: none !important;
        }
        body.rdp-appraisal-only .article-container {
            width: 100% !important;
        }
        @media print {
            header,
            nav,
            .navigation,
            #navigation,
            .top-nav-tabs-wrapper,
            .dcs-top-tabs-host,
            .no-print,
            .chatify-floating-widget {
                display: none !important;
            }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    @vite(['resources/css/dashboard.css', 'resources/css/notifications.css', 'resources/js/dashboard.js'])
    <title>CSPC - Records Disposition Program</title>
    @stack('styles')
</head>
@php
    $rdpGuestAppraisal = !(auth()->user()?->permissions?->is_sadm || auth()->user()?->permissions?->can_access_rdp);
@endphp
<body data-gramm="false" data-gramm_editor="false" data-enable-grammarly="false" @if($rdpGuestAppraisal) class="rdp-appraisal-only" @endif>
    <header>
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
    @php
        $sidebarState = request()->cookie('sidebarState', 'imup');
        $isCollapsed = $sidebarState === 'imdown';
    @endphp
    <section>
        @unless($rdpGuestAppraisal)
        <div class="navigation {{ $isCollapsed ? 'imdown' : 'imup' }}" id="navigation">
            <div class="nav">
                <div class="nav-header-container">
                    <div class="toggle-btn" onclick="toggleNavProperties()">
                        <img id="nav-main-icon" src="{{ asset($isCollapsed ? 'icons/toggle-nav-section.svg' : 'icons/toggle-nav-default.svg') }}" alt="Toggle Icon">
                    </div>
                    <div id="nav-contexts" class="subsystem-indicator">
                        <div class="subsystem-name">Records Disposition Program</div>
                        <div class="subsystem-version">Version: {{ \Illuminate\Support\Facades\Cache::remember('subsystem_ver_rdp', 3600, fn() => \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems')->where('subsystem_name', 'Records Disposition Program')->value('subsystem_version') ?? 'N/A') }}</div>
                    </div>
                </div>
                <hr>
                <div class="nav-list-container" onclick="resetNavProperties()">
                    <x-nav.rdp />
                </div>
                <div class="account-container">
                    <div class="account-label">
                        @if(\App\Services\ServerManagementService::isMultiServerActive())
                            <span class="account-server-label">{{ \App\Services\ServerManagementService::getServerLabel() }}</span>
                        @endif
                        <span class="account-email">{{ auth()->user()?->details?->email }}</span>
                    </div>
                </div>
            </div>
        </div>
        @endunless
        <div id="article-container" class="article-container {{ $isCollapsed ? 'imup' : 'imdown' }}">
            @unless($rdpGuestAppraisal)
            <x-nav.top-tabs system="rdp" />
            @endunless
            {{ $slot }}
        </div>
    </section>
    <x-chatify.floating-widget />
    @auth
    <livewire:components.session-guard />
    <livewire:components.report-problem />
    @endauth
    <x-rms-error />
    @stack('scripts')
</body>
</html>
