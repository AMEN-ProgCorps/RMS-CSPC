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

function persistSidebarState(state) {
    try {
        localStorage.setItem('sidebarState', state);
        localStorage.setItem('dcs-sidebar-collapsed', state === 'imdown' ? '1' : '0');
    } catch (e) {}
    document.cookie = 'sidebarState=' + encodeURIComponent(state) + '; path=/; max-age=31536000; SameSite=Lax';
}

function toggleNavProperties() {
    const navigation = document.getElementById('navigation');
    const navMainIcon = document.getElementById('nav-main-icon');
    const articleContainer = document.getElementById('article-container');
    const dcsToggle = document.getElementById('dcs-sidebar-collapsed');

    if (dcsToggle && !navigation) {
        dcsToggle.checked = !dcsToggle.checked;
        dcsToggle.dispatchEvent(new Event('change'));
        return;
    }

    const toggleNavSection = window.assetPaths?.toggleNavSection ?? '/icons/toggle-nav-section.svg';
    const toggleNavDefault = window.assetPaths?.toggleNavDefault ?? '/icons/toggle-nav-default.svg';

    if (!navigation || !articleContainer) return;

    if (navigation.classList.contains('imup')) {
        navigation.classList.remove('imup');
        navigation.classList.add('imdown');
        articleContainer.classList.remove('imdown');
        articleContainer.classList.add('imup');
        if (navMainIcon) navMainIcon.src = toggleNavSection;
        persistSidebarState('imdown');
    } else {
        navigation.classList.remove('imdown');
        navigation.classList.add('imup');
        articleContainer.classList.remove('imup');
        articleContainer.classList.add('imdown');
        if (navMainIcon) navMainIcon.src = toggleNavDefault;
        persistSidebarState('imup');
    }
}

