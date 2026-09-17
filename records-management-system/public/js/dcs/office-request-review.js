(function () {
    'use strict';

    const root = document.getElementById('ofiRequestActions');
    if (!root) return;

    const type = root.getAttribute('data-type');
    const id = root.getAttribute('data-id');
    const check = document.getElementById('ofiRequestReceived');
    const receiveMeta = document.getElementById('ofiRequestReceiveMeta');
    const unlockBlock = document.getElementById('ofiRequestUnlockBlock');
    const unlockReason = document.getElementById('ofiRequestUnlockReason');
    const unlockBtn = document.getElementById('ofiRequestUnlockBtn');
    const unlockMeta = document.getElementById('ofiRequestUnlockMeta');
    const proceed = document.getElementById('ofiRequestProceed');

    let busy = false;
    let registerUrl = root.getAttribute('data-register-url') || '';
    let canRegister = root.getAttribute('data-can-register') === '1';

    function csrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    }

    function syncProceed(received) {
        if (!proceed) return;
        const ok = !!received && canRegister && !!registerUrl;
        proceed.disabled = !ok;
    }

    function syncUnlock(received) {
        if (!unlockBlock) return;
        unlockBlock.hidden = !!received;
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
            if (receiveMeta) {
                let text = 'Received';
                if (data.receivedAt) text += ' on ' + data.receivedAt;
                if (data.receivedBy) text += ' by ' + data.receivedBy;
                receiveMeta.textContent = text;
                receiveMeta.hidden = false;
            }
            syncUnlock(true);
            syncProceed(true);
        } catch (err) {
            if (check) check.checked = false;
            alert(err.message || 'Could not mark as received.');
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
            if (receiveMeta) receiveMeta.hidden = true;
            syncUnlock(false);
            syncProceed(false);
        } catch (err) {
            if (check) check.checked = true;
            alert(err.message || 'Could not clear received status.');
        } finally {
            busy = false;
        }
    }

    async function unlockEdit() {
        if (busy) return;
        const reason = (unlockReason?.value || '').trim();
        if (reason.length < 3) {
            alert('Please enter a reason (at least 3 characters) before enabling edit.');
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
            alert(err.message || 'Could not enable edit.');
        } finally {
            busy = false;
            if (unlockBtn) unlockBtn.disabled = false;
        }
    }

    async function proceedToRegistration() {
        if (!registerUrl || proceed?.disabled) return;
        proceed.disabled = true;
        proceed.innerHTML = 'Opening… <i class="fa-solid fa-spinner fa-spin"></i>';
        try {
            await fetch(
                '/dcs/api/office-intake/' + encodeURIComponent(type) + '/' + encodeURIComponent(id) + '/begin-register',
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
        } catch (_) { /* still navigate */ }

        try {
            sessionStorage.setItem(
                'dcs_office_intake_pending',
                JSON.stringify({ type, id: Number(id) })
            );
        } catch (_) { /* ignore */ }

        window.location.href = registerUrl;
    }

    check?.addEventListener('change', function () {
        if (this.checked) markReceived();
        else clearReceived();
    });
    unlockBtn?.addEventListener('click', unlockEdit);
    proceed?.addEventListener('click', proceedToRegistration);

    syncProceed(root.getAttribute('data-received') === '1');
    syncUnlock(root.getAttribute('data-received') === '1');
})();
