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

window.dcsShowToast = function (message, type) {
    if (!message) {
        return;
    }
    window.closeToast();
    const isError = type === 'error';
    const toast = document.createElement('div');
    toast.className = 'dcs-toast ' + (isError ? 'dcs-toast-error' : 'dcs-toast-success');
    toast.id = isError ? 'errorToast' : 'successToast';
    toast.dataset.dcsToast = '';
    toast.setAttribute('role', 'status');
    toast.innerHTML =
        '<div class="dcs-toast-icon"><i class="fa-solid ' + (isError ? 'fa-circle-exclamation' : 'fa-circle-check') + '"></i></div>' +
        '<div class="dcs-toast-content">' +
            '<span class="dcs-toast-title">' + (isError ? 'Error' : 'Success') + '</span>' +
            '<span class="dcs-toast-message">' + escapeToastHtml(message) + '</span>' +
        '</div>' +
        '<button type="button" class="dcs-toast-close" onclick="closeToast()"><i class="fa-solid fa-xmark"></i></button>' +
        '<div class="dcs-toast-progress"></div>';
    document.body.appendChild(toast);
    bindToast(toast);
};

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
    Livewire.hook('commit', ({ succeed }) => {
        succeed(() => queueMicrotask(scanToasts));
    });
});
</script>
@endonce
