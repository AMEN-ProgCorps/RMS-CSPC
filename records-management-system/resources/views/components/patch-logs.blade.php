<?php

use App\Services\PatchNoteService;
use Livewire\Volt\Component;

new class extends Component {
    public bool $open = false;
    public string $selectedVersion = '';
    public string $subsystemFilter = 'ALL';

    public function mount(): void
    {
        $latest = PatchNoteService::getLatestRelease();
        $this->selectedVersion = $latest['version'] ?? '';
    }

    public function selectVersion(string $version): void
    {
        $this->selectedVersion = $version;
    }

    public function setSubsystem(string $code): void
    {
        $code = strtoupper(trim($code));
        $this->subsystemFilter = $code;
        $releases = PatchNoteService::getReleases($code === 'ALL' ? null : $code);
        if (!empty($releases)) {
            $this->selectedVersion = $releases[0]['version'];
        } else {
            $this->selectedVersion = '';
        }
    }

    public function with(): array
    {
        $releases = PatchNoteService::getReleases($this->subsystemFilter === 'ALL' ? null : $this->subsystemFilter);
        $selected = null;

        if ($this->selectedVersion !== '') {
            foreach ($releases as $r) {
                if ($r['version'] === $this->selectedVersion) {
                    $selected = $r;
                    break;
                }
            }
        }

        if (!$selected && !empty($releases)) {
            $selected = $releases[0];
            $this->selectedVersion = $selected['version'];
        }

        return [
            'releases' => $releases,
            'selectedRelease' => $selected,
        ];
    }
};
?>

<div>
<div
    x-data="{
        isOpen: @entangle('open'),
        searchQuery: '',
        init() {
            this.$watch('isOpen', val => {
                if (val) {
                    document.body.classList.add('patch-widget-open');
                    window.dispatchEvent(new CustomEvent('rms-widget-opened', { detail: { widget: 'patch-logs' } }));
                } else {
                    document.body.classList.remove('patch-widget-open');
                    window.dispatchEvent(new CustomEvent('rms-widget-closed', { detail: { widget: 'patch-logs' } }));
                }
            });
        },
        matchesSearch(text, label) {
            if (!this.searchQuery || this.searchQuery.trim() === '') return true;
            var q = this.searchQuery.toLowerCase().trim();
            var hay = ((label || '') + ' ' + (text || '')).toLowerCase();
            return hay.indexOf(q) !== -1;
        }
    }"
    x-on:open-patch-logs.window="
        isOpen = true;
        if ($event.detail && $event.detail.subsystem) {
            $wire.setSubsystem($event.detail.subsystem);
        }
    "
    x-on:rms-open-patch-logs.window="
        isOpen = true;
        if ($event.detail && $event.detail.subsystem) {
            $wire.setSubsystem($event.detail.subsystem);
        }
    "
    x-on:rms-widget-opened.window="if ($event.detail && ($event.detail.widget || (Array.isArray($event.detail) ? $event.detail[0]?.widget : null)) !== 'patch-logs' && isOpen) { isOpen = false; }"
    x-on:scanner-modal-opened.window="if (isOpen) { isOpen = false; }"
