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
    const unlockMeta = root.querySelector('[data-ofi-unlock-meta]');
    const notifyBtn = root.querySelector('[data-ofi-notify-btn]');
    const notifyLabel = root.querySelector('[data-ofi-notify-label]');
    const notifyMeta = root.querySelector('[data-ofi-notify-meta]');
    const proceed = root.querySelector('[data-ofi-proceed]');
    const actionError = root.querySelector('[data-ofi-action-error]');

    let busy = false;
    let proceeding = false;
    let registerUrl = root.getAttribute('data-register-url') || '';
    let canRegister = root.getAttribute('data-can-register') === '1';
    let printNotified = root.getAttribute('data-print-notified') === '1';

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

    function showPanelError(message) {
        const text = String(message || '').trim();
        if (!text) return;
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

    function syncProceed(received) {
        if (!proceed) return;
        if (proceeding) {
            proceed.disabled = true;
            return;
        }
        const ok = !!received && printNotified && pairReceived() && pairNotified() && canRegister && !!registerUrl;
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
            showPanelError('Enable edit is not available after notifying the office that this submission is correct.');
            return;
        }
        const reason = (unlockReason?.value || '').trim();
        if (reason.length < 3) {
            showPanelError('Please enter a reason (at least 3 characters) before enabling edit.');
            unlockReason?.focus();
            return;
        }
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
            if (unlockMeta) {
                unlockMeta.textContent = 'Office can now edit and resubmit this form.';
                unlockMeta.hidden = false;
            }
        } catch (err) {
            showPanelError(err.message || 'Could not enable edit.');
        } finally {
            busy = false;
            if (unlockBtn && !printNotified) unlockBtn.disabled = false;
        }
    }

    async function notifyPrintReady() {
        if (busy) return;
        if (printNotified) {
            const ok = window.confirm('Resend the print & sign notification to the submitting office?');
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
                    : 'Client notified';
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
                other.setAttribute('data-pair-notified', '1');
                other.dispatchEvent(new CustomEvent('ofi-pair-notified'));
            });
            const receivedNow = check ? check.checked : root.getAttribute('data-received') === '1';
            syncProceed(receivedNow);
        } catch (err) {
            showPanelError(err.message || 'Could not notify the office.');
        } finally {
            busy = false;
            if (notifyBtn) notifyBtn.disabled = false;
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
    }
    hardenProceedUi();
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
