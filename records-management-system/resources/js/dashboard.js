function toggleDropdown() {
    const dropdownIcon = document.getElementById('dropdown-icon');
    const dropdown = document.getElementById('dropdown');

    if (!dropdown?.classList.contains('show')) {
        window.dispatchEvent(new CustomEvent('close-notifications'));
    }

    if (dropdownIcon?.classList.contains('rotate')) {
        dropdownIcon.classList.remove('rotate');
        dropdownIcon.classList.toggle('revert');
    } else {
        if (dropdownIcon?.classList.contains('revert')) {
            dropdownIcon.classList.remove('revert');
        }
        dropdownIcon?.classList.add('rotate');
    }

    dropdown?.classList.toggle('show');
}

function closeActionsDropdown() {
    const dropdown = document.getElementById('dropdown');
    const dropdownIcon = document.getElementById('dropdown-icon');
    if (dropdown?.classList.contains('show')) {
        dropdown.classList.remove('show');
        if (dropdownIcon?.classList.contains('rotate')) {
            dropdownIcon.classList.remove('rotate');
            dropdownIcon.classList.add('revert');
        }
    }
}

function toggleNavProperties() {
    const navigation = document.getElementById('navigation');
    const navMainIcon = document.getElementById('nav-main-icon');
    const articleContainer = document.getElementById('article-container');
    const toggleNavSection = window.assetPaths?.toggleNavSection ?? '/icons/toggle-nav-section.svg';
    const toggleNavDefault = window.assetPaths?.toggleNavDefault ?? '/icons/toggle-nav-default.svg';

    if (navigation.classList.contains('imup')) {
        navigation.classList.remove('imup');
        navigation.classList.add('imdown');
        articleContainer.classList.remove('imdown');
        articleContainer.classList.add('imup');
        navMainIcon.src = toggleNavSection;
        localStorage.setItem('sidebarState', 'imdown');
    } else {
        navigation.classList.remove('imdown');
        navigation.classList.add('imup');
        articleContainer.classList.remove('imup');
        articleContainer.classList.add('imdown');
        navMainIcon.src = toggleNavDefault;
        localStorage.setItem('sidebarState', 'imup');
    }
}

function resetNavProperties() {
    const navigation = document.getElementById('navigation');
    const navMainIcon = document.getElementById('nav-main-icon');
    const articleContainer = document.getElementById('article-container');
    const toggleNavDefault = window.assetPaths?.toggleNavDefault ?? '/icons/toggle-nav-default.svg';

    navigation.classList.remove('imdown');
    navigation.classList.add('imup');
    articleContainer.classList.remove('imup');
    articleContainer.classList.add('imdown');
    navMainIcon.src = toggleNavDefault;
    localStorage.setItem('sidebarState', 'imup');
}

function showButtonSection(button_target) {
    const navigation = document.getElementById('navigation');
    const pla = document.getElementById(button_target);

    if (navigation.classList.contains('imup')) {
        if (pla.classList.contains('show')) {
            pla.classList.remove('show');
        } else {
            // Find all other open button section containers and close them
            document.querySelectorAll('.button-section-container.show').forEach(container => {
                if (container.id !== button_target) {
                    container.classList.remove('show');
                }
            });
            pla.classList.add('show');
        }
    }
}

function proccedto(url) {
    window.location.href = url;
}

window.toggleDropdown = toggleDropdown;
window.toggleNavProperties = toggleNavProperties;
window.resetNavProperties = resetNavProperties;
window.showButtonSection = showButtonSection;
window.proccedto = proccedto;
window.closeActionsDropdown = closeActionsDropdown;

function initializeNavTooltips() {
    document.querySelectorAll('.button-container').forEach(container => {
        if (!container.querySelector('.nav-tooltip')) {
            const labelSpan = container.querySelector('.button-label span');
            if (labelSpan) {
                const tooltip = document.createElement('span');
                tooltip.className = 'nav-tooltip';
                tooltip.textContent = labelSpan.textContent.trim();
                container.appendChild(tooltip);
            }
        }
    });
}

function closeNavFlyout() {
    const existing = document.querySelector('.nav-flyout-popover');
    if (existing) {
        existing.remove();
    }
}
window.closeNavFlyout = closeNavFlyout;

