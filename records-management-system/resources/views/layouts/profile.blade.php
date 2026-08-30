<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="{{ asset('images/cspc.webp') }}" type="image/webp">
    
    <script>
        (function() {
            var theme = localStorage.getItem('rms-theme') || '{{ auth()->user()?->theme() ?? "light" }}';
            document.documentElement.setAttribute('data-theme', theme);
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

    @vite(['resources/css/dashboard.css', 'resources/css/notifications.css', 'resources/js/dashboard.js'])
    <title>CSPC - Profile Manager</title>
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
                        <div class="subsystem-name">Profile Manager</div>
                        <div class="subsystem-version">Version: {{ \DB::table('subsystems')->where('subsystem_name', 'Profile Manager')->value('subsystem_version') ?? 'N/A' }}</div>
                    </div>
                </div>
                <hr>
                <div class="nav-list-container" onclick="resetNavProperties()">
                    <x-nav.profile />
                </div>
                <div class="account-container">
                    <div class="account-label">
                        <span class="account-email">{{ auth()->user()?->details?->email }}</span>
                    </div>
                </div>
            </div>
        </div>
        <div id="article-container" class="article-container {{ $isCollapsed ? 'imup' : 'imdown' }}">
            {{ $slot }}
        </div>
    </section>
    @stack('scripts')
    <script>
    (function () {
        let clockTimer = null;

        // Clean up any legacy or stale rms-profile storage keys
        try {
            for (let i = sessionStorage.length - 1; i >= 0; i--) {
                const k = sessionStorage.key(i);
                if (k && k.startsWith('rms-profile:')) {
                    sessionStorage.removeItem(k);
                }
            }
        } catch (e) {}

        const queryClockElements = () => {
            const root = document.querySelector('.hero-clock');
            if (!root) {
                return null;
            }
            const timeEl = root.querySelector('#currTime');
            const dateEl = root.querySelector('#currDate');
            return timeEl && dateEl ? { root, timeEl, dateEl } : null;
        };

        const renderClock = () => {
            const elements = queryClockElements();
            if (!elements) {
                return false;
            }
            const now = new Date();
            elements.timeEl.textContent = now.toLocaleTimeString([], {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            elements.dateEl.textContent = now.toLocaleDateString([], {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            return true;
        };

        const stopClock = () => {
            if (clockTimer !== null) {
                clearInterval(clockTimer);
                clockTimer = null;
            }
        };

        const startClock = () => {
            stopClock();
            if (!renderClock()) {
                return;
            }
            clockTimer = setInterval(() => {
                if (!renderClock()) {
                    stopClock();
                }
            }, 1000);
        };

        const initialize = () => {
            startClock();
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initialize);
        } else {
            initialize();
        }

        document.addEventListener('livewire:navigated', startClock);
    })();
    </script>
    <x-chatify.floating-widget />
</body>
</html>