>
    <!-- Floating Patch Notes Widget Card (Docked in bottom-right) -->
    <div
        x-show="isOpen"
        x-cloak
        class="rms-patch-widget-card"
        role="dialog"
        aria-modal="true"
        aria-labelledby="rms-patch-title"
        @click.outside="isOpen = false"
        @keydown.escape.window="isOpen = false"
    >
        <!-- Header Bar -->
        <div class="rms-patch-widget-header">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div class="rms-patch-indicator"></div>
                <div style="display: flex; flex-direction: column; gap: 1px;">
                    <span id="rms-patch-title" style="color: #f8fafc; font-weight: 700; font-size: 13.5px; letter-spacing: 0.2px; display: flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-sparkles" style="color: #38bdf8; font-size: 12px;"></i>
                        System Update Logs & Patches
                    </span>
                    <span style="color: #94a3b8; font-size: 11px;">
                        Release Notes &bull; {{ count($releases) }} versions tracked
                    </span>
                </div>
            </div>

            <div style="display: flex; align-items: center; gap: 8px;">
                <button type="button" @click="isOpen = false" title="Close" class="rms-patch-hdr-btn">
                    <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>

        <!-- Subsystems Filter Row -->
        <div class="rms-patch-sub-tabs">
            <button type="button" class="rms-patch-sub-tab {{ $subsystemFilter === 'ALL' ? 'active' : '' }}" wire:click="setSubsystem('ALL')">All</button>
            <button type="button" class="rms-patch-sub-tab {{ $subsystemFilter === 'DTS' ? 'active' : '' }}" wire:click="setSubsystem('DTS')">DTS</button>
            <button type="button" class="rms-patch-sub-tab {{ $subsystemFilter === 'RDP' ? 'active' : '' }}" wire:click="setSubsystem('RDP')">RDP</button>
            <button type="button" class="rms-patch-sub-tab {{ $subsystemFilter === 'ADMIN' ? 'active' : '' }}" wire:click="setSubsystem('ADMIN')">Admin</button>
        </div>

        <!-- Versions Bar -->
        <div class="rms-patch-versions-bar">
            <div class="rms-patch-versions-scroll">
                @forelse($releases as $rel)
                    <button
                        type="button"
                        class="rms-patch-ver-pill {{ $selectedVersion === $rel['version'] ? 'active' : '' }}"
                        wire:click="selectVersion('{{ $rel['version'] }}')"
                    >
                        @if($subsystemFilter === 'ALL')
                            <span style="font-size: 0.65rem; font-weight: 700; opacity: 0.75;">{{ $rel['subsystem_code'] }}</span>
                        @endif
                        <span>{{ $rel['version'] }}</span>
                        @if($rel['is_latest'])
                            <span class="rms-patch-pill-badge">Latest</span>
                        @endif
                    </button>
                @empty
                    <span style="font-size: 0.78rem; color: #94a3b8; padding: 4px 8px;">No updates found for this subsystem.</span>
                @endforelse
            </div>
        </div>

        <!-- Search Bar -->
        <div class="rms-patch-search-box">
            <div class="rms-patch-search-inner">
                <i class="fa-solid fa-magnifying-glass" style="color: #94a3b8; font-size: 12px;"></i>
                <input
                    type="text"
                    x-model="searchQuery"
                    placeholder="Search update items, fixes, or changes..."
                    class="rms-patch-search-input"
                >
                <button
                    type="button"
                    x-show="searchQuery.length > 0"
                    @click="searchQuery = ''"
                    style="border: none; background: transparent; color: #94a3b8; font-size: 12px; cursor: pointer;"
                >&times;</button>
            </div>
        </div>

        <!-- Body: Release Details -->
        <div class="rms-patch-widget-body">
            @if($selectedRelease)
                <div class="rms-patch-rel-meta">
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="font-size: 0.72rem; font-weight: 700; background: {{ $selectedRelease['subsystem_code'] === 'DTS' ? '#e0e7ff; color: #3730a3;' : ($selectedRelease['subsystem_code'] === 'RDP' ? '#e0f2fe; color: #0369a1;' : ($selectedRelease['subsystem_code'] === 'ADMIN' ? '#f1f5f9; color: #334155;' : '#ecfdf5; color: #065f46;')) }}; padding: 2px 7px; border-radius: 4px; text-transform: uppercase;">
                                {{ $selectedRelease['subsystem_name'] }}
                            </span>
                            <span style="font-size: 1.1rem; font-weight: 800; color: #0f172a; font-family: monospace;">
                                {{ $selectedRelease['version'] }}
                            </span>
                        </div>
                        <span style="font-size: 0.8rem; color: #64748b; display: flex; align-items: center; gap: 5px;">
                            <i class="fa-regular fa-calendar"></i>
                            {{ $selectedRelease['date'] ?: 'Current Version' }}
                        </span>
                    </div>

                    @if(!empty($selectedRelease['summary']))
                        <div class="rms-patch-purpose">
                            <i class="fa-solid fa-circle-info" style="color: #3b82f6; font-size: 13px; flex-shrink: 0; margin-top: 2px;"></i>
                            <span>{{ $selectedRelease['summary'] }}</span>
                        </div>
                    @endif
                </div>

                @forelse($selectedRelease['categories'] as $cat)
                    <div class="rms-patch-cat-card">
                        <div class="rms-patch-cat-hdr">
                            <span class="rms-patch-cat-chip {{ $cat['type'] }}">
                                @if($cat['type'] === 'added') <i class="fa-solid fa-plus"></i>
                                @elseif($cat['type'] === 'fixed') <i class="fa-solid fa-wrench"></i>
                                @elseif($cat['type'] === 'changed') <i class="fa-solid fa-arrows-rotate"></i>
                                @elseif($cat['type'] === 'security') <i class="fa-solid fa-shield"></i>
                                @endif
                                {{ $cat['name'] }}
                            </span>
                            <span style="font-size: 0.75rem; color: #94a3b8;">{{ count($cat['items']) }} items</span>
                        </div>

                        <div class="rms-patch-items-list">
                            @foreach($cat['items'] as $item)
                                <div
                                    class="rms-patch-item-row"
                                    x-show="matchesSearch(@js($item['text']), @js($item['label']))"
                                >
                                    <span class="rms-patch-dot">&bull;</span>
                                    <div class="rms-patch-item-content">
                                        @if(!empty($item['label']))
                                            <strong class="rms-patch-label">{{ $item['label'] }}:</strong>
                                        @endif
                                        <span class="rms-patch-text">{{ $item['text'] }}</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <div style="padding: 24px; text-align: center; color: #94a3b8; font-size: 0.85rem;">
                        No categorized items found for this release.
                    </div>
                @endforelse
            @else
                <div style="padding: 32px; text-align: center; color: #94a3b8;">
                    No release notes available.
                </div>
            @endif
        </div>

        <!-- Footer -->
        <div class="rms-patch-widget-footer">
            <span style="font-size: 11px; color: #94a3b8;">
                Updates are maintained in <code>resources/patch-notes/README.md</code>
            </span>
            <button
                type="button"
                @click="isOpen = false"
                class="rms-patch-close-btn"
            >
                Done
            </button>
        </div>
    </div>
