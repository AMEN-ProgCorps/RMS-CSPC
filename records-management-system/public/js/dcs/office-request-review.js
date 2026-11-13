(function () {
    'use strict';

    const roots = document.querySelectorAll('[data-ofi-actions]');
    if (!roots.length) return;

    // One Proceed at a time across linked DRF/DCN panels (avoids stuck "Opening…").
    if (typeof window.__ofiProceedLock === 'undefined') {
        window.__ofiProceedLock = false;
    }

    roots.forEach(bindActions);

    function bindActions(root) {
    if (root.getAttribute('data-ofi-bound') === '1') return;
    root.setAttribute('data-ofi-bound', '1');

    const type = root.getAttribute('data-type');
    const id = root.getAttribute('data-id');
    const proceedType = root.getAttribute('data-proceed-type') || type;
    const proceedId = root.getAttribute('data-proceed-id') || id;
    const check = root.querySelector('[data-ofi-received]');
    const receiveMeta = root.querySelector('[data-ofi-receive-meta]');
    const unlockBlock = root.querySelector('[data-ofi-unlock-block]');
    const unlockReason = root.querySelector('[data-ofi-unlock-reason]');
    const unlockBtn = root.querySelector('[data-ofi-unlock-btn]');
    const unlockBtnLabel = root.querySelector('[data-ofi-unlock-btn-label]');
    const unlockSent = root.querySelector('[data-ofi-unlock-sent]');
    const unlockSentDetail = root.querySelector('[data-ofi-unlock-sent-detail]');
    const unlockError = root.querySelector('[data-ofi-unlock-error]');
    const unlockMeta = root.querySelector('[data-ofi-unlock-meta]');
    const notifyBtn = root.querySelector('[data-ofi-notify-btn]');
    const notifyLabel = root.querySelector('[data-ofi-notify-label]');
    const notifyMeta = root.querySelector('[data-ofi-notify-meta]');
    const notifyWait = root.querySelector('[data-ofi-notify-wait]');
    const proceed = root.querySelector('[data-ofi-proceed]');
    const actionError = root.querySelector('[data-ofi-action-error]');

    let busy = false;
    let proceeding = false;
    let registerUrl = root.getAttribute('data-register-url') || '';
    let canRegister = root.getAttribute('data-can-register') === '1';
    let printNotified = root.getAttribute('data-print-notified') === '1';
    let editUnlocked = root.getAttribute('data-edit-unlocked') === '1';

    function csrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    }

    function isReceived() {
        if (root.getAttribute('data-received') === '1') return true;
        return !!(check && check.checked);
    }

    function resetProceedButton() {
        proceeding = false;
        window.__ofiProceedLock = false;
        if (!proceed) return;
        proceed.innerHTML = 'Proceed to Registration <i class="fa-solid fa-arrow-right"></i>';
        syncProceed(isReceived());
    }

    /** Same-origin http(s) only — blocks javascript:/data:/vbscript: open redirects. */
    function safeSameOriginUrl(raw) {
        const value = String(raw || '').trim();
        if (!value) return null;
        let parsed;
        try {
            parsed = new URL(value, window.location.origin);
        } catch (_) {
            return null;
        }
        if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') return null;
        if (parsed.origin !== window.location.origin) return null;
        return parsed.href;
    }

    function navigateSameOrigin(raw) {
        const safe = safeSameOriginUrl(raw);
        if (!safe) return false;
        window.location.href = safe;
        return true;
    }

    const proceedHint = root.querySelector('[data-ofi-proceed-hint]');
    const pairLabel = root.getAttribute('data-pair-label') || 'linked form';

    function pairReceived() {
        return root.getAttribute('data-pair-received') !== '0';
    }

    function pairNotified() {
        return root.getAttribute('data-pair-notified') !== '0';
    }

    function clearUnlockError() {
        if (!unlockError) return;
        unlockError.hidden = true;
        unlockError.textContent = '';
    }

    function showUnlockError(message) {
        const text = String(message || '').trim();
        if (!text) return;
        if (unlockError) {
            unlockError.textContent = text;
            unlockError.hidden = false;
        }
        if (actionError) {
            actionError.hidden = true;
            actionError.textContent = '';
        }
        if (typeof window.dcsShowToast === 'function') {
            window.dcsShowToast(text, 'error');
        }
    }

    function showPanelError(message) {
        const text = String(message || '').trim();
        if (!text) return;
        clearUnlockError();
        if (actionError) {
            actionError.textContent = text;
            actionError.hidden = false;
        }
        if (proceedHint) {
            proceedHint.hidden = false;
            proceedHint.textContent = text;
        }
        if (typeof window.dcsShowToast === 'function') {
            window.dcsShowToast(text, 'error');
        }
    }

    function proceedHintText(received) {
        if (proceeding) {
            return 'Opening registration…';
        }
        if (editUnlocked) {
            return 'Wait for the submitting office to edit and resubmit this ' + String(type).toUpperCase() + ' before registration.';
        }
        if (!printNotified) {
            return 'Notify the submitting office that this ' + String(type).toUpperCase() + ' is correct before registration.';
        }
        if (!pairNotified()) {
            return 'Notify the office for the linked ' + pairLabel + ' as well — one notice is sent when both are confirmed.';
        }
        if (!received) {
            return 'Confirm receipt of the signed ' + String(type).toUpperCase() + ' first to unlock registration.';
        }
        if (!pairReceived()) {
            return 'Switch to the ' + pairLabel + ' preview and confirm receipt of that signed form before registration.';
        }
        if (!canRegister) {
            return 'Your account cannot open Document Registration for this intake.';
        }
        if (!registerUrl) {
            return 'Registration link is missing. Refresh this page and try again.';
        }
        return 'Complete the steps above to unlock registration.';
    }

    function syncNotifyWhileUnlocked() {
        if (notifyBtn) {
            notifyBtn.disabled = !!editUnlocked;
            notifyBtn.title = editUnlocked
                ? 'Wait for the office to edit and resubmit before notifying them to print and sign.'
                : '';
        }
        if (notifyWait) notifyWait.hidden = !editUnlocked;
        if (notifyMeta && editUnlocked) notifyMeta.hidden = true;
        if (check) check.disabled = !!editUnlocked;
    }

    function syncProceed(received) {
        if (!proceed) return;
        if (proceeding) {
            proceed.disabled = true;
            return;
        }
        const ok = !!received && printNotified && !editUnlocked && pairReceived() && pairNotified() && canRegister && !!registerUrl;
        proceed.disabled = !ok;
        if (!proceedHint) return;
        if (ok) {
            proceedHint.hidden = true;
            return;
        }
        proceedHint.hidden = false;
        proceedHint.textContent = proceedHintText(received);
    }

    function syncUnlock(received) {
        if (!unlockBlock) return;
        unlockBlock.hidden = !!received;
        renumberSteps();
    }

    function renumberSteps() {
        let n = 0;
        root.querySelectorAll('.ofi-request-step').forEach(function (step) {
            if (step.hasAttribute('hidden') || step.hidden) return;
            n += 1;
            const badge = step.querySelector('.ofi-request-step-num');
            if (badge) badge.textContent = String(n);
        });
    }

    function markPanelPrintNotified(panel, metaText) {
        if (!panel) return;
        panel.setAttribute('data-print-notified', '1');
        const label = panel.querySelector('[data-ofi-notify-label]');
        if (label) label.textContent = 'Resend print & sign notice';
        const meta = panel.querySelector('[data-ofi-notify-meta]');
        if (meta) {
            meta.textContent = metaText || 'Client notified';
            meta.hidden = false;
        }
        const wait = panel.querySelector('[data-ofi-notify-wait]');
        if (wait) wait.hidden = true;
        const unlockReasonEl = panel.querySelector('[data-ofi-unlock-reason]');
        if (unlockReasonEl) unlockReasonEl.disabled = true;
        const unlockBtnEl = panel.querySelector('[data-ofi-unlock-btn]');
        if (unlockBtnEl) {
            unlockBtnEl.disabled = true;
            unlockBtnEl.title = 'Not available after notifying the office that the form is correct.';
            const icon = unlockBtnEl.querySelector('i');
            if (icon) {
                icon.classList.remove('fa-unlock');
                icon.classList.add('fa-lock');
            }
        }
        const unlockMetaEl = panel.querySelector('[data-ofi-unlock-meta]');
        if (unlockMetaEl) {
            unlockMetaEl.textContent = 'Locked — office was already notified that this submission is correct.';
            unlockMetaEl.hidden = false;
        }
    }

    function lockUnlockAfterPrintNotify() {
        printNotified = true;
        root.setAttribute('data-print-notified', '1');
        if (unlockReason) unlockReason.disabled = true;
        if (unlockBtn) {
            unlockBtn.disabled = true;
            unlockBtn.title = 'Not available after notifying the office that the form is correct.';
            const icon = unlockBtn.querySelector('i');
            if (icon) {
                icon.classList.remove('fa-unlock');
                icon.classList.add('fa-lock');
            }
        }
        if (unlockMeta) {
            unlockMeta.textContent = 'Locked — office was already notified that this submission is correct.';
            unlockMeta.hidden = false;
        }
    }

    async function markReceived() {
        if (busy) return;
        busy = true;
        try {
            const response = await fetch(
                '/dcs/api/office-intake/' + encodeURIComponent(type) + '/' + encodeURIComponent(id) + '/received',
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({}),
                }
            );
            if (!response.ok) throw new Error('Could not mark as received.');
            const data = await response.json();
            if (data.registerUrl) registerUrl = data.registerUrl;
            if (typeof data.canRegister === 'boolean') canRegister = data.canRegister;
            root.setAttribute('data-received', '1');
            if (check) check.checked = true;
            if (receiveMeta) {
                let text = 'Received';
                if (data.receivedAt) text += ' on ' + data.receivedAt;
                if (data.receivedBy) text += ' by ' + data.receivedBy;
                receiveMeta.textContent = text;
                receiveMeta.hidden = false;
            }
            syncUnlock(true);
            syncProceed(true);
            syncPairPanels(type, true);
        } catch (err) {
            if (check) check.checked = false;
            showPanelError(err.message || 'Could not mark as received.');
        } finally {
            busy = false;
        }
    }

    async function clearReceived() {
        if (busy) return;
        busy = true;
        try {
            const response = await fetch(
                '/dcs/api/office-intake/' + encodeURIComponent(type) + '/' + encodeURIComponent(id) + '/received',
                {
                    method: 'DELETE',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                }
            );
            if (!response.ok) throw new Error('Could not clear received status.');
            root.setAttribute('data-received', '0');
            if (check) check.checked = false;
            if (receiveMeta) receiveMeta.hidden = true;
            syncUnlock(false);
            syncProceed(false);
            syncPairPanels(type, false);
        } catch (err) {
            if (check) check.checked = true;
            showPanelError(err.message || 'Could not clear received status.');
        } finally {
            busy = false;
        }
    }

    async function unlockEdit() {
        if (busy) return;
        if (printNotified) {
            showUnlockError('Enable edit is not available after notifying the office that this submission is correct.');
            return;
        }
        const reason = (unlockReason?.value || '').trim();
        if (reason.length < 5) {
            showUnlockError('Please provide a short reason (at least 5 characters).');
            unlockReason?.focus();
            return;
        }
        clearUnlockError();
        busy = true;
        if (unlockBtn) unlockBtn.disabled = true;
        try {
            const response = await fetch(
                '/dcs/api/office-intake/' + encodeURIComponent(type) + '/' + encodeURIComponent(id) + '/unlock-edit',
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ reason }),
                }
            );
            if (!response.ok) {
                let message = 'Could not enable edit.';
                try {
                    const err = await response.json();
                    message = err.message || err.error || message;
                } catch (_) { /* ignore */ }
                throw new Error(message);
            }
            clearUnlockError();
            editUnlocked = true;
            root.setAttribute('data-edit-unlocked', '1');
            if (unlockReason) unlockReason.disabled = true;
            if (unlockBtn) {
                unlockBtn.disabled = true;
                unlockBtn.classList.add('is-sent');
                const icon = unlockBtn.querySelector('i');
                if (icon) icon.className = 'fa-solid fa-lock';
            }
            if (unlockBtnLabel) unlockBtnLabel.textContent = 'Edit enabled for office';
            if (unlockSent) {
                if (unlockSentDetail) {
                    unlockSentDetail.textContent = 'They can edit and resubmit this form.';
                }
                unlockSent.hidden = false;
            }
            if (unlockMeta) unlockMeta.hidden = true;
            syncNotifyWhileUnlocked();
            syncProceed(isReceived());
        } catch (err) {
            showUnlockError(err.message || 'Could not enable edit.');
            if (unlockBtn && !printNotified && !editUnlocked) unlockBtn.disabled = false;
        } finally {
            busy = false;
        }
    }

    function confirmResendNotify() {
        return new Promise(function (resolve) {
            const overlay = document.getElementById('ofiConfirmDialog');
            if (!overlay) {
                resolve(window.confirm('Resend the print & sign notification to the submitting office?'));
                return;
            }

            const message = overlay.querySelector('#ofiConfirmDialogMessage');
            const okBtn = overlay.querySelector('[data-ofi-confirm-ok]');
            const cancelBtn = overlay.querySelector('[data-ofi-confirm-cancel]');
            if (message) {
                message.textContent = 'Resend the print & sign notification to the submitting office?';
            }

            function close(result) {
                overlay.classList.remove('is-open');
                overlay.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('ofi-modal-open');
                document.removeEventListener('keydown', onKey);
                overlay.removeEventListener('click', onBackdrop);
                if (okBtn) okBtn.removeEventListener('click', onOk);
                if (cancelBtn) cancelBtn.removeEventListener('click', onCancel);
                resolve(result);
            }

            function onOk() { close(true); }
            function onCancel() { close(false); }
            function onBackdrop(e) {
                if (e.target === overlay) close(false);
            }
            function onKey(e) {
                if (e.key === 'Escape') close(false);
                if (e.key === 'Enter') close(true);
            }

            if (overlay.parentElement !== document.body) {
                document.body.appendChild(overlay);
            }

            overlay.addEventListener('click', onBackdrop);
            document.addEventListener('keydown', onKey);
            if (okBtn) okBtn.addEventListener('click', onOk);
            if (cancelBtn) cancelBtn.addEventListener('click', onCancel);

            overlay.classList.add('is-open');
            overlay.setAttribute('aria-hidden', 'false');
            document.body.classList.add('ofi-modal-open');
            if (okBtn) okBtn.focus();
        });
    }

    async function notifyPrintReady() {
        if (busy) return;
        if (editUnlocked) {
            showPanelError('Wait for the submitting office to edit and resubmit this form before notifying them to print and sign.');
            return;
        }
        if (printNotified) {
            const ok = await confirmResendNotify();
            if (!ok) return;
        }
        busy = true;
        if (notifyBtn) notifyBtn.disabled = true;
        try {
            const response = await fetch(
                '/dcs/api/office-intake/' + encodeURIComponent(type) + '/' + encodeURIComponent(id) + '/notify-print-ready',
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({}),
                }
            );
            if (!response.ok) {
                let message = 'Could not notify the office.';
                try {
                    const err = await response.json();
                    message = err.message || err.error || message;
                } catch (_) { /* ignore */ }
                throw new Error(message);
            }
            const data = await response.json();
            lockUnlockAfterPrintNotify();
            if (notifyLabel) notifyLabel.textContent = 'Resend print & sign notice';
            if (notifyMeta) {
                let text = data.noticeHeld
                    ? ('Print unlocked. The office is notified after the linked ' + (data.pairLabel || pairLabel) + ' is also confirmed.')
                    : (data.peerAlsoNotified
                        ? ('Client notified for this form and the linked ' + (data.pairLabel || pairLabel))
                        : 'Client notified');
                if (!data.noticeHeld && data.printNotifiedAt) text += ' on ' + data.printNotifiedAt;
                if (!data.noticeHeld && data.printNotifiedBy) text += ' by ' + data.printNotifiedBy;
                notifyMeta.textContent = text;
                notifyMeta.hidden = false;
            }
            document.querySelectorAll('[data-ofi-actions]').forEach(function (other) {
                if (other === root) {
                    if (data.pairComplete) other.setAttribute('data-pair-notified', '1');
                    return;
                }
                // Peer panel: this form was just confirmed, so pair is notified from their side.
                other.setAttribute('data-pair-notified', '1');
                if (data.peerAlsoNotified || data.pairComplete) {
                    markPanelPrintNotified(
                        other,
                        'Client notified with the linked ' + String(type).toUpperCase() + ' notice.'
                    );
                    other.setAttribute('data-pair-notified', '1');
                }
                other.dispatchEvent(new CustomEvent('ofi-pair-notified'));
            });
            const receivedNow = check ? check.checked : root.getAttribute('data-received') === '1';
            syncProceed(receivedNow);
            renumberSteps();
        } catch (err) {
            showPanelError(err.message || 'Could not notify the office.');
        } finally {
            busy = false;
            if (notifyBtn) notifyBtn.disabled = !!editUnlocked;
        }
    }

    async function ensurePanelReceived() {
        if (isReceived()) return true;
        if (!(check && check.checked)) return false;
        // Checkbox looks checked but data-received is stale — persist before Proceed.
        try {
            const response = await fetch(
                '/dcs/api/office-intake/' + encodeURIComponent(type) + '/' + encodeURIComponent(id) + '/received',
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({}),
                }
            );
            const data = await response.json().catch(function () { return {}; });
            if (!response.ok) {
                throw new Error(data.message || 'Could not mark as received.');
            }
            if (data.registerUrl) registerUrl = data.registerUrl;
            if (typeof data.canRegister === 'boolean') canRegister = data.canRegister;
            root.setAttribute('data-received', '1');
            if (check) check.checked = true;
            syncUnlock(true);
            syncProceed(true);
            syncPairPanels(type, true);
            return true;
        } catch (_) {
            return false;
        }
    }

    async function proceedToRegistration() {
        if (proceeding || busy || window.__ofiProceedLock) return;
        if (!registerUrl || proceed?.disabled) {
            showPanelError(proceedHintText(isReceived()));
            return;
        }
        proceeding = true;
        window.__ofiProceedLock = true;
        busy = true;
        proceed.disabled = true;
        proceed.innerHTML = 'Opening… <i class="fa-solid fa-spinner fa-spin"></i>';
        if (actionError) {
            actionError.hidden = true;
            actionError.textContent = '';
        }

        let landedType = proceedType;
        let landedId = proceedId;
        try {
            // If the checkbox is checked but data-received is stale, persist before begin-register.
            if (check && check.checked && root.getAttribute('data-received') !== '1') {
                const panelOk = await ensurePanelReceived();
                if (!panelOk) {
                    showPanelError(proceedHintText(false));
                    busy = false;
                    resetProceedButton();
                    return;
                }
            }
            // Re-POST receive before begin-register when UI says received
            // (covers resubmit races where the checkbox/meta still look confirmed).
            if (!isReceived()) {
                showPanelError(proceedHintText(false));
                busy = false;
                resetProceedButton();
                return;
            }
            async function pingReceived(pingType, pingId) {
                try {
                    await fetch(
                        '/dcs/api/office-intake/' + encodeURIComponent(pingType) + '/' + encodeURIComponent(pingId) + '/received',
                        {
                            method: 'POST',
                            headers: {
                                Accept: 'application/json',
                                'Content-Type': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': csrfToken(),
                            },
                            credentials: 'same-origin',
                            body: JSON.stringify({}),
                        }
                    );
                } catch (_) { /* begin-register reports if still missing */ }
            }
            await pingReceived(type, id);
            // If Proceed targets the linked form, refresh that receipt too when the UI already treats the pair as received.
            if (pairReceived() && (String(proceedType) !== String(type) || String(proceedId) !== String(id))) {
                await pingReceived(proceedType, proceedId);
            }

            const response = await fetch(
                '/dcs/api/office-intake/' + encodeURIComponent(proceedType) + '/' + encodeURIComponent(proceedId) + '/begin-register',
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({}),
                }
            );
            const data = await response.json().catch(function () { return {}; });
            if (!response.ok) {
                const message = data.message || data.error
                    || (Array.isArray(data.errors) ? data.errors[0] : null)
                    || (data.errors && typeof data.errors === 'object'
                        ? (Object.values(data.errors).flat()[0] || null)
                        : null)
                    || 'Could not open registration. Refresh this page and try again.';
                busy = false;
                showPanelError(String(message));
                resetProceedButton();
                return;
            }
            if (data.registerUrl) registerUrl = data.registerUrl;
            if (data.type) landedType = data.type;
            if (data.id) landedId = data.id;
            if (data.registerType === 'recurring' || (registerUrl && /[?&]type=recurring\b/.test(registerUrl))) {
                root.setAttribute('data-register-type', 'recurring');
            }
        } catch (_) {
            busy = false;
            showPanelError('Could not open registration. Check your connection and try again.');
            resetProceedButton();
            return;
        }

        try {
            sessionStorage.setItem(
                'dcs_office_intake_pending',
                JSON.stringify({ type: landedType, id: Number(landedId) })
            );
        } catch (_) { /* ignore */ }

        if (!navigateSameOrigin(registerUrl)) {
            busy = false;
            showPanelError('Registration link is invalid. Refresh this page and try again.');
            resetProceedButton();
            return;
        }
        // Navigation started — keep Opening state; unlock if the page stays put.
        setTimeout(function () {
            if (document.visibilityState === 'visible') {
                busy = false;
                resetProceedButton();
            }
        }, 4000);
    }

    check?.addEventListener('change', function () {
        if (this.checked) markReceived();
        else clearReceived();
    });
    unlockReason?.addEventListener('input', function () {
        const len = String(unlockReason.value || '').trim().length;
        if (len >= 5) clearUnlockError();
    });
    unlockBtn?.addEventListener('click', unlockEdit);
    notifyBtn?.addEventListener('click', notifyPrintReady);
    root.addEventListener('ofi-pair-notified', function () {
        syncProceed(isReceived());
    });
    root.querySelector('[data-ofi-proceed-wrap]')?.addEventListener('click', function () {
        if (proceeding || window.__ofiProceedLock) return;
        if (proceed && proceed.disabled) {
            showPanelError(proceedHintText(isReceived()));
        }
    });
    proceed?.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        proceedToRegistration();
    });

    // Always reset stuck Opening… from bfcache / interrupted navigations.
    function hardenProceedUi() {
        if (check) check.checked = root.getAttribute('data-received') === '1';
        if (!window.__ofiProceedLock) {
            proceeding = false;
            if (proceed && /Opening/i.test(proceed.textContent || '')) {
                resetProceedButton();
            } else {
                syncProceed(isReceived());
            }
        }
        syncUnlock(isReceived());
        syncNotifyWhileUnlocked();
    }
    hardenProceedUi();
    renumberSteps();
    window.addEventListener('pageshow', hardenProceedUi);
    root.addEventListener('ofi-pair-sync', function () {
        syncProceed(isReceived());
    });
    }

    function syncPairPanels(sourceType, received) {
        document.querySelectorAll('[data-ofi-actions]').forEach(function (other) {
            if ((other.getAttribute('data-pair-label') || '').toUpperCase() !== String(sourceType).toUpperCase()) return;
            other.setAttribute('data-pair-received', received ? '1' : '0');
            other.dispatchEvent(new CustomEvent('ofi-pair-sync'));
        });
    }
})();