function resetNavProperties() {
    const navigation = document.getElementById('navigation');
    const navMainIcon = document.getElementById('nav-main-icon');
    const articleContainer = document.getElementById('article-container');
    const dcsToggle = document.getElementById('dcs-sidebar-collapsed');

    if (dcsToggle && !navigation) {
        if (dcsToggle.checked) {
            dcsToggle.checked = false;
            dcsToggle.dispatchEvent(new Event('change'));
        }
        return;
    }

    const toggleNavDefault = window.assetPaths?.toggleNavDefault ?? '/icons/toggle-nav-default.svg';

    if (!navigation || !articleContainer) return;

    navigation.classList.remove('imdown');
    navigation.classList.add('imup');
    articleContainer.classList.remove('imup');
    articleContainer.classList.add('imdown');
    if (navMainIcon) navMainIcon.src = toggleNavDefault;
    persistSidebarState('imup');
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

function showNavFlyoutMenu(buttonContainer, section) {
    closeNavFlyout();

    const functionsContainer = section.querySelector('.functions-container');
    const subButtons = functionsContainer ? functionsContainer.querySelectorAll('.function-button') : [];
    if (!subButtons || subButtons.length === 0) return;

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

    const treeGroups = functionsContainer ? functionsContainer.querySelectorAll('.nav-tree-group') : [];

    if (treeGroups && treeGroups.length > 0) {
        treeGroups.forEach(group => {
            const groupTitleEl = group.querySelector('.nav-tree-title');
            const groupName = group.dataset.groupName || (groupTitleEl ? groupTitleEl.textContent.trim() : '');
            const groupButtons = group.querySelectorAll('.function-button');

            if (groupButtons.length > 0) {
                if (groupName) {
                    const groupHeader = document.createElement('div');
                    groupHeader.className = 'flyout-group-title';
                    groupHeader.textContent = groupName.toUpperCase();
                    menuList.appendChild(groupHeader);
                }

                groupButtons.forEach(sub => {
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
            }
        });
    } else {
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
    }

    flyout.appendChild(header);
    flyout.appendChild(menuList);
    document.body.appendChild(flyout);
}

function setupIconModeInteractions() {
    document.querySelectorAll('.button-section-container').forEach(section => {
        const buttonContainer = section.querySelector('.button-container');
        if (!buttonContainer || buttonContainer.dataset.iconEventsBound) return;
        buttonContainer.dataset.iconEventsBound = 'true';

        // LEFT-CLICK: Direct Navigation (or flyout if no direct route)
        buttonContainer.addEventListener('click', (e) => {
            const navigation = document.getElementById('navigation');
            if (navigation && navigation.classList.contains('imdown')) {
                const onclickAttr = buttonContainer.getAttribute('onclick') || '';

                // CASE A: Direct Link with proccedto
                if (onclickAttr.includes("proccedto('")) {
                    closeNavFlyout();
                    const urlMatch = onclickAttr.match(/proccedto\('([^']+)'\)/);
                    if (urlMatch && urlMatch[1]) {
                        e.preventDefault();
                        e.stopPropagation();
                        proccedto(urlMatch[1]);
                    }
                    return;
                }

                // CASE B: Has Sub-Items / Accordion -> Show Floating Flyout Menu
                const functionsContainer = section.querySelector('.functions-container');
                const subButtons = functionsContainer ? functionsContainer.querySelectorAll('.function-button') : [];

                if (subButtons && subButtons.length > 0) {
                    e.preventDefault();
                    e.stopPropagation();
                    showNavFlyoutMenu(buttonContainer, section);
                }
            }
        });

        // RIGHT-CLICK: Opens Flyout Menu for sections with sub-items; redirects directly for single items
        buttonContainer.addEventListener('contextmenu', (e) => {
            const navigation = document.getElementById('navigation');
            if (navigation && navigation.classList.contains('imdown')) {
                const functionsContainer = section.querySelector('.functions-container');
                const subButtons = functionsContainer ? functionsContainer.querySelectorAll('.function-button') : [];

                if (subButtons && subButtons.length > 0) {
                    e.preventDefault();
                    e.stopPropagation();
                    showNavFlyoutMenu(buttonContainer, section);
                } else {
                    const onclickAttr = buttonContainer.getAttribute('onclick') || '';
                    if (onclickAttr.includes("proccedto('")) {
                        const urlMatch = onclickAttr.match(/proccedto\('([^']+)'\)/);
                        if (urlMatch && urlMatch[1]) {
                            e.preventDefault();
                            e.stopPropagation();
                            closeNavFlyout();
                            proccedto(urlMatch[1]);
                        }
                    }
                }
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

    const getCookie = (name) => {
        const match = document.cookie.match(new RegExp('(^|;\\s*)' + name + '=([^;]*)'));
        return match ? decodeURIComponent(match[2]) : null;
    };

    let savedState = null;
    try {
        savedState = localStorage.getItem('sidebarState');
    } catch (e) {}
    if (!savedState) {
        savedState = getCookie('sidebarState');
    }

    if (savedState === 'imdown' || (window.innerWidth < 1024 && !savedState)) {
        // Keep closed / icon mode
        navigation.classList.remove('imup');
        navigation.classList.add('imdown');
        articleContainer.classList.remove('imdown');
        articleContainer.classList.add('imup');
        navMainIcon.src = toggleNavSection;
        persistSidebarState('imdown');
    } else {
        // Keep open / expanded mode
        navigation.classList.remove('imdown');
        navigation.classList.add('imup');
        articleContainer.classList.remove('imup');
        articleContainer.classList.add('imdown');
        navMainIcon.src = toggleNavDefault;
        persistSidebarState('imup');
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
/* ==========================================================================
   RMS Universal Configurable Modal Closer Engine
   ========================================================================== */
function getRMSModalCloseKey() {
    if (window.RMS_MODAL_CLOSE_KEY !== undefined && window.RMS_MODAL_CLOSE_KEY !== null) {
        if (window.RMS_MODAL_CLOSE_KEY === '' || window.RMS_MODAL_CLOSE_KEY.toLowerCase() === 'none') {
            return '';
        }
        return window.RMS_MODAL_CLOSE_KEY;
    }
    try {
        const stored = localStorage.getItem('rms-modal-close-key');
        if (stored !== null) {
            if (stored === '' || stored.toLowerCase() === 'none') return '';
            return stored;
        }
    } catch (e) {}
    return 'Escape';
}
window.getRMSModalCloseKey = getRMSModalCloseKey;

function isModalKeyMatch(event, targetKey) {
    if (!targetKey) return false;
    const normTarget = targetKey.trim().toLowerCase();
    const eventKey = (event.key || '').trim().toLowerCase();
    const eventCode = (event.code || '').trim().toLowerCase();

    if (normTarget === 'escape' || normTarget === 'esc') {
        return eventKey === 'escape' || eventKey === 'esc' || eventCode === 'escape';
    }

    if (eventKey === normTarget || eventCode === normTarget) {
        return true;
    }

    // Handle alphanumeric and function keys, e.g., 'f2', 'q', 'x', '1', '`'
    if (eventCode === 'key' + normTarget || eventCode === 'digit' + normTarget) {
        return true;
    }
    if (normTarget.startsWith('key') && eventKey === normTarget.substring(3)) {
        return true;
    }
    if (normTarget === 'backquote' && (eventKey === '`' || eventKey === '~')) {
        return true;
    }

    return false;
}

function isExemptedModalElement(element) {
    if (!element) return false;
    // Exempt: Action container dropdown, Notification center, and Chatify widget
    return !!element.closest(
        '.actions-container, #dropdown, .notification-container, .notif-wrapper, .notif-dropdown, #chatify-global-widget, #chatify-widget-card, .chatify-widget, [id^="chatify"], .nav-flyout-popover'
    );
}

function isElementVisibleForModal(el) {
    if (!el) return false;
    if (el.style && el.style.display === 'none') return false;
    try {
        const style = window.getComputedStyle(el);
        if (style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0') {
            return false;
        }
        const rect = el.getBoundingClientRect();
        return (rect.width > 0 && rect.height > 0) || (style.position === 'fixed' || style.position === 'absolute');
    } catch (e) {
        return false;
    }
}

function handleUniversalModalClose(event) {
    const configuredKey = getRMSModalCloseKey();
    if (!isModalKeyMatch(event, configuredKey)) {
        return false;
    }

    // Safety guard: if configured key is a printable character (not Escape/F-key),
    // do not trigger modal close if user is currently typing in an input, textarea, or contenteditable
    const isEscapeKey = isModalKeyMatch(event, 'Escape');
    const activeEl = document.activeElement;
    if (!isEscapeKey && activeEl) {
        const tagName = activeEl.tagName ? activeEl.tagName.toLowerCase() : '';
        if (tagName === 'input' || tagName === 'textarea' || tagName === 'select' || activeEl.isContentEditable) {
            return false;
        }
    }

    // 1. Dynamic QR Print Modal
    const dynamicQrPrintModal = document.getElementById('dynamicQrPrintModal');
    if (dynamicQrPrintModal && dynamicQrPrintModal.style.display !== 'none' && !isExemptedModalElement(dynamicQrPrintModal)) {
        if (typeof window.closeDynamicPrintModal === 'function') {
            window.closeDynamicPrintModal();
            event.preventDefault();
            event.stopPropagation();
            return true;
        }
    }

    // 2. DTS QR View Modal
    const dtsQrViewModal = document.getElementById('dts-qr-view-modal');
    if (dtsQrViewModal && dtsQrViewModal.style.display !== 'none' && !isExemptedModalElement(dtsQrViewModal)) {
        if (typeof window.closeQrViewModal === 'function') {
            window.closeQrViewModal();
            event.preventDefault();
            event.stopPropagation();
            return true;
        }
    }

    // 3. DTS Scanner Modal
    const scannerBackdrop = document.querySelector('.global-scanner-backdrop');
    if (scannerBackdrop && isElementVisibleForModal(scannerBackdrop) && !isExemptedModalElement(scannerBackdrop)) {
        const closeBtn = scannerBackdrop.querySelector('button[wire\\:click*="closeModal"], button[wire\\:click*="close"]');
        if (closeBtn) {
            closeBtn.click();
            event.preventDefault();
            event.stopPropagation();
            return true;
        }
        if (window.Livewire) {
            window.Livewire.dispatch('close-scanner-modal');
            event.preventDefault();
            event.stopPropagation();
            return true;
        }
    }

    // 4. DTS Export Modal
    const exportModalBackdrop = document.querySelector('.dts-export-modal-backdrop');
    if (exportModalBackdrop && isElementVisibleForModal(exportModalBackdrop) && !isExemptedModalElement(exportModalBackdrop)) {
        const closeBtn = exportModalBackdrop.querySelector('.dts-export-modal-close-btn, .dts-export-btn-cancel, button[wire\\:click="closeExportModal"]');
        if (closeBtn) {
            closeBtn.click();
            event.preventDefault();
            event.stopPropagation();
            return true;
        }
    }

    // 5. Query all open modal overlays/cards on the page
    const modalCandidates = Array.from(document.querySelectorAll(
        '.modal-backdrop, .global-scanner-backdrop, .dts-export-modal-backdrop, .ars-modal-overlay, .rdp-modal-backdrop, .inactivity-modal, [role="dialog"], .modal, div[class*="modal-backdrop"], div[class*="modal-overlay"], div[class*="modal-card"], div[class*="-modal"], div[class*="-drawer"]'
    )).filter(el => !isExemptedModalElement(el) && isElementVisibleForModal(el));

    if (modalCandidates.length > 0) {
        // Pick the topmost active modal (last in DOM)
        const topModal = modalCandidates[modalCandidates.length - 1];

        // Search for the closest close or cancel button inside this modal
        const closeSelectors = [
            'button.modal-close-btn',
            'button.modal-close',
            'button.dts-export-modal-close-btn',
            'button.ars-modal-close',
            'button.rdp-modal-close',
            'button.ev-modal-close',
            'button.dash-cl-modal-close',
            'button.mf-drawer-close',
            'button.rpt-filter-close',
            'button[wire\\:click*="close" i]',
            'button[wire\\:click*="cancel" i]',
            'button[wire\\:click*="toggle" i]',
            'button[aria-label="Close" i]',
            'button[title="Close" i]',
            'button[title="Cancel" i]',
            'button.btn-cancel',
            'button.ars-btn-secondary',
            'button.dts-export-btn-cancel',
            'button.upd-modal-cancel',
            'button.rb-modal-cancel',
            'button.btn-delete',
            'button[onclick*="close" i]',
            'button[onclick*="toggle" i]',
            'button[x-on\\:click*="close" i]',
            'button[@click*="close" i]',
            'button.btn-close',
            '[data-dismiss="modal"]'
        ];

        for (const sel of closeSelectors) {
            const btn = topModal.querySelector(sel);
            if (btn && isElementVisibleForModal(btn) && !btn.disabled) {
                btn.click();
                event.preventDefault();
                event.stopPropagation();
                return true;
            }
        }

        // If modal backdrop itself has a click handler (e.g. wire:click="closeTransaction")
        if (topModal.hasAttribute('wire:click') || topModal.hasAttribute('onclick') || topModal.hasAttribute('@click') || topModal.hasAttribute('x-on:click')) {
            topModal.click();
            event.preventDefault();
            event.stopPropagation();
            return true;
        }
    }

    return false;
}
window.handleUniversalModalClose = handleUniversalModalClose;

function getRMSSidebarToggleKey() {
    if (window.RMS_SIDEBAR_TOGGLE_KEY !== undefined && window.RMS_SIDEBAR_TOGGLE_KEY !== null && window.RMS_SIDEBAR_TOGGLE_KEY !== '') {
        return window.RMS_SIDEBAR_TOGGLE_KEY;
    }
    try {
        const stored = localStorage.getItem('rms-sidebar-toggle-key');
        if (stored && stored !== 'none') return stored;
    } catch (e) {}
    return '';
}
window.getRMSSidebarToggleKey = getRMSSidebarToggleKey;

function handleSidebarToggleShortcut(event) {
    const targetKey = getRMSSidebarToggleKey();
    if (!targetKey || targetKey.toLowerCase() === 'none') {
        return false;
    }

    if (!isModalKeyMatch(event, targetKey)) {
        return false;
    }

    // Do not trigger if user is actively typing in an input, textarea, select, or contenteditable
    const activeEl = document.activeElement;
    if (activeEl) {
        const tagName = activeEl.tagName ? activeEl.tagName.toLowerCase() : '';
        if (tagName === 'input' || tagName === 'textarea' || tagName === 'select' || activeEl.isContentEditable) {
            return false;
        }
    }

    // Do not toggle sidebar if a modal is actively open
    const modalCandidates = Array.from(document.querySelectorAll(
        '.modal-backdrop, .global-scanner-backdrop, .dts-export-modal-backdrop, .ars-modal-overlay, .rdp-modal-backdrop, .inactivity-modal, [role="dialog"], .modal'
    )).filter(el => !isExemptedModalElement(el) && isElementVisibleForModal(el));
    if (modalCandidates.length > 0) {
        return false;
    }

    toggleNavProperties();
    event.preventDefault();
    event.stopPropagation();
    return true;
}
window.handleSidebarToggleShortcut = handleSidebarToggleShortcut;

function getRMSActionToggleKey() {
    if (window.RMS_ACTION_TOGGLE_KEY !== undefined && window.RMS_ACTION_TOGGLE_KEY !== null && window.RMS_ACTION_TOGGLE_KEY !== '') {
        return window.RMS_ACTION_TOGGLE_KEY;
    }
    try {
        const stored = localStorage.getItem('rms-action-toggle-key');
        if (stored && stored !== 'none') return stored;
    } catch (e) {}
    return '';
}
window.getRMSActionToggleKey = getRMSActionToggleKey;

function getRMSNotifToggleKey() {
    if (window.RMS_NOTIF_TOGGLE_KEY !== undefined && window.RMS_NOTIF_TOGGLE_KEY !== null && window.RMS_NOTIF_TOGGLE_KEY !== '') {
        return window.RMS_NOTIF_TOGGLE_KEY;
    }
    try {
        const stored = localStorage.getItem('rms-notif-toggle-key');
        if (stored && stored !== 'none') return stored;
    } catch (e) {}
    return '';
}
window.getRMSNotifToggleKey = getRMSNotifToggleKey;

function getRMSChatifyToggleKey() {
    if (window.RMS_CHATIFY_TOGGLE_KEY !== undefined && window.RMS_CHATIFY_TOGGLE_KEY !== null && window.RMS_CHATIFY_TOGGLE_KEY !== '') {
        return window.RMS_CHATIFY_TOGGLE_KEY;
    }
    try {
        const stored = localStorage.getItem('rms-chatify-toggle-key');
        if (stored && stored !== 'none') return stored;
    } catch (e) {}
    return '';
}
window.getRMSChatifyToggleKey = getRMSChatifyToggleKey;

function isTypingActive() {
    const activeEl = document.activeElement;
    if (activeEl) {
        const tagName = activeEl.tagName ? activeEl.tagName.toLowerCase() : '';
        if (tagName === 'input' || tagName === 'textarea' || tagName === 'select' || activeEl.isContentEditable) {
            return true;
        }
    }
    return false;
}

function handleActionToggleShortcut(event) {
    const targetKey = getRMSActionToggleKey();
    if (!targetKey || targetKey.toLowerCase() === 'none') return false;
    if (!isModalKeyMatch(event, targetKey)) return false;
    if (isTypingActive()) return false;

    if (typeof window.toggleDropdown === 'function') {
        window.toggleDropdown();
    } else {
        const actionBtn = document.querySelector('.action_button');
        if (actionBtn) actionBtn.click();
    }
    event.preventDefault();
    event.stopPropagation();
    return true;
}
window.handleActionToggleShortcut = handleActionToggleShortcut;

function handleNotificationToggleShortcut(event) {
    const targetKey = getRMSNotifToggleKey();
    if (!targetKey || targetKey.toLowerCase() === 'none') return false;
    if (!isModalKeyMatch(event, targetKey)) return false;
    if (isTypingActive()) return false;

    const notifBtn = document.querySelector('.notif-bell-btn');
    if (notifBtn) {
        notifBtn.click();
    } else {
        window.dispatchEvent(new CustomEvent('toggle-notifications'));
    }
    event.preventDefault();
    event.stopPropagation();
    return true;
}
window.handleNotificationToggleShortcut = handleNotificationToggleShortcut;

function handleChatifyToggleShortcut(event) {
    const targetKey = getRMSChatifyToggleKey();
    if (!targetKey || targetKey.toLowerCase() === 'none') return false;
    if (!isModalKeyMatch(event, targetKey)) return false;
    if (isTypingActive()) return false;

    const chatifyBtn = document.getElementById('chatify-widget-btn');
    if (chatifyBtn) {
        chatifyBtn.click();
    }
    event.preventDefault();
    event.stopPropagation();
    return true;
}
window.handleChatifyToggleShortcut = handleChatifyToggleShortcut;

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeNavFlyout();
    }
    const handledModal = handleUniversalModalClose(e);
    if (!handledModal) {
        if (!handleSidebarToggleShortcut(e)) {
            if (!handleActionToggleShortcut(e)) {
                if (!handleNotificationToggleShortcut(e)) {
                    handleChatifyToggleShortcut(e);
                }
            }
        }
    }
});
window.addEventListener('resize', () => {
    closeNavFlyout();
    const navigation = document.getElementById('navigation');
    if (navigation && window.innerWidth < 1024 && navigation.classList.contains('imup')) {
        initializeSidebarState();
    }
});

window.addEventListener('rms-modal-key-changed', (e) => {
    if (e && e.detail) {
        window.RMS_MODAL_CLOSE_KEY = typeof e.detail === 'string' ? e.detail : (e.detail.key || 'Escape');
    }
});

window.addEventListener('rms-sidebar-key-changed', (e) => {
    if (e) {
        const keyVal = typeof e.detail === 'string' ? e.detail : (e.detail?.key || '');
        window.RMS_SIDEBAR_TOGGLE_KEY = (keyVal === 'none' || !keyVal) ? '' : keyVal;
    }
});

window.addEventListener('rms-action-key-changed', (e) => {
    if (e) {
        const keyVal = typeof e.detail === 'string' ? e.detail : (e.detail?.key || '');
        window.RMS_ACTION_TOGGLE_KEY = (keyVal === 'none' || !keyVal) ? '' : keyVal;
    }
});

window.addEventListener('rms-notif-key-changed', (e) => {
    if (e) {
        const keyVal = typeof e.detail === 'string' ? e.detail : (e.detail?.key || '');
        window.RMS_NOTIF_TOGGLE_KEY = (keyVal === 'none' || !keyVal) ? '' : keyVal;
    }
});

window.addEventListener('rms-chatify-key-changed', (e) => {
    if (e) {
        const keyVal = typeof e.detail === 'string' ? e.detail : (e.detail?.key || '');
        window.RMS_CHATIFY_TOGGLE_KEY = (keyVal === 'none' || !keyVal) ? '' : keyVal;
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
            if (!document.querySelector('meta[name="rms-portal"]')) {
                document.documentElement.setAttribute('data-theme', e.newValue);
            }
        }
        if (e.key === 'rms-modal-close-key' && e.newValue) {
            window.RMS_MODAL_CLOSE_KEY = e.newValue;
        }
        if (e.key === 'rms-sidebar-toggle-key') {
            window.RMS_SIDEBAR_TOGGLE_KEY = (e.newValue === 'none' || !e.newValue) ? '' : e.newValue;
        }
        if (e.key === 'rms-action-toggle-key') {
            window.RMS_ACTION_TOGGLE_KEY = (e.newValue === 'none' || !e.newValue) ? '' : e.newValue;
        }
        if (e.key === 'rms-notif-toggle-key') {
            window.RMS_NOTIF_TOGGLE_KEY = (e.newValue === 'none' || !e.newValue) ? '' : e.newValue;
        }
        if (e.key === 'rms-chatify-toggle-key') {
            window.RMS_CHATIFY_TOGGLE_KEY = (e.newValue === 'none' || !e.newValue) ? '' : e.newValue;
        }
        if (e.key === 'sidebarState' && e.newValue) {
            const dcsToggle = document.getElementById('dcs-sidebar-collapsed');
            if (dcsToggle) {
                const shouldCollapse = e.newValue === 'imdown';
                if (dcsToggle.checked !== shouldCollapse) {
                    dcsToggle.checked = shouldCollapse;
                    dcsToggle.dispatchEvent(new Event('change'));
                }
            } else if (typeof initializeSidebarState === 'function') {
                initializeSidebarState();
            }
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
            if (!document.querySelector('meta[name="rms-portal"]')) {
                const currentTheme = localStorage.getItem('rms-theme') || 'light';
                document.documentElement.setAttribute('data-theme', currentTheme);
            }
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