function setupIconModeInteractions() {
    document.querySelectorAll('.button-section-container').forEach(section => {
        const buttonContainer = section.querySelector('.button-container');
        if (!buttonContainer || buttonContainer.dataset.iconEventsBound) return;
        buttonContainer.dataset.iconEventsBound = 'true';

        // LEFT-CLICK HANDLER IN ICON MODE (Expands Nav)
        buttonContainer.addEventListener('click', (e) => {
            const navigation = document.getElementById('navigation');
            if (navigation && navigation.classList.contains('imdown')) {
                e.preventDefault();
                e.stopPropagation();
                closeNavFlyout();

                // 1. Expand sidebar back to imup mode
                toggleNavProperties();

                // 2. Open section if it has sub-items
                const sectionId = section.id;
                if (sectionId) {
                    showButtonSection(sectionId);
                }
            }
        });

        // RIGHT-CLICK HANDLER IN ICON MODE (Direct Link or Flyout Menu)
        buttonContainer.addEventListener('contextmenu', (e) => {
            const navigation = document.getElementById('navigation');
            if (navigation && navigation.classList.contains('imdown')) {
                e.preventDefault();
                e.stopPropagation();
                closeNavFlyout();

                const functionsContainer = section.querySelector('.functions-container');
                const subButtons = functionsContainer ? functionsContainer.querySelectorAll('.function-button') : [];

                // CASE A: Single-page section (NO sub-items) -> Redirect directly
                if (!subButtons || subButtons.length === 0) {
                    const onclickAttr = buttonContainer.getAttribute('onclick');
                    if (onclickAttr && onclickAttr.includes("proccedto('")) {
                        const urlMatch = onclickAttr.match(/proccedto\('([^']+)'\)/);
                        if (urlMatch && urlMatch[1]) {
                            proccedto(urlMatch[1]);
                        }
                    }
                    return;
                }

                // CASE B: Multi-page section (HAS sub-items) -> Show Popout Flyout Card!
                const rect = buttonContainer.getBoundingClientRect();
                const titleSpan = buttonContainer.querySelector('.button-label span');
                const titleText = titleSpan ? titleSpan.textContent.trim().toUpperCase() : 'SECTION';
                const iconSvg = buttonContainer.querySelector('.button-icon svg');

                const flyout = document.createElement('div');
                flyout.className = 'nav-flyout-popover';
                flyout.style.top = `${Math.max(10, rect.top)}px`;
                flyout.style.left = `${rect.right + 12}px`;

                const header = document.createElement('div');
                header.className = 'flyout-header';

                const iconContainer = document.createElement('div');
                iconContainer.className = 'flyout-icon';
                if (iconSvg) {
                    iconContainer.appendChild(iconSvg.cloneNode(true));
                }

                const titleEl = document.createElement('span');
                titleEl.className = 'flyout-title';
                titleEl.textContent = titleText;

                header.appendChild(iconContainer);
                header.appendChild(titleEl);

                const menuList = document.createElement('div');
                menuList.className = 'flyout-menu-list';

                subButtons.forEach(sub => {
                    const subText = sub.textContent.trim();
                    const isActive = sub.classList.contains('force-active');
                    const onclickAttr = sub.getAttribute('onclick') || '';

                    const item = document.createElement('div');
                    item.className = `flyout-item ${isActive ? 'force-active' : ''}`;
                    item.textContent = subText;

                    item.addEventListener('click', () => {
                        closeNavFlyout();

                        const urlMatch = onclickAttr.match(/proccedto\('([^']+)'\)/);
                        if (urlMatch && urlMatch[1]) {
                            proccedto(urlMatch[1]);
                        }
                    });

                    menuList.appendChild(item);
                });

                flyout.appendChild(header);
                flyout.appendChild(menuList);
                document.body.appendChild(flyout);
            }
        });
    });
}

