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
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    @vite(['resources/css/dashboard.css', 'resources/css/notifications.css', 'resources/js/dashboard.js'])
    <title>CSPC - Admin Console</title>
    @stack('styles')
</head>
<body>
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
        <div class="navigation {{ $isCollapsed ? 'imdown' : 'imup' }}" id="navigation">
            <div class="nav">
                <div class="nav-header-container">
                    <div class="toggle-btn" onclick="toggleNavProperties()">
                        <img id="nav-main-icon" src="{{ asset($isCollapsed ? 'icons/toggle-nav-section.svg' : 'icons/toggle-nav-default.svg') }}" alt="Toggle Icon">
                    </div>
                    <div id="nav-contexts" class="subsystem-indicator">
                        <div class="subsystem-name">Admin Console</div>
                        <div class="subsystem-version">Version: {{ \DB::table('subsystems')->where('subsystem_name', 'Admin Console')->value('subsystem_version') ?? 'N/A' }}</div>
                    </div>
                </div>
                <hr>
                <div class="nav-list-container" onclick="resetNavProperties()">
                    <x-nav.admin />
                </div>
                <div class="account-container">
                    <div class="account-label">
                        <span class="account-email">{{ auth()->user()?->details?->email }}</span>
                    </div>
                </div>
            </div>
        </div>
        <div id="article-container" class="article-container {{ $isCollapsed ? 'imup' : 'imdown' }}">
            <x-nav.top-tabs system="admin" />
            {{ $slot }}
        </div>
    </section>
    <x-chatify.floating-widget />
    <livewire:components.scanner-modal />
    @auth
    <script>
        document.addEventListener("DOMContentLoaded", () => {
            // Heartbeat ping every 30s to keep session active while tab is visible
            setInterval(() => {
                if (document.visibilityState === 'visible') {
                    fetch('/api/session/ping', {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                            'Content-Type': 'application/json'
                        }
                    }).catch(() => {});
                }
            }, 30000);

            // Tab Closure Beacon
            window.addEventListener('pagehide', () => {
                const data = new FormData();
                data.append('_token', '{{ csrf_token() }}');
                navigator.sendBeacon('/api/session/tab-closed', data);
            });
        });
    </script>
    @endauth
    @stack('scripts')
</body>
</html>
