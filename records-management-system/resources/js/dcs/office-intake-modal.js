(function () {
    let root = null;
    let listenersBound = false;
    let currentPayload = null;
    let markingReceived = false;
    let unlockingEdit = false;

    function csrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
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

    function footerEls() {
        if (!root) {
            return {};
        }
        return {
            footer: root.querySelector('[data-ofi-modal-footer]'),
            check: root.querySelector('[data-ofi-receive-check]'),
            receiveLabel: root.querySelector('[data-ofi-receive-label]'),
            meta: root.querySelector('[data-ofi-receive-meta]'),
            proceed: root.querySelector('[data-ofi-receive-proceed]'),
            registeredBanner: root.querySelector('[data-ofi-registered-banner]'),
            unlockBlock: root.querySelector('[data-ofi-unlock-block]'),
            unlockReason: root.querySelector('[data-ofi-unlock-reason]'),
            unlockBtn: root.querySelector('[data-ofi-unlock-btn]'),
            unlockMeta: root.querySelector('[data-ofi-unlock-meta]'),
        };
    }

    function resetFooter() {
        const els = footerEls();
        if (els.footer) els.footer.hidden = true;
        if (els.check) {
            els.check.checked = false;
            els.check.disabled = false;
        }
        if (els.receiveLabel) els.receiveLabel.hidden = false;
        if (els.meta) {
            els.meta.hidden = true;
            els.meta.textContent = '';
        }
        if (els.proceed) {
            els.proceed.disabled = true;
            els.proceed.hidden = false;
            els.proceed.removeAttribute('data-url');
        }
        const actions = root?.querySelector?.('.ofi-intake-modal-footer-actions');
        if (actions) actions.hidden = false;
        if (els.registeredBanner) els.registeredBanner.hidden = true;
        if (els.unlockBlock) els.unlockBlock.hidden = true;
        if (els.unlockReason) els.unlockReason.value = '';
        if (els.unlockMeta) {
            els.unlockMeta.hidden = true;
            els.unlockMeta.textContent = '';
        }
        currentPayload = null;
        markingReceived = false;
        unlockingEdit = false;
    }

    function setProceedLabel(proceed, label) {
        if (!proceed) return;
        proceed.textContent = '';
        proceed.appendChild(document.createTextNode(label + ' '));
        const icon = document.createElement('i');
        icon.className = 'fa-solid fa-arrow-right';
        proceed.appendChild(icon);
    }

    function renderFooter(data) {
        const els = footerEls();
        if (!els.footer || !els.check || !els.proceed) {
            return;
        }

        const canManage = !!(data?.canConfirmReceived || data?.canUnlockEdit || data?.registered);
        if (!canManage && !data?.canConfirmReceived) {
            // Still show footer for registered state to reviewers
            if (!data?.registered) {
                els.footer.hidden = true;
                return;
            }
        }

        els.footer.hidden = false;
        currentPayload = data;

        const registered = !!data.registered;
        if (els.registeredBanner) {
            els.registeredBanner.hidden = !registered;
        }

        if (els.receiveLabel) {
            els.receiveLabel.hidden = registered || !data.canConfirmReceived;
        }

        const canProceed = !registered
            && !!data.received
            && !!data.canRegister
            && !!data.registerUrl;

        if (els.proceed) {
            // Hide entirely once registered / when handoff is closed — do not leave a disabled CTA.
            els.proceed.hidden = registered || !data.canRegister || !data.registerUrl;
            els.proceed.disabled = !canProceed;
            if (canProceed) {
                els.proceed.setAttribute('data-url', data.registerUrl);
            } else {
                els.proceed.removeAttribute('data-url');
            }
            setProceedLabel(els.proceed, 'Proceed to Registration');
        }

        const received = !!data.received;
        els.check.checked = received || registered;
        // Stay toggleable until the intake is fully registered.
        els.check.disabled = registered;

        if (els.meta) {
            if ((received || registered) && (data.receivedAt || data.receivedBy)) {
                const parts = [];
                if (data.receivedBy) parts.push('Confirmed by ' + data.receivedBy);
                if (data.receivedAt) parts.push(data.receivedAt);
                els.meta.textContent = parts.join(' · ');
                els.meta.hidden = false;
            } else {
                els.meta.hidden = true;
                els.meta.textContent = '';
            }
        }

        if (els.unlockBlock) {
            // Hide return-for-correction while "already received" is checked.
            const showUnlock = !!data.canUnlockEdit && !registered && !received;
            els.unlockBlock.hidden = !showUnlock;
            if (els.unlockMeta) {
                if (data.editUnlocked && showUnlock) {
                    const bits = ['Edit already enabled for the office'];
                    if (data.editUnlockReason) bits.push(data.editUnlockReason);
                    els.unlockMeta.textContent = bits.join(' — ');
                    els.unlockMeta.hidden = false;
                } else if (!showUnlock) {
                    els.unlockMeta.hidden = true;
                } else {
                    els.unlockMeta.hidden = true;
                    els.unlockMeta.textContent = '';
                }
            }
            if (els.unlockBtn) {
                els.unlockBtn.disabled = !!data.editUnlocked;
            }
        }

        const actions = root.querySelector('.ofi-intake-modal-footer-actions');
        if (actions) {
            actions.hidden = !!(els.proceed?.hidden);
        }
    }

    function closeModal() {
        if (!root) return;
        root.classList.remove('is-open');
        root.hidden = true;
        document.body.classList.remove('ofi-modal-open');
        const body = root.querySelector('[data-ofi-modal-body]');
        if (body) body.innerHTML = '';
        resetFooter();
    }

    async function markReceived() {
        if (!currentPayload || markingReceived) return;
        const type = currentPayload.type;
        const id = currentPayload.id;
        if (!type || !id) return;

        const els = footerEls();
        markingReceived = true;
        if (els.check) els.check.disabled = true;
        if (els.proceed) els.proceed.disabled = true;

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
            if (!response.ok) throw new Error('Request failed');
            const data = await response.json();
            currentPayload = Object.assign({}, currentPayload, data, {
                canConfirmReceived: true,
                canUnlockEdit: currentPayload.canUnlockEdit !== false,
                received: true,
            });
            renderFooter(currentPayload);
        } catch (error) {
            if (els.check) {
                els.check.checked = false;
                els.check.disabled = false;
            }
            if (els.meta) {
                els.meta.textContent = 'Could not confirm receipt. Please try again.';
                els.meta.hidden = false;
            }
            if (els.proceed) els.proceed.disabled = true;
        } finally {
            markingReceived = false;
            if (els.check && !currentPayload?.registered) {
                els.check.disabled = false;
            }
        }
    }

    async function clearReceived() {
        if (!currentPayload || markingReceived) return;
        const type = currentPayload.type;
        const id = currentPayload.id;
        if (!type || !id) return;

        const els = footerEls();
        markingReceived = true;
        if (els.check) els.check.disabled = true;
        if (els.proceed) els.proceed.disabled = true;

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
            if (!response.ok) throw new Error('Request failed');
            const data = await response.json();
            currentPayload = Object.assign({}, currentPayload, data, {
                canConfirmReceived: true,
                canUnlockEdit: true,
                received: false,
                receivedAt: null,
                receivedBy: null,
            });
            renderFooter(currentPayload);
        } catch (error) {
            if (els.check) {
                els.check.checked = true;
                els.check.disabled = false;
            }
            if (els.meta) {
                els.meta.textContent = 'Could not undo receipt confirmation. Please try again.';
                els.meta.hidden = false;
            }
        } finally {
            markingReceived = false;
            if (els.check && !currentPayload?.registered) {
                els.check.disabled = false;
            }
        }
    }

    async function unlockForEdit() {
        if (!currentPayload || unlockingEdit) return;
        const type = currentPayload.type;
        const id = currentPayload.id;
        const els = footerEls();
        const reason = String(els.unlockReason?.value || '')
            .replace(/<[^>]*>/g, '')
            .replace(/\s+/g, ' ')
            .trim();
        if (els.unlockReason) {
            els.unlockReason.value = reason;
        }
        if (reason.length < 5) {
            if (els.unlockMeta) {
                els.unlockMeta.textContent = 'Enter a reason (at least 5 characters).';
                els.unlockMeta.hidden = false;
            }
            return;
        }

        unlockingEdit = true;
        if (els.unlockBtn) els.unlockBtn.disabled = true;

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
            const data = await response.json();
            currentPayload = Object.assign({}, currentPayload, data, { editUnlocked: true });
            renderFooter(currentPayload);
            if (els.unlockMeta) {
                els.unlockMeta.textContent = 'Office can now edit and resubmit this form.';
                els.unlockMeta.hidden = false;
            }
        } catch (error) {
            if (els.unlockMeta) {
                els.unlockMeta.textContent = error.message || 'Could not enable edit. Please try again.';
                els.unlockMeta.hidden = false;
            }
            if (els.unlockBtn) els.unlockBtn.disabled = false;
        } finally {
            unlockingEdit = false;
        }
    }

    async function proceedToRegistration() {
        const els = footerEls();
        const url = els.proceed?.getAttribute('data-url') || currentPayload?.registerUrl;
        if (!url || !currentPayload) return;

        const type = currentPayload.type;
        const id = currentPayload.id;
        els.proceed.disabled = true;
        setProceedLabel(els.proceed, 'Opening…');

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
        } catch (_) {
            /* still navigate — hidden fields + sessionStorage are backups */
        }

        try {
            sessionStorage.setItem(
                'dcs_office_intake_pending',
                JSON.stringify({ type, id: Number(id) })
            );
        } catch (_) { /* ignore */ }

        navigateSameOrigin(url);
    }

    async function openModal(type, id) {
        if (!root || !type || !id) return;

        applyStoredTheme();
        resetFooter();

        const body = root.querySelector('[data-ofi-modal-body]');
        const titleEl = root.querySelector('[data-ofi-modal-title]');
        const subtitleEl = root.querySelector('[data-ofi-modal-subtitle]');
        if (!body) return;

        root.hidden = false;
        root.classList.add('is-open');
        document.body.classList.add('ofi-modal-open');
        body.innerHTML = '<div class="ofi-modal-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading submission…</div>';

        if (titleEl) titleEl.textContent = type === 'dcn' ? 'Office DCN Submission' : 'Office DRF Submission';
        if (subtitleEl) subtitleEl.textContent = 'Review only — submitted by another office for RFIO processing.';

        try {
            const response = await fetch('/dcs/api/office-intake/' + encodeURIComponent(type) + '/' + encodeURIComponent(id), {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });
            if (!response.ok) throw new Error('Request failed');
            const data = await response.json();
            if (titleEl && data.title) titleEl.textContent = data.title;
            if (subtitleEl) subtitleEl.textContent = data.subtitle || '';
            body.innerHTML = data.html || '<div class="ofi-alert err">No content available.</div>';
            renderFooter(data);
        } catch (error) {
            body.innerHTML = '<div class="ofi-alert err">Unable to load this submission. Please try again.</div>';
            resetFooter();
        }
    }

    function bindFooterActions() {
        if (!root || root.dataset.ofiFooterBound === '1') return;
        root.dataset.ofiFooterBound = '1';

        root.querySelector('[data-ofi-receive-check]')?.addEventListener('change', (event) => {
            const input = event.target;
            if (currentPayload?.registered) {
                input.checked = true;
                return;
            }
            if (input?.checked) {
                markReceived();
                return;
            }
            clearReceived();
        });

        root.querySelector('[data-ofi-receive-proceed]')?.addEventListener('click', proceedToRegistration);
        root.querySelector('[data-ofi-unlock-btn]')?.addEventListener('click', unlockForEdit);
    }

    function bindGlobalListeners() {
        if (listenersBound) return;
        listenersBound = true;

        window.addEventListener('open-office-intake-modal', (event) => {
            const detail = event.detail || {};
            openModal(detail.type, detail.id);
        });

        document.addEventListener('livewire:init', () => {
            if (!window.Livewire?.on) return;
            Livewire.on('open-office-intake-modal', (payload) => {
                const detail = Array.isArray(payload) ? (payload[0] || {}) : (payload || {});
                openModal(detail.type, detail.id);
            });
        });

        document.addEventListener('keydown', (event) => {
            if (!root?.classList.contains('is-open')) return;
            const closeKey = window.RMS_MODAL_CLOSE_KEY || 'Escape';
            if (event.key === closeKey) closeModal();
        });

        document.addEventListener('livewire:navigated', () => {
            applyStoredTheme();
            consumeIntakeQueryParams();
            attachModalElement();
        });
    }

    function applyStoredTheme() {
        if (document.querySelector('meta[name="rms-portal"]')) return;
        try {
            const theme = localStorage.getItem('rms-theme');
            if (theme) document.documentElement.setAttribute('data-theme', theme);
        } catch (error) { /* ignore */ }
    }

    function consumeIntakeQueryParams() {
        const params = new URLSearchParams(window.location.search);
        const intakeType = params.get('intake');
        const intakeId = params.get('id');
        if (!intakeType || !intakeId || !/^(drf|dcn)$/.test(intakeType)) return;
        if (params.get('intake_id')) return;

        openModal(intakeType, intakeId);

        const url = new URL(window.location.href);
        url.searchParams.delete('intake');
        url.searchParams.delete('id');
        window.history.replaceState({}, '', url.pathname + url.search + url.hash);
    }

    function attachModalElement() {
        const nextRoot = document.getElementById('ofi-intake-modal');
        if (!nextRoot) return;

        if (nextRoot !== root) {
            root = nextRoot;
            root.addEventListener('click', (event) => {
                if (event.target === root) closeModal();
            });
            root.querySelector('[data-ofi-modal-close]')?.addEventListener('click', closeModal);
        }

        bindFooterActions();
    }

    function initOfficeIntakeModal() {
        bindGlobalListeners();
        applyStoredTheme();
        attachModalElement();
        consumeIntakeQueryParams();
        window.openOfficeIntakeModal = openModal;
        window.closeOfficeIntakeModal = closeModal;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initOfficeIntakeModal);
    } else {
        initOfficeIntakeModal();
    }
})();