</div>
</div>

<style>
    .rms-patch-widget-card {
        position: fixed;
        right: 24px;
        bottom: 24px;
        width: 460px;
        max-width: calc(100vw - 32px);
        height: 640px;
        max-height: calc(100vh - 48px);
        background: #ffffff;
        border-radius: 16px;
        box-shadow: 0 25px 60px -15px rgba(15, 23, 42, 0.4), 0 0 0 1px rgba(15, 23, 42, 0.08);
        z-index: 1000020;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        pointer-events: auto;
        font-family: 'Inter', system-ui, -apple-system, sans-serif;
        animation: rmsPatchSlideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    }

    @keyframes rmsPatchSlideUp {
        from {
            opacity: 0;
            transform: translateY(16px) scale(0.97);
        }
        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    .rms-patch-widget-header {
        background: #0f172a;
        padding: 14px 18px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        flex-shrink: 0;
    }

    .rms-patch-indicator {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        background: #10b981;
        box-shadow: 0 0 10px #10b981;
    }

    .rms-patch-hdr-btn {
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

    .rms-patch-hdr-btn:hover {
        color: #f8fafc;
        background: rgba(255, 255, 255, 0.1);
    }

    .rms-patch-sub-tabs {
        background: #1e293b;
        padding: 6px 14px;
        display: flex;
        gap: 6px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        flex-shrink: 0;
    }

    .rms-patch-sub-tab {
        background: transparent;
        border: 1px solid transparent;
        color: #94a3b8;
        font-size: 0.72rem;
        font-weight: 700;
        padding: 3px 10px;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.15s ease;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }

    .rms-patch-sub-tab:hover {
        color: #f8fafc;
        background: rgba(255, 255, 255, 0.08);
    }

    .rms-patch-sub-tab.active {
        background: #003699;
        color: #ffffff;
        border-color: #3b82f6;
    }

    .rms-patch-versions-bar {
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        padding: 8px 14px;
        flex-shrink: 0;
    }

    .rms-patch-versions-scroll {
        display: flex;
        align-items: center;
        gap: 6px;
        overflow-x: auto;
        scrollbar-width: thin;
    }

    .rms-patch-ver-pill {
        border: 1px solid #e2e8f0;
        background: #ffffff;
        color: #475569;
        font-size: 0.78rem;
        font-weight: 600;
        padding: 5px 12px;
        border-radius: 99px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        white-space: nowrap;
        transition: all 0.15s ease;
    }

    .rms-patch-ver-pill:hover {
        background: #f1f5f9;
        border-color: #cbd5e1;
    }

    .rms-patch-ver-pill.active {
        background: #003699;
        color: #ffffff;
        border-color: #003699;
        box-shadow: 0 2px 6px rgba(0, 54, 153, 0.25);
    }

    .rms-patch-pill-badge {
        font-size: 0.65rem;
        font-weight: 700;
        background: #10b981;
        color: #ffffff;
        padding: 1px 6px;
        border-radius: 99px;
        text-transform: uppercase;
    }

    .rms-patch-search-box {
        padding: 8px 14px;
        background: #ffffff;
        border-bottom: 1px solid #e2e8f0;
        flex-shrink: 0;
    }

    .rms-patch-search-inner {
        display: flex;
        align-items: center;
        gap: 8px;
        background: #f8fafc;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 6px 12px;
    }

    .rms-patch-search-input {
        border: none;
        outline: none;
        background: transparent;
        width: 100%;
        font-size: 12.5px;
        color: #1e293b;
    }

    .rms-patch-widget-body {
        padding: 16px;
        overflow-y: auto;
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 14px;
        background: #f8fafc;
    }

    .rms-patch-rel-meta {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 12px 16px;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .rms-patch-purpose {
        background: #eff6ff;
        border-left: 3px solid #3b82f6;
        padding: 8px 12px;
        border-radius: 6px;
        font-size: 0.82rem;
        color: #1e40af;
        line-height: 1.45;
        display: flex;
        align-items: flex-start;
        gap: 8px;
    }

    .rms-patch-cat-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        overflow: hidden;
    }

    .rms-patch-cat-hdr {
        background: #f8fafc;
        padding: 8px 14px;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .rms-patch-cat-chip {
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        padding: 2px 8px;
        border-radius: 6px;
        letter-spacing: 0.04em;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }

    .rms-patch-cat-chip.added { background: #dcfce7; color: #15803d; }
    .rms-patch-cat-chip.fixed { background: #fef3c7; color: #b45309; }
    .rms-patch-cat-chip.changed { background: #e0f2fe; color: #0369a1; }
    .rms-patch-cat-chip.security { background: #f3e8ff; color: #7e22ce; }
    .rms-patch-cat-chip.deprecated { background: #fee2e2; color: #b91c1c; }
    .rms-patch-cat-chip.removed { background: #f1f5f9; color: #475569; }
    .rms-patch-cat-chip.info { background: #f1f5f9; color: #334155; }

    .rms-patch-items-list {
        padding: 10px 14px;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .rms-patch-item-row {
        display: flex;
        align-items: flex-start;
        gap: 8px;
        font-size: 0.82rem;
        line-height: 1.45;
        color: #1e293b;
    }

    .rms-patch-dot {
        color: #94a3b8;
        font-size: 0.9rem;
    }

    .rms-patch-item-content {
        flex: 1;
    }

    .rms-patch-label {
        font-weight: 700;
        color: #0f172a;
    }

    .rms-patch-widget-footer {
        padding: 10px 16px;
        background: #ffffff;
        border-top: 1px solid #e2e8f0;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-shrink: 0;
    }

    .rms-patch-close-btn {
        background: #0f172a;
        color: #ffffff;
        border: none;
        padding: 6px 14px;
        border-radius: 99px;
        font-size: 0.78rem;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.15s;
    }

    .rms-patch-close-btn:hover {
        background: #1e293b;
    }

    /* Subsystem version interactive trigger styling */
    .subsystem-version {
        cursor: pointer;
        transition: all 0.15s ease;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        user-select: none;
    }

    .subsystem-version:hover {
        color: #38bdf8 !important;
        text-decoration: underline;
    }

    .subsystem-version:hover::after {
        content: ' 🚀';
        font-size: 10px;
    }
</style>
