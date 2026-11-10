<?php

use App\Services\ManualService;
use Livewire\Volt\Component;

new class extends Component {
    public bool $open = false;
    public ?array $manual = null;
    public string $pageRoute = '';
    public string $currentRoute = '';
    public string $search = '';

    public function mount(): void
    {
        $this->pageRoute = optional(request()->route())->getName() ?? '';
        $this->loadManual($this->pageRoute);
    }

    public function loadManual(?string $route = null): void
    {
        $routeName = $route ?: $this->pageRoute;
        if (empty($routeName) || $routeName === 'livewire.update') {
            $routeName = optional(request()->route())->getName() ?? '';
            if (empty($routeName) || $routeName === 'livewire.update') {
                $routeName = $this->pageRoute;
            }
        }

        $this->currentRoute = $routeName;
        if (!empty($this->currentRoute) && $this->currentRoute !== 'livewire.update') {
            $this->manual = ManualService::getManualForCurrentRoute($this->currentRoute);
        }
    }

    public function openManual(?string $name = null): void
    {
        if ($name) {
            $customManual = ManualService::getManualByName($name);
            if ($customManual) {
                $this->manual = $customManual;
                $this->open = true;
                return;
            }
        }

        if ($this->manual) {
            $this->open = true;
        }
    }

    public function toggleModal(): void
    {
        $this->open = ! $this->open;
    }

    public function closeModal(): void
    {
        $this->open = false;
        $this->loadManual($this->pageRoute);
    }

    public function openModal(): void
    {
        $this->open = true;
    }
};
?>

<div>
@if($manual)
<div
    x-data="{
        isOpen: @entangle('open'),
        searchQuery: '',
        activeStep: 0,
        init() {
            this.$watch('isOpen', val => {
                if (val) {
                    document.body.classList.add('manual-widget-open');
                    window.dispatchEvent(new CustomEvent('rms-widget-opened', { detail: { widget: 'manual' } }));
                } else {
                    document.body.classList.remove('manual-widget-open');
                    window.dispatchEvent(new CustomEvent('rms-widget-closed', { detail: { widget: 'manual' } }));
                }
            });

            var checkMessify = () => {
                if (!document.getElementById('messify-global-widget')) {
                    document.body.classList.add('no-messify-widget');
                } else {
                    document.body.classList.remove('no-messify-widget');
                }
            };
            checkMessify();
            setTimeout(checkMessify, 400);
        },
        matchesSearch(text, label) {
            if (!this.searchQuery || this.searchQuery.trim() === '') return true;
            var q = this.searchQuery.toLowerCase().trim();
            var hay = ((label || '') + ' ' + (text || '')).toLowerCase();
            return hay.indexOf(q) !== -1;
        }
    }"
    x-on:rms-open-manual.window="$wire.openManual($event.detail?.name || $event.detail)"
    x-on:rms-widget-opened.window="if ($event.detail && $event.detail.widget !== 'manual' && isOpen) { $wire.closeModal(); }"
