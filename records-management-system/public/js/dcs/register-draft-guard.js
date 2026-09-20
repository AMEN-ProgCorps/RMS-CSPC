/**
 * Draft confirmation modal + leave autosave for DCS Register / Edit.
 * Expects page hooks:
 *   window.__regDraftCanSave() -> boolean (enough data to save a draft)
 *   window.__regDraftHasProgress() -> boolean (user typed something worth keeping)
 *   window.__regDraftShouldAutosaveOnLeave() -> boolean (create page, or editing a draft)
 *   window.__regDraftPrepareSubmit() -> void (approval defaults, checklist sync, etc.)
 *   window.__regDraftShowErrors(message) -> void (optional)
 */
(function () {
    'use strict';

    let pendingLeaveUrl = null;
    let formSubmitting = false;

    function formEl() {
        return document.getElementById('masterForm');
    }

    function draftFlag() {
        return document.getElementById('saveAsDraft');
    }

    function ensureLeaveField() {
        const form = formEl();
        if (!form) return null;
        let field = document.getElementById('draftLeaveTo');
        if (!field) {
            field = document.createElement('input');
            field.type = 'hidden';
            field.id = 'draftLeaveTo';
            field.name = 'draft_leave_to';
            form.appendChild(field);
        }
        return field;
    }

    function openOverlay(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.add('is-open');
        el.setAttribute('aria-hidden', 'false');
    }

    function closeOverlay(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.remove('is-open');
        el.setAttribute('aria-hidden', 'true');
    }

    function call(name, fallback) {
        const fn = window[name];
        if (typeof fn === 'function') return fn();
        return fallback;
    }

    function showDraftError(message) {
        if (typeof window.showDraftNoticeModal === 'function') {
            window.showDraftNoticeModal(message);
            return;
        }
        if (typeof window.__regDraftShowErrors === 'function') {
            window.__regDraftShowErrors(message);
            return;
        }
        if (window.dcsShowToast) {
            window.dcsShowToast(message, 'error');
            return;
        }
        alert(message);
    }

    window.closeDraftNoticeModal = function () {
        closeOverlay('regDraftNoticeModal');
    };

    window.showDraftNoticeModal = function (message) {
        const lead = document.getElementById('regDraftNoticeLead');
        const body = document.getElementById('regDraftNoticeBody');
        const text = String(message || '').trim();
        if (lead) {
            lead.textContent = text
                || 'To save a draft, fill Document No plus at least one other masterlist field.';
        }
        if (body) {
            body.innerHTML = 'Add at least one more field such as <strong>Title</strong>, <strong>Effectivity Date</strong>, <strong>Pages</strong>, <strong>Keywords</strong>, <strong>Originator</strong>, or <strong>Source Unit</strong>.';
        }
        closeOverlay('regDraftConfirmModal');
        closeOverlay('regLeaveDraftModal');
        openOverlay('regDraftNoticeModal');
        if (typeof window.__regDraftShowErrors === 'function') {
            // Still run page-side highlighting without native alerts.
            window.__regDraftHighlightErrors = true;
            try { window.__regDraftShowErrors(text); } finally { window.__regDraftHighlightErrors = false; }
        }
    };

    function showSavingDraftOverlay(message) {
        let overlay = document.getElementById('regSavingOverlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'regSavingOverlay';
            overlay.className = 'reg-saving-overlay';
            overlay.setAttribute('role', 'status');
            overlay.setAttribute('aria-live', 'polite');
            overlay.innerHTML =
                '<div class="reg-saving-card">' +
                    '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>' +
                    '<div><strong></strong><p>Please wait a moment.</p></div>' +
                '</div>';
            document.body.appendChild(overlay);
        }
        const title = overlay.querySelector('strong');
        if (title) title.textContent = message || 'Saving draft…';
        overlay.classList.add('is-visible');
    }

    function submitAsDraft(leaveUrl) {
        const form = formEl();
        if (!form || formSubmitting) return false;

        if (!call('__regDraftCanSave', false)) {
            showDraftError('Select Version Type and Document Type, and fill Document No plus at least one other masterlist field before saving a draft.');
            return false;
        }

        const flag = draftFlag();
        if (flag) flag.value = '1';

        const leaveField = ensureLeaveField();
        if (leaveField) leaveField.value = leaveUrl || '';

        if (typeof window.__regDraftPrepareSubmit === 'function') {
            window.__regDraftPrepareSubmit();
        }
        if (typeof syncSyllabiContextHidden === 'function') {
            syncSyllabiContextHidden();
        }

        formSubmitting = true;
        window.__regFormSubmitting = true;
        showSavingDraftOverlay(leaveUrl ? 'Saving draft before leaving…' : 'Saving draft…');
        form.submit();
        return true;
    }

    window.openDraftConfirmModal = function () {
        if (!call('__regDraftCanSave', false)) {
            showDraftError('Select Version Type and Document Type, and fill Document No plus at least one other masterlist field before saving a draft.');
            return;
        }
        closeOverlay('regLeaveDraftModal');
        openOverlay('regDraftConfirmModal');
    };

    window.closeDraftConfirmModal = function () {
        closeOverlay('regDraftConfirmModal');
    };

    window.confirmDraftSaveFromModal = function () {
        closeOverlay('regDraftConfirmModal');
        submitAsDraft('');
    };

    window.closeLeaveDraftModal = function () {
        pendingLeaveUrl = null;
        closeOverlay('regLeaveDraftModal');
    };

    window.leaveWithoutSaving = function () {
        const url = pendingLeaveUrl;
        pendingLeaveUrl = null;
        formSubmitting = true;
        window.__regFormSubmitting = true;
        closeOverlay('regLeaveDraftModal');
        if (!url) return;
        let safe;
        try {
            const parsed = new URL(String(url), window.location.origin);
            if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') return;
            if (parsed.origin !== window.location.origin) return;
            safe = parsed.href;
        } catch (_) {
            return;
        }
        window.location.href = safe;
    };

    window.saveDraftAndLeave = function () {
        const url = pendingLeaveUrl;
        closeOverlay('regLeaveDraftModal');
        if (!submitAsDraft(url || '')) {
            pendingLeaveUrl = url;
        }
    };

    window.confirmSaveDraft = function () {
        window.openDraftConfirmModal();
    };

    function isInternalNavLink(anchor) {
        if (!anchor || anchor.tagName !== 'A') return false;
        if (anchor.target && anchor.target.toLowerCase() === '_blank') return false;
        if (anchor.hasAttribute('download')) return false;
        if (anchor.dataset.skipDraftGuard === '1') return false;

        const href = anchor.getAttribute('href');
        const hrefNorm = href ? href.trim().toLowerCase() : '';
        if (
            !href ||
            hrefNorm === '#' ||
            hrefNorm.startsWith('javascript:') ||
            hrefNorm.startsWith('data:') ||
            hrefNorm.startsWith('vbscript:') ||
            hrefNorm.startsWith('mailto:') ||
            hrefNorm.startsWith('tel:')
        ) {
            return false;
        }

        let url;
        try {
            url = new URL(href, window.location.origin);
        } catch (_) {
            return false;
        }
        if (url.protocol !== 'http:' && url.protocol !== 'https:') return false;
        if (url.origin !== window.location.origin) return false;
        if (url.pathname === window.location.pathname && url.search === window.location.search) return false;
        return true;
    }

    function openLeaveModal(url, options) {
        pendingLeaveUrl = url;
        closeOverlay('regDraftConfirmModal');
        const wantsDraft = !!(options && options.allowDraftSave);
        const canSave = call('__regDraftCanSave', false);
        const showSave = wantsDraft && canSave;
        const saveBtn = document.getElementById('btnLeaveSaveDraft');
        const hint = document.getElementById('regLeaveDraftHint');
        if (saveBtn) {
            saveBtn.style.display = showSave ? '' : 'none';
            saveBtn.disabled = !showSave;
            saveBtn.style.opacity = showSave ? '' : '0.45';
            saveBtn.style.pointerEvents = showSave ? '' : 'none';
        }
        if (hint) {
            if (showSave) {
                hint.textContent = 'Save it as a draft so you can continue later from Document Registration → Drafts.';
            } else if (wantsDraft) {
                hint.textContent = 'Not enough details yet to save a draft (need Version Type, Document Type, Document No, and at least one other masterlist field). Leaving now will discard what you entered.';
            } else {
                hint.textContent = 'Leaving now will discard unsaved changes on this page.';
            }
        }
        openOverlay('regLeaveDraftModal');
    }

    document.addEventListener('click', function (e) {
        if (formSubmitting || window.__regFormSubmitting) return;
        if (!call('__regDraftHasProgress', false)) return;

        const anchor = e.target.closest('a[href]');
        if (!isInternalNavLink(anchor)) return;

        e.preventDefault();
        e.stopPropagation();

        const url = new URL(anchor.getAttribute('href'), window.location.origin);
        const dest = url.pathname + url.search + url.hash;
        const autosave = call('__regDraftShouldAutosaveOnLeave', false);

        if (autosave && call('__regDraftCanSave', false)) {
            submitAsDraft(dest);
            return;
        }

        openLeaveModal(dest, { allowDraftSave: autosave });
    }, true);

    window.addEventListener('beforeunload', function (e) {
        if (formSubmitting || window.__regFormSubmitting) return;
        if (!call('__regDraftHasProgress', false)) return;
        // Tab/window close cannot reliably POST multipart forms — warn instead.
        e.preventDefault();
        e.returnValue = '';
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (document.getElementById('regDraftConfirmModal')?.classList.contains('is-open')) {
            window.closeDraftConfirmModal();
        }
        if (document.getElementById('regLeaveDraftModal')?.classList.contains('is-open')) {
            window.closeLeaveDraftModal();
        }
        if (document.getElementById('regDraftNoticeModal')?.classList.contains('is-open')) {
            window.closeDraftNoticeModal();
        }
    });
})();
