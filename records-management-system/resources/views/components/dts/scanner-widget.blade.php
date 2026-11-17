@auth
@php
    $user = auth()->user();
    $perms = $user?->permissions;
    $canAccessDts = $perms ? ($perms->is_sadm || ($perms->can_access_dts ?? true)) : true;
@endphp

@if($canAccessDts)
@unless(request()->routeIs('login', 'track-document', 'tracked') || request()->is('/', 'track-document', 'tracked'))
<div
    x-data="{
        isOpen: false,
        /**
         * Toggle the floating scanner modal open or closed.
         * Enforces single-widget mutual exclusivity: dispatches 'rms-widget-opened'
         * and calls closeMessifyWidget() before opening the scanner modal.
         *
         * @returns {void}
         */
        openScanner() {
            if (this.isOpen) {
                if (window.Livewire) {
                    Livewire.dispatch('close-scanner-modal');
                } else {
                    window.dispatchEvent(new CustomEvent('close-scanner-modal'));
                }
            } else {
                window.dispatchEvent(new CustomEvent('rms-widget-opened', { detail: { widget: 'scanner' } }));
                if (typeof window.closeMessifyWidget === 'function') {
                    window.closeMessifyWidget();
                }
                if (window.Livewire) {
                    Livewire.dispatch('open-scanner-modal');
                } else {
                    window.dispatchEvent(new CustomEvent('open-scanner-modal'));
                }
            }
        },
        /**
         * Initializes widget DOM styling watchers and keyboard shortcuts.
         * Registers Alt+S and Ctrl+Shift+S hotkeys for opening the scanner modal.
         *
         * @returns {void}
         */
        init() {
            const syncMessifyPresent = () => {
                if (!document.getElementById('messify-global-widget')) {
                    document.body.classList.add('no-messify-widget');
                } else {
                    document.body.classList.remove('no-messify-widget');
                }
            };
            syncMessifyPresent();
            setTimeout(syncMessifyPresent, 400);

            // Global shortcut Alt+S or Ctrl+Shift+S to open scanner modal
            window.addEventListener('keydown', (e) => {
                if ((e.altKey && (e.key === 's' || e.key === 'S')) || 
                    (e.ctrlKey && e.shiftKey && (e.key === 's' || e.key === 'S'))) {
                    e.preventDefault();
                    this.openScanner();
                }
            });
        }
    }"
    x-on:scanner-modal-opened.window="isOpen = true"
    x-on:scanner-modal-closed.window="isOpen = false"
    x-on:rms-widget-opened.window="if ($event.detail && ($event.detail.widget || (Array.isArray($event.detail) ? $event.detail[0]?.widget : null)) !== 'scanner' && isOpen) { isOpen = false; if (window.Livewire) { Livewire.dispatch('close-scanner-modal'); } else { window.dispatchEvent(new CustomEvent('close-scanner-modal')); } }"
>
    <!-- Floating Scanner Trigger Button -->
    <button
        type="button"
        id="rms-scanner-fab-btn"
        class="rms-scanner-fab"
        :class="{ 'is-active': isOpen }"
        :data-tooltip="isOpen ? 'Close Scanner (Alt+S)' : 'DTS QR Scanner (Alt+S)'"
        @click="openScanner()"
        aria-label="Toggle Scanner"
        :title="isOpen ? 'Close DTS Scanner (Alt+S)' : 'DTS QR Scanner (Alt+S)'"
    >
        <span class="rms-scanner-fab-icon" aria-hidden="true">
            <!-- Viewfinder / QR Scanner SVG -->
            <svg x-show="!isOpen" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 7V5a2 2 0 0 1 2-2h2"></path>
                <path d="M17 3h2a2 2 0 0 1 2 2v2"></path>
                <path d="M21 17v2a2 2 0 0 1-2 2h-2"></path>
                <path d="M7 21H5a2 2 0 0 1-2-2v-2"></path>
                <line x1="7" y1="12" x2="17" y2="12"></line>
            </svg>
            <!-- Close Icon when open -->
            <svg x-show="isOpen" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display: none;">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </span>
    </button>
</div>

<style>
    .rms-scanner-fab {
        position: fixed;
        right: 92px; /* Placed immediately next to Chatify (24px + 56px + 12px gap) */
        bottom: 24px;
        z-index: 1000000;
        height: 56px;
        min-width: 56px;
        width: 56px;
        border-radius: 999px;
        border: 1px solid rgba(255, 255, 255, 0.2);
        background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
        color: #ffffff;
        box-shadow: 0 10px 28px rgba(2, 132, 199, 0.4), 0 0 0 1px rgba(255, 255, 255, 0.2);
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0;
        overflow: visible;
        white-space: nowrap;
        user-select: none;
        box-sizing: border-box;
        transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1),
                    box-shadow 0.2s ease,
                    background 0.2s ease;
    }

    .rms-scanner-fab.is-active {
        background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
        box-shadow: 0 10px 28px rgba(15, 23, 42, 0.5), 0 0 0 2px rgba(56, 189, 248, 0.7);
    }

    .rms-scanner-fab:hover {
        transform: translateY(-2px);
        background: linear-gradient(135deg, #0369a1 0%, #075985 100%);
        box-shadow: 0 14px 32px rgba(2, 132, 199, 0.55), 0 0 0 1px rgba(255, 255, 255, 0.3);
    }

    .rms-scanner-fab:active {
        transform: translateY(1px);
    }

    .rms-scanner-fab-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 24px;
        height: 24px;
        color: #ffffff;
    }

    /* Floating Tooltip Label on top */
    .rms-scanner-fab::before {
        content: attr(data-tooltip);
        position: absolute;
        bottom: calc(100% + 10px);
        left: 50%;
        transform: translateX(-50%) translateY(4px);
        background: #0f172a;
        color: #f8fafc;
        font-family: Inter, system-ui, sans-serif;
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0.02em;
        padding: 4px 10px;
        border-radius: 6px;
        box-shadow: 0 6px 18px rgba(15, 23, 42, 0.25);
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.15s ease, transform 0.15s ease;
        white-space: nowrap;
        border: 1px solid rgba(255, 255, 255, 0.1);
        z-index: 1000020;
    }

    .rms-scanner-fab:hover::before {
        opacity: 1;
        transform: translateX(-50%) translateY(0);
    }

    body.no-messify-widget .rms-scanner-fab {
        right: 24px;
    }

    @media (max-width: 767px) {
        .rms-scanner-fab {
            right: 80px;
            bottom: 24px;
        }
        body.no-messify-widget .rms-scanner-fab {
            right: 16px;
        }
    }

    @media print {
        .rms-scanner-fab {
            display: none !important;
        }
    }
</style>
@endunless
@endif
@endauth
