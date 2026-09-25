/**
 * Live uniqueness check for Register DRF No.
 */
(function () {
    'use strict';

    let timer = null;
    let lastChecked = '';

    function inputEl() {
        return document.getElementById('drfNo');
    }

    function hintEl() {
        return document.getElementById('drfNoHint');
    }

    function excludeRequestId() {
        const hidden = document.getElementById('requestId');
        const fromHidden = parseInt(hidden && hidden.value ? hidden.value : '0', 10) || 0;
        const fromDraft = parseInt(window.__draftRequestId || '0', 10) || 0;

        return fromHidden || fromDraft;
    }

    function setHint(text, valid) {
        const el = hintEl();
        if (!el) return;
        if (!text) {
            el.textContent = '';
            el.style.display = 'none';
            el.dataset.valid = '';
            return;
        }
        el.style.display = 'block';
        el.style.marginTop = '4px';
        el.style.fontSize = '12px';
        el.style.color = valid ? '#15803d' : '#dc2626';
        el.dataset.valid = valid ? 'ok' : 'taken';
        el.innerHTML = valid
            ? ''
            : '<i class="fa-solid fa-circle-exclamation"></i> ' + text;
    }

    function setDuplicate(taken) {
        window.drfNoDuplicate = !!taken;
        if (typeof window.setSaveEnabled === 'function' && !taken) {
            window.setSaveEnabled(true);
        }
    }

    async function checkDrfNo() {
        const input = inputEl();
        if (!input) return;
        const value = String(input.value || '').trim();
        if (value === '') {
            lastChecked = '';
            setDuplicate(false);
            setHint('', true);
            return;
        }
        if (value === lastChecked) return;
        lastChecked = value;

        const url = '/dcs/register/check-drfno?drf_no=' + encodeURIComponent(value)
            + '&exclude_request_id=' + encodeURIComponent(String(excludeRequestId()));

        try {
            const response = await fetch(url, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            const data = await response.json();
            if (String(input.value || '').trim() !== value) {
                return;
            }
            if (data && data.exists) {
                setDuplicate(true);
                setHint(data.message || 'This DRF number is already used.', false);
                return;
            }
            setDuplicate(false);
            setHint('', true);
        } catch (_) {
            setDuplicate(false);
        }
    }

    function scheduleCheck() {
        if (timer) clearTimeout(timer);
        timer = setTimeout(function () {
            void checkDrfNo();
        }, 280);
    }

    function bind() {
        const input = inputEl();
        if (!input) return;
        window.drfNoDuplicate = false;
        input.addEventListener('input', scheduleCheck);
        input.addEventListener('blur', function () {
            void checkDrfNo();
        });
        if (String(input.value || '').trim() !== '') {
            void checkDrfNo();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }
})();
