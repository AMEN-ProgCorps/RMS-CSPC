/**
 * Draft confirmation modal + leave autosave + periodic autosave for DCS Register / Edit.
 * Periodic autosave protects against brownouts / sudden power loss once enough
 * masterlist fields are filled (same rules as manual Save Draft).
 *
 * Expects page hooks:
 *   window.__regDraftCanSave() -> boolean
 *   window.__regDraftHasProgress() -> boolean
 *   window.__regDraftShouldAutosaveOnLeave() -> boolean
 *   window.__regDraftPrepareSubmit() -> void
 *   window.__regDraftShowErrors(message) -> void (optional)
 */
(function () {
    'use strict';

    let pendingLeaveUrl = null;
    let formSubmitting = false;
    let autosaveInFlight = false;
    let lastAutosaveSnapshot = '';
    let autosaveTimer = null;
    const AUTOSAVE_INTERVAL_MS = 45000;

    function formEl() {
        return document.getElementById('masterForm');
    }

    function draftFlag() {
        return document.getElementById('saveAsDraft');
    }

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta?.content) return meta.content;
        const input = formEl()?.querySelector('input[name="_token"]');
        return input ? String(input.value || '') : '';
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

    function setAutosaveStatus(text, state) {
        const el = document.getElementById('regAutosaveStatus');
        if (!el) return;
        if (!text) {
            el.hidden = true;
            el.textContent = '';
            el.removeAttribute('data-state');
            return;
        }
        el.hidden = false;
        el.textContent = text;
        el.setAttribute('data-state', state || 'ok');
    }

    function submitAsDraft(leaveUrl) {
        const form = formEl();
        if (!form || formSubmitting) return false;

        if (!call('__regDraftCanSave', false)) {
            showDraftError('To save a draft, enter Document No plus at least one other masterlist field (for example Title, Effectivity Date, Pages, Keywords, Originator, or Source Unit).');
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
            showDraftError('To save a draft, enter Document No plus at least one other masterlist field (for example Title, Effectivity Date, Pages, Keywords, Originator, or Source Unit).');
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
        const lead = document.querySelector('#regLeaveDraftModal .reg-draft-lead');
        if (saveBtn) {
            saveBtn.style.display = showSave ? '' : 'none';
            saveBtn.disabled = !showSave;
            saveBtn.style.opacity = showSave ? '' : '0.45';
            saveBtn.style.pointerEvents = showSave ? '' : 'none';
        }
        if (lead) {
            if (showSave) {
                lead.textContent = 'Your draft details are ready — you can leave safely.';
            } else {
                lead.textContent = 'You entered data that isn’t saved yet.';
            }
        }
        if (hint) {
            if (showSave) {
                hint.innerHTML = 'Required draft fields are filled, so auto-draft is active. You can move to other pages anytime — your work is kept and you can continue from <strong>Document Registration → Drafts</strong>. You can also click <strong>Save Draft</strong> before leaving.';
            } else if (wantsDraft) {
                hint.innerHTML = 'A draft needs <strong>Document No</strong> plus at least one other masterlist field (Title, Effectivity Date, Pages, Keywords, Originator, or Source Unit). Once those are filled, auto-draft turns on and you can freely open other pages. Until then, leaving now will discard what you entered — or stay and click <strong>Save Draft</strong> when ready.';
            } else {
                hint.textContent = 'Leaving now will discard unsaved changes on this page.';
            }
        }
        openOverlay('regLeaveDraftModal');
    }

    function navigateAfterDraftSave(dest) {
        formSubmitting = true;
        window.__regFormSubmitting = true;
        let safe;
        try {
            const parsed = new URL(String(dest), window.location.origin);
            if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') return false;
            if (parsed.origin !== window.location.origin) return false;
            safe = parsed.pathname + parsed.search + parsed.hash;
        } catch (_) {
            return false;
        }
        window.location.href = safe;
        return true;
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
            // Requirements met → silent auto-draft, then navigate (no blocking "unsaved" wall).
            setAutosaveStatus('Saving draft before you leave…', 'pending');
            void silentAutosaveDraft({ force: true, keepalive: true }).then(function (ok) {
                if (ok && navigateAfterDraftSave(dest)) return;
                submitAsDraft(dest);
            });
            return;
        }

        openLeaveModal(dest, { allowDraftSave: autosave });
    }, true);

    window.addEventListener('beforeunload', function (e) {
        if (formSubmitting || window.__regFormSubmitting) return;
        if (!call('__regDraftHasProgress', false)) return;
        // Best-effort silent autosave (may not finish on hard power loss).
        try { void silentAutosaveDraft({ force: true, keepalive: true }); } catch (_) { /* ignore */ }
        // Requirements already met → auto-draft covers navigation; skip the browser block.
        if (call('__regDraftShouldAutosaveOnLeave', false) && call('__regDraftCanSave', false)) {
            return;
        }
        e.preventDefault();
        e.returnValue = '';
    });

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            void silentAutosaveDraft({ keepalive: true });
        }
    });

    window.addEventListener('pagehide', function () {
        void silentAutosaveDraft({ keepalive: true });
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

    // ── Periodic / silent autosave (brownout protection) ───────────────

    function shouldPeriodicAutosave() {
        if (window.__editReadOnly) return false;
        if (formSubmitting || window.__regFormSubmitting) return false;
        // Create page always; edit page only while the record is still a draft.
        if (typeof window.__regDraftShouldAutosaveOnLeave === 'function') {
            return !!window.__regDraftShouldAutosaveOnLeave();
        }
        return true;
    }

    function formFieldSnapshot(form) {
        const parts = [];
        Array.from(form.elements || []).forEach((el) => {
            if (!el || !el.name || el.disabled) return;
            if (el.type === 'file' || el.type === 'button' || el.type === 'submit') return;
            if (el.name === '_token' || el.name === 'save_as_draft' || el.name === 'autosave' || el.name === 'draft_leave_to') return;
            if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) return;
            parts.push(el.name + '=' + String(el.value || ''));
        });
        return parts.sort().join('&');
    }

    function buildAutosaveFormData(form) {
        const fd = new FormData();
        Array.from(form.elements || []).forEach((el) => {
            if (!el || !el.name || el.disabled) return;
            if (el.type === 'file') return; // scans re-uploaded only on manual save
            if (el.type === 'button' || el.type === 'submit') return;
            if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) return;
            fd.append(el.name, el.value);
        });
        fd.set('save_as_draft', '1');
        fd.set('autosave', '1');
        const leave = document.getElementById('draftLeaveTo');
        if (leave) fd.set('draft_leave_to', '');
        return fd;
    }

    function toAbsoluteUrl(url) {
        if (!url) return '';
        return url.indexOf('http') === 0 ? url : (window.location.origin + url);
    }

    function adoptCreateFormAsDraftEdit(requestId, editUrl, updateUrl) {
        const form = formEl();
        if (!form || !requestId || !editUrl) return;

        // PUT target is /register/{id}, not the GET edit page (/register/{id}/edit).
        const actionUrl = updateUrl
            || String(editUrl).replace(/\/edit\/?(\?.*)?$/, '$1')
            || editUrl;

        form.action = toAbsoluteUrl(actionUrl);

        let method = form.querySelector('input[name="_method"]');
        if (!method) {
            method = document.createElement('input');
            method.type = 'hidden';
            method.name = '_method';
            form.appendChild(method);
        }
        method.value = 'PUT';

        let idField = document.getElementById('requestId');
        if (!idField) {
            idField = document.createElement('input');
            idField.type = 'hidden';
            idField.id = 'requestId';
            form.appendChild(idField);
        }
        idField.value = String(requestId);

        window.__isDraftDoc = true;
        window.__draftRequestId = Number(requestId);

        try {
            history.replaceState(null, '', editUrl);
        } catch (_) { /* ignore */ }
    }

    async function silentAutosaveDraft(options) {
        options = options || {};
        if (!shouldPeriodicAutosave()) return false;
        if (autosaveInFlight || formSubmitting || window.__regFormSubmitting) return false;
        if (!call('__regDraftCanSave', false)) return false;

        const form = formEl();
        if (!form || !form.action) return false;

        const snapshot = formFieldSnapshot(form);
        if (!options.force && snapshot && snapshot === lastAutosaveSnapshot) return false;

        if (typeof window.__regDraftPrepareSubmit === 'function') {
            window.__regDraftPrepareSubmit();
        }
        if (typeof syncSyllabiContextHidden === 'function') {
            syncSyllabiContextHidden();
        }

        const flag = draftFlag();
        const previousFlag = flag ? flag.value : '0';
        if (flag) flag.value = '1';

        const fd = buildAutosaveFormData(form);
        autosaveInFlight = true;
        if (!options.keepalive) {
            setAutosaveStatus('Auto-saving draft…', 'pending');
        }

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-DCS-Autosave': '1',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                keepalive: !!options.keepalive,
            });

            let data = null;
            try {
                data = await response.json();
            } catch (_) {
                data = null;
            }

            if (!response.ok || !data || data.ok !== true) {
                if (!options.keepalive) {
                    setAutosaveStatus('Auto-save paused — keep working; try Save Draft if needed', 'warn');
                }
                return false;
            }

            lastAutosaveSnapshot = snapshot;
            if (data.request_id && data.edit_url) {
                const onCreate = !document.getElementById('requestId')?.value
                    && !window.__draftRequestId;
                if (onCreate) {
                    adoptCreateFormAsDraftEdit(data.request_id, data.edit_url, data.update_url);
                }
            }

            const when = data.saved_at || new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
            setAutosaveStatus('Draft auto-saved at ' + when + ' — resume from Drafts after interruption', 'ok');
            return true;
        } catch (_) {
            if (!options.keepalive) {
                setAutosaveStatus('Auto-save failed — connection issue', 'warn');
            }
            return false;
        } finally {
            autosaveInFlight = false;
            if (flag) flag.value = previousFlag;
        }
    }

    function startPeriodicAutosave() {
        if (autosaveTimer) clearInterval(autosaveTimer);
        autosaveTimer = setInterval(function () {
            void silentAutosaveDraft({});
        }, AUTOSAVE_INTERVAL_MS);
        // First attempt shortly after the form becomes fillable.
        setTimeout(function () {
            void silentAutosaveDraft({});
        }, 12000);
    }

    window.__regSilentAutosaveDraft = silentAutosaveDraft;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startPeriodicAutosave);
    } else {
        startPeriodicAutosave();
    }
})();