function initializeSidebarState() {
    const navigation = document.getElementById('navigation');
    const navMainIcon = document.getElementById('nav-main-icon');
    const articleContainer = document.getElementById('article-container');
    initializeNavTooltips();
    setupIconModeInteractions();
    if (!navigation || !articleContainer || !navMainIcon) return;

    const toggleNavSection = window.assetPaths?.toggleNavSection ?? '/icons/toggle-nav-section.svg';
    const toggleNavDefault = window.assetPaths?.toggleNavDefault ?? '/icons/toggle-nav-default.svg';

    const savedState = localStorage.getItem('sidebarState');

    if (savedState === 'imdown' || (window.innerWidth < 1024 && !savedState)) {
        // Keep closed / icon mode
        navigation.classList.remove('imup');
        navigation.classList.add('imdown');
        articleContainer.classList.remove('imdown');
        articleContainer.classList.add('imup');
        navMainIcon.src = toggleNavSection;
    } else {
        // Keep open / expanded mode
        navigation.classList.remove('imdown');
        navigation.classList.add('imup');
        articleContainer.classList.remove('imup');
        articleContainer.classList.add('imdown');
        navMainIcon.src = toggleNavDefault;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    initializeNavTooltips();
    setupIconModeInteractions();
    initializeSidebarState();
});
document.addEventListener('livewire:navigated', () => {
    initializeNavTooltips();
    setupIconModeInteractions();
    initializeSidebarState();
});
document.addEventListener('click', (e) => {
    if (!e.target.closest('.nav-flyout-popover') && !e.target.closest('.button-container')) {
        closeNavFlyout();
    }
});
document.addEventListener('contextmenu', (e) => {
    if (!e.target.closest('.button-container')) {
        closeNavFlyout();
    }
});
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeNavFlyout();
    }
});
window.addEventListener('resize', () => {
    closeNavFlyout();
    const navigation = document.getElementById('navigation');
    if (navigation && window.innerWidth < 1024 && navigation.classList.contains('imup')) {
        initializeSidebarState();
    }
});

window.initializeSidebarState = initializeSidebarState;
window.initializeNavTooltips = initializeNavTooltips;
window.setupIconModeInteractions = setupIconModeInteractions;

/* ==========================================================================
   RMS Synchronized Cross-Tab Settings Auto-Refresh Engine
   ========================================================================== */
