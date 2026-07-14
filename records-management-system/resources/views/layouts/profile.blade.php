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
        <span class="office_name">Records and Freedom of Information Office</span>
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
                        <span class="account-office">{{ auth()->user()?->details?->office?->office_name }}</span>
                    </div>
                </div>
            </div>
        </div>
        <div id="article-container" class="article-container imdown">
            {{ $slot }}
        </div>
    </section>
    @stack('scripts')
    <script>
    (function () {
        let clockTimer = null;
        const storagePrefix = 'rms-profile:';

        const getMaskStorageKey = (target) => storagePrefix + 'mask:' + target;
        const getPlainStorageKey = (target) => storagePrefix + 'plain:' + target;

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

        const normalizeTarget = (target) => (target || '').toString().trim();
        const readStoredBoolean = (key) => {
            try {
                const raw = sessionStorage.getItem(key);
                return raw === null ? null : JSON.parse(raw);
            } catch (e) {
                return null;
            }
        };
        const writeStoredBoolean = (key, value) => {
            sessionStorage.setItem(key, JSON.stringify(!!value));
        };

        const readMaskState = (target) => {
            const key = normalizeTarget(target);
            return key ? readStoredBoolean(getMaskStorageKey(key)) : null;
        };
        const writeMaskState = (target, value) => {
            const key = normalizeTarget(target);
            if (!key) {
                return;
            }
            writeStoredBoolean(getMaskStorageKey(key), value);
        };
        const readPlainValue = (target) => {
            const key = normalizeTarget(target);
            return key ? (sessionStorage.getItem(getPlainStorageKey(key)) || '') : '';
        };
        const writePlainValue = (target, value) => {
            const key = normalizeTarget(target);
            if (!key) {
                return;
            }
            sessionStorage.setItem(getPlainStorageKey(key), String(value || ''));
        };

        const maskPlaceholder = (plain) => '•'.repeat(Math.max(String(plain || '').length, 8));
        const findMaskedValueElement = (target) => {
            if (!target) {
                return null;
            }
            return document.querySelector(`[setid="${target}"] .masked-value`);
        };

        const updateButtonIcon = (buttonEl, isMasked) => {
            const icon = buttonEl.querySelector('.eye-icon');
            if (!icon) {
                return;
            }
            icon.innerHTML = isMasked ? '<i class="fa-solid fa-eye"></i>' : '<i class="fa-solid fa-eye-slash"></i>';
        };

        const refreshMaskedField = (buttonEl, valueEl) => {
            if (!buttonEl || !valueEl) {
                return;
            }
            const target = buttonEl.getAttribute('data-target');
            if (!target) {
                return;
            }

            const storedPlain = readPlainValue(target);
            const currentText = valueEl.textContent.trim();
            const actualPlain = storedPlain || (currentText.indexOf('•') === 0 ? '' : currentText);
            const storedMasked = readMaskState(target);
            const isMasked = storedMasked === false ? false : true;

            if (actualPlain) {
                writePlainValue(target, actualPlain);
            }

            valueEl.setAttribute('data-plain', actualPlain);
            valueEl.setAttribute('data-masked', String(isMasked));
            valueEl.textContent = isMasked ? maskPlaceholder(actualPlain) : actualPlain;
            updateButtonIcon(buttonEl, isMasked);
        };

        const refreshAllMaskedFields = () => {
            document.querySelectorAll('.mask-toggle[data-target]').forEach((buttonEl) => {
                const target = buttonEl.getAttribute('data-target');
                const valueEl = findMaskedValueElement(target);
                refreshMaskedField(buttonEl, valueEl);
            });
        };

        const toggleMaskedField = (buttonEl) => {
            const target = buttonEl.getAttribute('data-target');
            const valueEl = findMaskedValueElement(target);
            if (!valueEl || !target) {
                return;
            }

            const currentText = valueEl.textContent.trim();
            const plainText = readPlainValue(target) || (currentText.indexOf('•') === 0 ? '' : currentText);
            const currentlyMasked = valueEl.getAttribute('data-masked') !== 'false';
            const nextMasked = !currentlyMasked;

            writePlainValue(target, plainText);
            writeMaskState(target, nextMasked);
            valueEl.setAttribute('data-plain', plainText);
            valueEl.setAttribute('data-masked', String(nextMasked));
            valueEl.textContent = nextMasked ? maskPlaceholder(plainText) : plainText;
            updateButtonIcon(buttonEl, nextMasked);
        };

        const maskClickHandler = (event) => {
            const buttonEl = event.target.closest('.mask-toggle');
            if (!buttonEl) {
                return;
            }
            toggleMaskedField(buttonEl);
        };

        const ensureMaskClickHandler = () => {
            if (window.__rmsProfileMaskClickInstalled) {
                return;
            }
            window.__rmsProfileMaskClickInstalled = true;
            document.addEventListener('click', maskClickHandler);
        };

        const handleLivewireRefresh = () => {
            startClock();
            refreshAllMaskedFields();
        };

        const installLivewireHook = () => {
            if (window.__rmsProfileLivewireHookInstalled) {
                return;
            }
            if (window.Livewire && typeof Livewire.hook === 'function') {
                window.__rmsProfileLivewireHookInstalled = true;
                Livewire.hook('message.processed', handleLivewireRefresh);
            }
        };

        const initialize = () => {
            ensureMaskClickHandler();
            startClock();
            refreshAllMaskedFields();
            installLivewireHook();
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initialize);
        } else {
            initialize();
        }

        document.addEventListener('livewire:load', installLivewireHook);
    })();
    </script>
</body>
</html>
