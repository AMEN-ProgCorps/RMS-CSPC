@props([
    'message' => null,
    'type' => null,
])

@php
    $flashMessage = $message
        ?? session('success')
        ?? session('error')
        ?? ($errors->any() ? $errors->first() : null);
    $flashType = $type
        ?? ((session('error') || ($errors->any() && ! session('success'))) ? 'error' : 'success');
    $isError = $flashType === 'error';
@endphp

@if($flashMessage)
    <div
        class="dcs-toast {{ $isError ? 'dcs-toast-error' : 'dcs-toast-success' }}"
        id="{{ $isError ? 'errorToast' : 'successToast' }}"
        data-dcs-toast
        role="status"
    >
        <div class="dcs-toast-icon">
            <i class="fa-solid {{ $isError ? 'fa-circle-exclamation' : 'fa-circle-check' }}"></i>
        </div>
        <div class="dcs-toast-content">
            <span class="dcs-toast-title">{{ $isError ? 'Error' : 'Success' }}</span>
            <span class="dcs-toast-message">{{ $flashMessage }}</span>
        </div>
        <button type="button" class="dcs-toast-close" onclick="closeToast()">
            <i class="fa-solid fa-xmark"></i>
        </button>
        <div class="dcs-toast-progress"></div>
    </div>
@endif

@once
<script>
const TOAST_MS = 5000;

function escapeToastHtml(value) {
    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
}

function toastNodes() {
    return document.querySelectorAll('[data-dcs-toast], .dcs-toast, .reg-toast');
}

function dismissToast(el) {
    if (!el) {
        return;
    }
    if (el._dcsToastTimer) {
        clearTimeout(el._dcsToastTimer);
    }
    el.style.animation = 'dcsToastOut 0.3s ease forwards';
    setTimeout(() => el.remove(), 300);
}

function bindToast(el) {
    if (!el || el.dataset.dcsToastBound === '1') {
        return;
    }
    el.dataset.dcsToastBound = '1';
    el._dcsToastTimer = setTimeout(() => dismissToast(el), TOAST_MS);
}

function scanToasts() {
    toastNodes().forEach(bindToast);
}

window.closeToast = function () {
    toastNodes().forEach(dismissToast);
};

window.dcsShowToast = function (message, type, title) {
    if (!message) {
        return;
    }
    window.closeToast();
    const isError = type === 'error' || type === 'client' || type === 'server';
    const toastTitle = title || (type === 'client' ? 'Client error' : (type === 'server' ? 'Server error' : (isError ? 'Error' : 'Success')));
    const toast = document.createElement('div');
    toast.className = 'dcs-toast ' + (isError ? 'dcs-toast-error' : 'dcs-toast-success');
    toast.id = isError ? 'errorToast' : 'successToast';
    toast.dataset.dcsToast = '';
    toast.setAttribute('role', 'status');
    toast.innerHTML =
        '<div class="dcs-toast-icon"><i class="fa-solid ' + (isError ? 'fa-circle-exclamation' : 'fa-circle-check') + '"></i></div>' +
        '<div class="dcs-toast-content">' +
            '<span class="dcs-toast-title">' + escapeToastHtml(toastTitle) + '</span>' +
            '<span class="dcs-toast-message">' + escapeToastHtml(message) + '</span>' +
        '</div>' +
        '<button type="button" class="dcs-toast-close" onclick="closeToast()"><i class="fa-solid fa-xmark"></i></button>' +
        '<div class="dcs-toast-progress"></div>';
    document.body.appendChild(toast);
    bindToast(toast);
};

function diagnosedRequestFailure(status, content) {
    if (typeof window.rmsDiagnoseError === 'function') {
        return window.rmsDiagnoseError(status, content);
    }
    return {
        title: 'Server error',
        message: 'The server could not finish this request.',
        skipDefault: status !== 422,
    };
}

document.addEventListener('DOMContentLoaded', scanToasts);
document.addEventListener('livewire:navigated', scanToasts);
if (document.readyState !== 'loading') {
    scanToasts();
}

document.addEventListener('livewire:init', () => {
    Livewire.on('dcs-toast', (payload) => {
        const data = Array.isArray(payload) ? payload[0] : payload;
        window.dcsShowToast(data?.message, data?.type || 'success');
    });
    Livewire.on('office-incoming-count', (payload) => {
        const data = Array.isArray(payload) ? payload[0] : payload;
        const count = Math.max(0, parseInt(data?.count ?? data ?? 0, 10) || 0);
        const badge = document.getElementById('ofiIncomingBadge')
            || document.querySelector('[data-ofi-incoming-badge]');
        if (!badge) {
            return;
        }
        badge.textContent = String(count);
        if (count > 0) {
            badge.hidden = false;
            badge.style.display = '';
        } else {
            badge.hidden = true;
            badge.style.display = 'none';
        }
    });
    Livewire.hook('commit', ({ succeed }) => {
        succeed(() => queueMicrotask(scanToasts));
    });
    Livewire.hook('request', ({ fail }) => {
        fail(({ status, content, preventDefault }) => {
            const diagnosed = diagnosedRequestFailure(status, content);
            window.dcsShowToast(diagnosed.message, 'error', diagnosed.title);
            if (diagnosed.skipDefault) {
                preventDefault();
            }
        });
    });
});
</script>
@endonce