(function() {
    const SYNC_CHANNEL_NAME = 'rms_settings_sync_channel';
    const STORAGE_KEY = 'rms_settings_sync_event';
    const COUNTDOWN_SECONDS = 5;
    let refreshCountdownTimer = null;
    let refreshToastElement = null;
    let lastHandledEventId = null;

    // Create BroadcastChannel if supported in the browser
    let broadcastChannel = null;
    try {
        if ('BroadcastChannel' in window) {
            broadcastChannel = new BroadcastChannel(SYNC_CHANNEL_NAME);
            broadcastChannel.onmessage = (event) => {
                if (event && event.data) {
                    handleSyncEvent(event.data, false);
                }
            };
        }
    } catch (e) {
        console.warn('RMS Sync: BroadcastChannel unavailable:', e);
    }

    // Storage event fallback (guarantees cross-tab and cross-window sync)
    window.addEventListener('storage', (e) => {
        if (e.key === 'rms-theme' && e.newValue) {
            document.documentElement.setAttribute('data-theme', e.newValue);
        }
        if (e.key === STORAGE_KEY && e.newValue) {
            try {
                const data = JSON.parse(e.newValue);
                handleSyncEvent(data, false);
            } catch (err) {}
        }
    });

    // Global dispatch function to broadcast setting updates
    function broadcastSettingsChanged(type = 'profile_preference', message = '') {
        const eventData = {
            id: 'sync_' + Date.now() + '_' + Math.random().toString(36).substring(2, 8),
            type: type,
            message: message || (type === 'site_settings' ? 'Site settings updated.' : 'Preferences updated.'),
            timestamp: Date.now()
        };

        if (broadcastChannel) {
            try {
                broadcastChannel.postMessage(eventData);
            } catch (err) {}
        }

        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(eventData));
        } catch (err) {}

        handleSyncEvent(eventData, true);
    }
    window.rmsBroadcastSettingsChanged = broadcastSettingsChanged;

    // Listen for Livewire or custom browser events dispatched on the current tab
    window.addEventListener('rms-settings-changed', (e) => {
        const detail = e.detail || {};
        const type = (typeof detail === 'object' && detail.type) ? detail.type : (typeof detail === 'string' ? detail : 'profile_preference');
        const message = (typeof detail === 'object' && detail.message) ? detail.message : '';
        broadcastSettingsChanged(type, message);
    });

    function handleSyncEvent(data, isInitiator) {
        if (!data || !data.id || data.id === lastHandledEventId) return;
        // Ignore stale events older than 10 seconds
        if (data.timestamp && (Date.now() - data.timestamp > 10000)) return;
        lastHandledEventId = data.id;

        if (data.type === 'theme_change') {
            const currentTheme = localStorage.getItem('rms-theme') || 'light';
            document.documentElement.setAttribute('data-theme', currentTheme);
        }

        showRefreshCountdownBanner(data, isInitiator);
    }

    function showRefreshCountdownBanner(data, isInitiator) {
        if (refreshCountdownTimer) {
            clearInterval(refreshCountdownTimer);
            refreshCountdownTimer = null;
        }
        if (refreshToastElement) {
            refreshToastElement.remove();
            refreshToastElement = null;
        }

        const isSiteSettings = data.type === 'site_settings';
        const titleText = isSiteSettings 
            ? 'Site Settings Updated' 
            : 'Preferences Updated';
            
        const descText = isSiteSettings
            ? 'Administrator updated system site settings.'
            : (isInitiator ? 'Your profile preferences were updated.' : 'Preferences were updated in another tab.');

        let remaining = COUNTDOWN_SECONDS;

        const toast = document.createElement('div');
        toast.id = 'rms-sync-refresh-toast';
        toast.className = 'rms-sync-toast';
        toast.innerHTML = `
            <div class="rms-sync-toast-content">
                <div class="rms-sync-icon-wrapper">
                    <svg class="rms-sync-spin-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/>
                    </svg>
                </div>
                <div class="rms-sync-text">
                    <div class="rms-sync-title">${titleText}</div>
                    <div class="rms-sync-desc">${descText} Refreshing page in <strong id="rms-sync-countdown-sec" class="rms-sync-timer-badge">${remaining}s</strong> to apply changes...</div>
                </div>
                <div class="rms-sync-actions">
                    <button type="button" id="rms-sync-refresh-btn" class="rms-sync-btn-refresh">Refresh Now</button>
                    <button type="button" id="rms-sync-dismiss-btn" class="rms-sync-btn-dismiss" title="Dismiss auto-refresh">✕</button>
                </div>
            </div>
            <div class="rms-sync-progress-bar">
                <div id="rms-sync-progress-fill" class="rms-sync-progress-fill"></div>
            </div>
        `;

        document.body.appendChild(toast);
        refreshToastElement = toast;

        // Animate entrance
        requestAnimationFrame(() => {
            toast.classList.add('rms-sync-toast-visible');
            const progressFill = toast.querySelector('#rms-sync-progress-fill');
            if (progressFill) {
                progressFill.style.transition = `width ${COUNTDOWN_SECONDS}s linear`;
                progressFill.style.width = '100%';
            }
        });

        // Event listeners
        const refreshBtn = toast.querySelector('#rms-sync-refresh-btn');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => {
                window.location.reload();
            });
        }

        const dismissBtn = toast.querySelector('#rms-sync-dismiss-btn');
        if (dismissBtn) {
            dismissBtn.addEventListener('click', () => {
                if (refreshCountdownTimer) {
                    clearInterval(refreshCountdownTimer);
                    refreshCountdownTimer = null;
                }
                toast.classList.remove('rms-sync-toast-visible');
                setTimeout(() => toast.remove(), 300);
            });
        }

        // 5-second countdown loop
        const countdownEl = toast.querySelector('#rms-sync-countdown-sec');
        refreshCountdownTimer = setInterval(() => {
            remaining--;
            if (countdownEl) {
                countdownEl.textContent = `${remaining}s`;
            }
            if (remaining <= 0) {
                clearInterval(refreshCountdownTimer);
                refreshCountdownTimer = null;
                window.location.reload();
            }
        }, 1000);
    }
})();