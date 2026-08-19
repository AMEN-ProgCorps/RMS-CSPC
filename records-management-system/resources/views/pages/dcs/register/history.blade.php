<?php

use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('Revision History — CSPC DCS')] class extends Component {
    public string $docNo = '';

    public function mount(string $docNo): void
    {
        $this->docNo = $docNo;
    }

    public function with(): array
    {
        return RegisterQueryHelper::history($this->docNo);
    }
}; ?>

<div class="hst-container" x-data @keydown.escape.window="window.location.href = '{{ route('dcs.register.update') }}'">
    <div class="reg-header">
        <div>
            <div class="reg-breadcrumb">Document Control System / Update / <span>History</span></div>
            <div style="display:flex; align-items:center; gap:12px;">
                <div class="reg-title">{{ $docTitle }}</div>
                <span class="hst-badge"><i class="fa-solid fa-clock-rotate-left"></i> {{ $revisions->count() }} {{ \Illuminate\Support\Str::plural('revision', $revisions->count()) }}</span>
            </div>
        </div>
        <a href="{{ route('dcs.register.update') }}" class="reg-btn reg-btn-cancel">
            <i class="fa-solid fa-arrow-left"></i> Back to Documents
        </a>
    </div>

    <div class="hst-timeline">
        @foreach($revisions as $i => $rev)
            @php
                $isLatest = ($rev->revision_status ?? '') === 'latest'
                    || (($rev->revision_status ?? null) === null && $i === 0);
                $checklists = [];
                if ($rev->drf_id) $checklists[] = 'DRF';
                if ($rev->dcn_id) $checklists[] = 'DCN';
                if ($rev->doc_title || $rev->revise_no !== null) $checklists[] = 'ML';
            @endphp
            <div class="hst-node {{ $isLatest ? 'hst-node-current' : '' }}">
                <div class="hst-dot">
                    @if($isLatest)
                        <i class="fa-solid fa-circle-check"></i>
                    @else
                        <i class="fa-solid fa-circle"></i>
                    @endif
                </div>
                <div class="hst-card">
                    <div class="hst-card-header">
                        <span class="hst-rev-badge">Rev {{ $rev->revise_no ?? 0 }}</span>
                        @if($isLatest) <span class="hst-current-tag">Current</span> @endif
                        <span class="hst-date">{{ $rev->created_at ? \Carbon\Carbon::parse($rev->created_at)->format('M d, Y h:i A') : 'N/A' }}</span>
                    </div>
                    <div class="hst-card-body">
                        <div class="hst-info-grid">
                            <div class="hst-info-item">
                                <span class="hst-info-label">Title</span>
                                <span class="hst-info-value">{{ $rev->doc_title ?? 'N/A' }}</span>
                            </div>
                            <div class="hst-info-item">
                                <span class="hst-info-label">Effectivity</span>
                                <span class="hst-info-value">{{ $rev->effectivity_date ? \Carbon\Carbon::parse($rev->effectivity_date)->format('M d, Y') : 'N/A' }}</span>
                            </div>
                            <div class="hst-info-item">
                                <span class="hst-info-label">Pages</span>
                                <span class="hst-info-value">{{ $rev->no_pages ?? 'N/A' }}</span>
                            </div>
                            <div class="hst-info-item">
                                <span class="hst-info-label">Originator</span>
                                <span class="hst-info-value">{{ $rev->originator_name ?? 'N/A' }}</span>
                            </div>
                            <div class="hst-info-item">
                                <span class="hst-info-label">Purpose</span>
                                <span class="hst-info-value">{{ $rev->brief_purpose ?? 'N/A' }}</span>
                            </div>
                        </div>
                        <div class="hst-tags">
                            @foreach($checklists as $cl)
                                <span class="hst-tag">{{ $cl }}</span>
                            @endforeach
                        </div>
                    </div>
                    @if($isLatest)
                        <div class="hst-card-actions">
                            <a href="{{ route('dcs.register.edit', $rev->id) }}" class="hst-btn-edit">
                                <i class="fa-solid fa-pen-to-square"></i> Edit This Revision
                            </a>
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
