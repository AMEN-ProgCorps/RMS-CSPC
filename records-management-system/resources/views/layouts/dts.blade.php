<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="{{ asset('images/cspc.webp') }}" type="image/webp">
    
    <script>
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
    <title>CSPC - Document Tracking System</title>
    @stack('styles')
    <!-- Dynamic QR Code Print Modal Script -->
    <script src="{{ asset('js/qr-print-modal.js') }}?v={{ file_exists(public_path('js/qr-print-modal.js')) ? filemtime(public_path('js/qr-print-modal.js')) : time() }}"></script>
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
    <section>
        <div class="navigation imup" id="navigation">
            <div class="nav">
                <div class="nav-header-container">
                    <div class="toggle-btn" onclick="toggleNavProperties()">
                        <img id="nav-main-icon" src="{{ asset('icons/toggle-nav-default.svg') }}" alt="Toggle Icon">
                    </div>
                    <div id="nav-contexts" class="subsystem-indicator">
                        <div class="subsystem-name">Document Tracking System</div>
                        <div class="subsystem-version">Version: {{ \DB::table('subsystems')->where('subsystem_name', 'Document Tracking System')->value('subsystem_version') ?? 'N/A' }}</div>
                    </div>
                </div>
                <hr>
                <div class="nav-list-container" onclick="resetNavProperties()">
                    <x-nav.dts />
                </div>
                <div class="account-container">
                    <div class="account-label">
                        <span class="account-email">{{ auth()->user()?->details?->email }}</span>
                    </div>
                </div>
            </div>
        </div>
        <div id="article-container" class="article-container imdown">
            {{ $slot }}
    </section>
    <x-chatify.floating-widget />
    <livewire:components.scanner-modal />
    @stack('scripts')
    <script>
        document.addEventListener('submit', () => {
            window.submittedForm = true;
        });

        document.addEventListener('click', (e) => {
            if (e.target.closest('[wire\\:click="generateQrCode"]')) {
                window.submittedForm = true;
            }
        });

        document.addEventListener('livewire:initialized', () => {
            Livewire.hook('request', ({ component, commit, respond, succeed, fail }) => {
                succeed(({ response }) => {
                    if (window.submittedForm) {
                        setTimeout(() => {
                            const firstError = document.querySelector('.error-msg, .beta-error');
                            if (firstError) {
                                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                const input = firstError.closest('.form-col, .beta-form-group, .beta-form-group-full')?.querySelector('input, select, textarea');
                                if (input) {
                                    input.focus();
                                }
                            }
                            window.submittedForm = false;
                        }, 100);
                    }
                });
            });
        });
    </script>
</body>
</html>