>
    <!-- Floating Trigger Button -->
    <button
        type="button"
        class="rms-manual-fab"
        :class="{ 'is-open': isOpen }"
        :data-tooltip="isOpen ? 'Close Guide' : 'Page Guide'"
        @click="isOpen ? $wire.closeModal() : $wire.openModal()"
        aria-label="Toggle Page Guide"
    >
        <span class="rms-manual-fab-icon" aria-hidden="true">
            <!-- Question Mark Icon when closed -->
            <svg x-show="!isOpen" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"/>
                <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/>
                <line x1="12" y1="17" x2="12.01" y2="17"/>
            </svg>
            <!-- Close Icon when open -->
            <svg x-show="isOpen" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display: none;">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </span>
    </button>

    <!-- Floating Manual Widget Card (Docked in bottom-right) -->
    <div
        x-show="isOpen"
        x-cloak
        class="rms-manual-widget-card"
        role="dialog"
        aria-modal="true"
        aria-labelledby="rms-manual-title"
        @click.outside="if (isOpen) $wire.closeModal()"
        @keydown.escape.window="if (isOpen) $wire.closeModal()"
    >
        <!-- Header Bar -->
        <div class="rms-manual-widget-header">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div class="rms-manual-indicator"></div>
                <div style="display: flex; flex-direction: column; gap: 1px;">
                    <span id="rms-manual-title" style="color: #f8fafc; font-weight: 700; font-size: 13.5px; letter-spacing: 0.2px;">
                        {{ $manual['title'] }}
                    </span>
                    <span style="color: #94a3b8; font-size: 11px;">
                        Interactive Page Manual &bull; {{ $manual['line_count'] }} steps
                    </span>
                </div>
            </div>

            <div style="display: flex; align-items: center; gap: 8px;">
                <button type="button" @click="$wire.closeModal()" title="Minimize" class="rms-manual-hdr-btn">
                    <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </button>
                <button type="button" @click="$wire.closeModal()" title="Close" class="rms-manual-hdr-btn">
                    <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>

        <!-- Search Bar -->
        <div class="rms-manual-search-box">
            <div class="rms-manual-search-inner">
                <i class="fa-solid fa-magnifying-glass" style="color: #94a3b8; font-size: 12px;"></i>
                <input
                    type="text"
                    x-model="searchQuery"
                    placeholder="Search instructions or field names..."
                    class="rms-manual-search-input"
                >
                <button
                    type="button"
                    x-show="searchQuery.length > 0"
                    @click="searchQuery = ''"
                    style="border: none; background: transparent; color: #94a3b8; font-size: 12px; cursor: pointer;"
                >&times;</button>
            </div>
        </div>

        <!-- Body: Line by Line Instructions -->
        <div class="rms-manual-widget-body">
            @if(!empty($manual['purpose']))
                <div class="rms-manual-purpose">
                    <i class="fa-solid fa-circle-info" style="color: #3b82f6; font-size: 14px; flex-shrink: 0; margin-top: 2px;"></i>
                    <span>{{ $manual['purpose'] }}</span>
                </div>
            @endif

            @foreach($manual['sections'] as $sIndex => $section)
                <div class="rms-manual-section" x-data="{ expanded: true }">
                    <button
                        type="button"
                        class="rms-manual-section-hdr"
                        @click="expanded = !expanded"
                    >
                        <span style="display: flex; align-items: center; gap: 8px;">
                            <span class="rms-manual-step-badge">{{ $sIndex + 1 }}</span>
                            <span class="rms-manual-section-title">{{ $section['title'] }}</span>
                        </span>
                        <i class="fa-solid fa-chevron-down rms-manual-chevron" :class="{ 'is-flipped': expanded }"></i>
                    </button>

                    <div class="rms-manual-items-list" x-show="expanded" x-transition.opacity.duration.150ms>
                        @foreach($section['items'] as $iIndex => $item)
                            <div
                                class="rms-manual-item-row"
                                x-show="matchesSearch(@js($item['text']), @js($item['label']))"
                            >
                                <span class="rms-manual-dot">&bull;</span>
                                <div class="rms-manual-item-content">
                                    @if(!empty($item['label']))
                                        <strong class="rms-manual-label">{{ $item['label'] }}:</strong>
                                    @endif
                                    <span class="rms-manual-text">{{ $item['text'] }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach

            @if(!empty($manual['callouts']))
                <div class="rms-manual-callouts">
                    @foreach($manual['callouts'] as $callout)
                        <div class="rms-manual-callout {{ $callout['type'] }}">
                            <div class="rms-manual-callout-hdr">
                                @if($callout['type'] === 'warning')
                                    <i class="fa-solid fa-triangle-exclamation"></i>
                                @elseif($callout['type'] === 'tip')
                                    <i class="fa-solid fa-lightbulb"></i>
                                @else
                                    <i class="fa-solid fa-info-circle"></i>
                                @endif
                                <span>{{ $callout['title'] }}</span>
                            </div>
                            <p class="rms-manual-callout-text">{{ $callout['text'] }}</p>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <!-- Footer -->
        <div class="rms-manual-widget-footer">
            <span style="font-size: 11.5px; color: #64748b; font-weight: 500;">
                <i class="fa-regular fa-file-lines" style="margin-right: 4px;"></i> {{ $currentRoute }}.md
            </span>
            <button type="button" class="rms-manual-btn primary" @click="$wire.closeModal()">
                Done
            </button>
        </div>
    </div>
</div>

<style>
    /* Default state: Circular FAB positioned horizontally next to Report button */
    .rms-manual-fab {
        position: fixed;
        right: 160px; /* Horizontally next to Report (92px + 56px + 12px gap) */
        bottom: 24px;
        z-index: 1000000;
        height: 56px;
        min-width: 56px;
        width: 56px;
        border-radius: 999px;
        border: 1px solid rgba(255, 255, 255, 0.12);
        background: #0284c7; /* Sky blue to distinguish from Dark Navy Report */
        color: #f8fafc;
        box-shadow: 0 10px 28px rgba(2, 132, 199, 0.35);
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0;
        overflow: visible;
        white-space: nowrap;
        user-select: none;
        box-sizing: border-box;
        transition: right 0.3s cubic-bezier(0.34, 1.25, 0.64, 1),
                    transform 0.2s cubic-bezier(0.4, 0, 0.2, 1),
                    background 0.2s ease,
                    box-shadow 0.2s ease;
    }

    .rms-manual-fab:hover {
        background: #0369a1;
        transform: translateY(-2px);
        box-shadow: 0 14px 32px rgba(2, 132, 199, 0.45);
    }

    /* Floating Tooltip Label on top */
    .rms-manual-fab::before {
        content: attr(data-tooltip);
        position: absolute;
        bottom: calc(100% + 10px);
        left: 50%;
        transform: translateX(-50%) translateY(4px);
        background: #0f172a;
        color: #f8fafc;
        font-family: 'Inter', system-ui, sans-serif;
        font-size: 11.5px;
        font-weight: 600;
        padding: 5px 9px;
        border-radius: 6px;
        white-space: nowrap;
        pointer-events: none;
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.18s ease, transform 0.18s ease, visibility 0.18s;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
        border: 1px solid rgba(255, 255, 255, 0.1);
        z-index: 1000001;
    }

    .rms-manual-fab:hover::before {
        opacity: 1;
        visibility: visible;
        transform: translateX(-50%) translateY(0);
    }

    .rms-manual-fab-icon {
        width: 30px;
        height: 30px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.2);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    /* If Messify is absent on this page, Guide moves to right: 92px (next to Report at 24px) */
    body.no-messify-widget .rms-manual-fab {
        right: 92px;
    }

    /* When active / open */
    .rms-manual-fab.is-open {
        background: #e11d48 !important;
        box-shadow: 0 12px 30px rgba(225, 29, 72, 0.4) !important;
    }

    /* Floating Manual Card Window - sits at bottom: 96px, completely ABOVE the bottom buttons */
    .rms-manual-widget-card {
        position: fixed;
        right: 24px;
        bottom: 96px;
        width: 440px;
        max-width: calc(100vw - 32px);
        height: 580px;
        max-height: calc(100vh - 120px);
        background: #ffffff;
        border-radius: 16px;
        box-shadow: 0 25px 60px -15px rgba(15, 23, 42, 0.35), 0 0 0 1px rgba(15, 23, 42, 0.06);
        z-index: 1000002;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        font-family: Inter, system-ui, sans-serif;
        animation: rmsManualSlideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    }

    @keyframes rmsManualSlideUp {
        from {
            opacity: 0;
            transform: translateY(18px) scale(0.98);
        }
        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    .rms-manual-widget-header {
        background: #0f172a;
        padding: 14px 18px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        flex-shrink: 0;
    }

    .rms-manual-indicator {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        background: #0284c7;
        box-shadow: 0 0 10px #0284c7;
    }

    .rms-manual-hdr-btn {
        background: transparent;
        border: none;
        color: #94a3b8;
        cursor: pointer;
        padding: 6px;
        border-radius: 6px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: color 0.15s, background 0.15s;
    }

    .rms-manual-hdr-btn:hover {
        color: #f8fafc;
        background: rgba(255, 255, 255, 0.1);
    }

    .rms-manual-search-box {
        padding: 10px 16px;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        flex-shrink: 0;
    }

    .rms-manual-search-inner {
        display: flex;
        align-items: center;
        gap: 8px;
        background: #ffffff;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 6px 12px;
    }

    .rms-manual-search-input {
        border: none;
        outline: none;
        background: transparent;
        width: 100%;
        font-size: 12.5px;
        color: #1e293b;
    }

    .rms-manual-widget-body {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto;
        overflow-x: hidden;
        padding: 16px;
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .rms-manual-purpose {
        flex-shrink: 0;
        display: flex;
        gap: 10px;
        background: #eff6ff;
        border: 1px solid #bfdbfe;
        border-radius: 10px;
        padding: 10px 14px;
        font-size: 12.5px;
        color: #1e40af;
        line-height: 1.45;
    }

    .rms-manual-section {
        flex-shrink: 0;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        overflow: hidden;
        background: #ffffff;
    }

    .rms-manual-section-hdr {
        width: 100%;
        min-height: 42px;
        flex-shrink: 0;
        background: #f8fafc;
        border: none;
        border-bottom: 1px solid #e2e8f0;
        padding: 11px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        cursor: pointer;
        text-align: left;
    }

    .rms-manual-step-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 20px;
        height: 20px;
        border-radius: 50%;
        background: #0284c7;
        color: #ffffff;
        font-size: 11px;
        font-weight: 700;
    }

    .rms-manual-section-title {
        font-size: 13px;
        font-weight: 700;
        color: #1e293b;
    }

    .rms-manual-chevron {
        color: #64748b;
        font-size: 11px;
        transition: transform 0.2s ease;
    }

    .rms-manual-chevron.is-flipped {
        transform: rotate(180deg);
    }

    .rms-manual-items-list {
        padding: 10px 14px;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .rms-manual-item-row {
        display: flex;
        align-items: flex-start;
        gap: 8px;
        font-size: 12.5px;
        line-height: 1.45;
    }

    .rms-manual-dot {
        color: #0284c7;
        font-size: 16px;
        line-height: 1;
        margin-top: 1px;
    }

    .rms-manual-label {
        color: #0f172a;
        font-weight: 600;
        margin-right: 4px;
    }

    .rms-manual-text {
        color: #475569;
    }

    .rms-manual-callouts {
        flex-shrink: 0;
        display: flex;
        flex-direction: column;
        gap: 10px;
        margin-top: 4px;
    }

    .rms-manual-callout {
        border-radius: 8px;
        padding: 10px 12px;
        font-size: 12px;
        line-height: 1.45;
    }

    .rms-manual-callout.note {
        background: #f0fdf4;
        border: 1px solid #bbf7d0;
        color: #166534;
    }

    .rms-manual-callout.warning {
        background: #fffbeb;
        border: 1px solid #fde68a;
        color: #92400e;
    }

    .rms-manual-callout-hdr {
        display: flex;
        align-items: center;
        gap: 6px;
        font-weight: 700;
        margin-bottom: 4px;
    }

    .rms-manual-callout-text {
        margin: 0;
    }

    .rms-manual-widget-footer {
        padding: 12px 18px;
        background: #f8fafc;
        border-top: 1px solid #e2e8f0;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-shrink: 0;
    }

    .rms-manual-btn {
        border: none;
        border-radius: 8px;
        padding: 7px 16px;
        font-size: 12.5px;
        font-weight: 600;
        cursor: pointer;
    }

    .rms-manual-btn.primary {
        background: #0284c7;
        color: #ffffff;
    }

    .rms-manual-btn.primary:hover {
        background: #0369a1;
    }

    /* Dark Mode Support */
    [data-theme="dark"] .rms-manual-widget-card {
        background: #0f172a;
        border: 1px solid #334155;
    }
    [data-theme="dark"] .rms-manual-search-box {
        background: #1e293b;
        border-color: #334155;
    }
    [data-theme="dark"] .rms-manual-search-inner {
        background: #0f172a;
        border-color: #334155;
    }
    [data-theme="dark"] .rms-manual-search-input {
        color: #f8fafc;
    }
    [data-theme="dark"] .rms-manual-section {
        background: #1e293b;
        border-color: #334155;
    }
    [data-theme="dark"] .rms-manual-section-hdr {
        background: #1e293b;
        border-color: #334155;
    }
    [data-theme="dark"] .rms-manual-section-title {
        color: #f8fafc;
    }
    [data-theme="dark"] .rms-manual-label {
        color: #f1f5f9;
    }
    [data-theme="dark"] .rms-manual-text {
        color: #cbd5e1;
    }
    [data-theme="dark"] .rms-manual-widget-footer {
        background: #1e293b;
        border-color: #334155;
    }
</style>
</div>
@endif
</div>
