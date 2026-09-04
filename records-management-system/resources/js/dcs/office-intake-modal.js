(function () {
    let root = null;
    let listenersBound = false;

    function closeModal() {
        if (!root) {
            return;
        }
        root.classList.remove('is-open');
        root.hidden = true;
        document.body.classList.remove('ofi-modal-open');
        const body = root.querySelector('[data-ofi-modal-body]');
        if (body) {
            body.innerHTML = '';
        }
    }

    async function openModal(type, id) {
        if (!root || !type || !id) {
            return;
        }

        applyStoredTheme();

        const body = root.querySelector('[data-ofi-modal-body]');
        const titleEl = root.querySelector('[data-ofi-modal-title]');
        const subtitleEl = root.querySelector('[data-ofi-modal-subtitle]');

        if (!body) {
            return;
        }

        root.hidden = false;
        root.classList.add('is-open');
        document.body.classList.add('ofi-modal-open');
        body.innerHTML = '<div class="ofi-modal-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading submission…</div>';

        if (titleEl) {
            titleEl.textContent = type === 'dcn' ? 'Office DCN Submission' : 'Office DRF Submission';
        }
        if (subtitleEl) {
            subtitleEl.textContent = 'Review only — submitted by another office for RFIO processing.';
        }

        try {
            const response = await fetch('/dcs/api/office-intake/' + encodeURIComponent(type) + '/' + encodeURIComponent(id), {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error('Request failed');
            }

            const data = await response.json();
            if (titleEl && data.title) {
                titleEl.textContent = data.title;
            }
            if (subtitleEl) {
                subtitleEl.textContent = data.subtitle || '';
            }
            body.innerHTML = data.html || '<div class="ofi-alert err">No content available.</div>';
        } catch (error) {
            body.innerHTML = '<div class="ofi-alert err">Unable to load this submission. Please try again.</div>';
        }
    }

    function bindGlobalListeners() {
        if (listenersBound) {
            return;
        }
        listenersBound = true;

        window.addEventListener('open-office-intake-modal', (event) => {
            const detail = event.detail || {};
            openModal(detail.type, detail.id);
        });

        document.addEventListener('livewire:init', () => {
            if (!window.Livewire?.on) {
                return;
            }

            Livewire.on('open-office-intake-modal', (payload) => {
                const detail = Array.isArray(payload) ? (payload[0] || {}) : (payload || {});
                openModal(detail.type, detail.id);
            });
        });

        document.addEventListener('keydown', (event) => {
            if (!root?.classList.contains('is-open')) {
                return;
            }
            const closeKey = window.RMS_MODAL_CLOSE_KEY || 'Escape';
            if (event.key === closeKey) {
                closeModal();
            }
        });

        document.addEventListener('livewire:navigated', () => {
            applyStoredTheme();
            consumeIntakeQueryParams();
            attachModalElement();
        });
    }

    function applyStoredTheme() {
        if (document.querySelector('meta[name="rms-portal"]')) {
            return;
        }
        try {
            const theme = localStorage.getItem('rms-theme');
            if (theme) {
                document.documentElement.setAttribute('data-theme', theme);
            }
        } catch (error) {
            /* ignore storage errors */
        }
    }

    function consumeIntakeQueryParams() {
        const params = new URLSearchParams(window.location.search);
        const intakeType = params.get('intake');
        const intakeId = params.get('id');
        if (!intakeType || !intakeId || !/^(drf|dcn)$/.test(intakeType)) {
            return;
        }

        openModal(intakeType, intakeId);

        const url = new URL(window.location.href);
        url.searchParams.delete('intake');
        url.searchParams.delete('id');
        window.history.replaceState({}, '', url.pathname + url.search + url.hash);
    }

    function attachModalElement() {
        const nextRoot = document.getElementById('ofi-intake-modal');
        if (!nextRoot || nextRoot === root) {
            return;
        }

        root = nextRoot;

        root.addEventListener('click', (event) => {
            if (event.target === root) {
                closeModal();
            }
        });

        root.querySelector('[data-ofi-modal-close]')?.addEventListener('click', closeModal);
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
