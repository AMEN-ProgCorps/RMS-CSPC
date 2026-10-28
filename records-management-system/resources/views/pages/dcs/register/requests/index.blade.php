<?php

/**
 * DCS file: Office DRF and DCN requests waiting for Admin DCS. Proceed to Registration is what links the intake to the document that gets saved.
 */

use App\Helpers\OfficeIntakeHelper;
use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('Request — CSPC DCS')] class extends Component {
    #[Url]
    public string $filter = 'all';

    public int $page = 1;

    public function mount(): void
    {
        abort_unless(RegisterQueryHelper::canBrowseAllOfficeIntake(), 403);
        if (! in_array($this->filter, ['all', 'drf', 'dcn'], true)) {
            $this->filter = 'all';
        }
    }

    public function updatedFilter(): void
    {
        $this->page = 1;
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function with(): array
    {
        $allRows = OfficeIntakeHelper::listPendingOfficeRequests('all');
        $filtered = $this->filter === 'all'
            ? $allRows
            : $allRows->where('type', $this->filter)->values();
        $perPage = 20;
        $total = $filtered->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $this->page), $lastPage);

        return [
            'rows' => $filtered->slice(($page - 1) * $perPage, $perPage)->values(),
            'filter' => $this->filter,
            'page' => $page,
            'lastPage' => $lastPage,
            'filteredTotal' => $total,
            'totalCount' => $allRows->count(),
            'drfCount' => $allRows->where('type', 'drf')->count(),
            'dcnCount' => $allRows->where('type', 'dcn')->count(),
            'pendingCount' => $allRows->where('received', false)->count(),
            'receivedCount' => $allRows->where('received', true)->count(),
        ];
    }
}; ?>

<div class="upd-container main-content ofi-request-page" wire:poll.5s.visible>
    <div class="upd-header">
        <div>
            <div class="upd-breadcrumb">Document Control System / Document Registration / <span>Request</span></div>
            <div class="upd-title">Request</div>
        </div>
        <div class="upd-header-stats">
            <span class="upd-count">{{ $totalCount }} Pending</span>
        </div>
    </div>

    <div class="ofi-request-stats">
        <div class="ofi-request-stat">
            <span class="ofi-request-stat-label">Total pending</span>
            <strong>{{ $totalCount }}</strong>
        </div>
        <div class="ofi-request-stat is-drf">
            <span class="ofi-request-stat-label">DRF</span>
            <strong>{{ $drfCount }}</strong>
        </div>
        <div class="ofi-request-stat is-dcn">
            <span class="ofi-request-stat-label">DCN</span>
            <strong>{{ $dcnCount }}</strong>
        </div>
        <div class="ofi-request-stat is-received">
            <span class="ofi-request-stat-label">Received</span>
            <strong>{{ $receivedCount }}</strong>
        </div>
        <div class="ofi-request-stat is-awaiting">
            <span class="ofi-request-stat-label">Awaiting receive</span>
            <strong>{{ $pendingCount }}</strong>
        </div>
    </div>

    <div class="upd-search-bar ofi-request-toolbar">
        <div class="ofi-request-filters">
            <button type="button" wire:click="$set('filter', 'all')" class="ofi-request-filter {{ $filter === 'all' ? 'is-active' : '' }}">
                All <span>{{ $totalCount }}</span>
            </button>
            <button type="button" wire:click="$set('filter', 'drf')" class="ofi-request-filter {{ $filter === 'drf' ? 'is-active' : '' }}">
                DRF <span>{{ $drfCount }}</span>
            </button>
            <button type="button" wire:click="$set('filter', 'dcn')" class="ofi-request-filter {{ $filter === 'dcn' ? 'is-active' : '' }}">
                DCN <span>{{ $dcnCount }}</span>
            </button>
        </div>
    </div>

    <div class="upd-table-card ofi-request-card" style="position:relative;" wire:loading.class="is-loading" wire:target="filter">
        {{-- Overlay only on intentional filter changes — silent on wire:poll background refresh --}}
        <div class="dcs-loading-overlay" wire:loading.flex wire:target="filter">
            <div class="dcs-loading-spinner" aria-hidden="true"></div>
            <h4>Loading requests…</h4>
            <p>Fetching records and preparing the list.</p>
        </div>
        <div class="upd-table-scroll">
            <table class="upd-table ofi-request-table">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Requestor</th>
                        <th>Title</th>
                        <th>Form Date</th>
                        <th>Status</th>
                        <th>Submitted</th>
                        <th style="width:130px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $row)
                        <tr>
                            <td>
                                <span class="ofi-type-pill is-{{ $row->type }}">{{ strtoupper($row->type) }}</span>
                            </td>
                            <td class="ofi-requestor">
                                <div class="ofi-requestor-name">{{ $row->submitter_name }}</div>
                                <div class="ofi-requestor-unit">{{ $row->submitting_office }}</div>
                            </td>
                            <td class="upd-doc-title">
                                <a class="ofi-request-title-link" href="{{ route('dcs.requests.show', [$row->type, $row->id], absolute: false) }}">
                                    {{ $row->title }}
                                </a>
                            </td>
                            <td>{{ !empty($row->form_date) ? \App\Helpers\RegisterQueryHelper::formatRdpPeriodDate($row->form_date) : '—' }}</td>
                            <td>
                                @if(!empty($row->received))
                                    <span class="ofi-status-pill is-received">Received</span>
                                @else
                                    <span class="ofi-status-pill is-pending">Pending</span>
                                @endif
                            </td>
                            <td>{{ $row->created_at ? \App\Helpers\RegisterQueryHelper::formatRdpPeriodDate(\Carbon\Carbon::parse($row->created_at)->timezone('Asia/Manila')) . ' ' . \Carbon\Carbon::parse($row->created_at)->timezone('Asia/Manila')->format('g:i A') : '—' }}</td>
                            <td>
                                <div class="upd-actions">
                                    <a class="ofi-request-review-btn" href="{{ route('dcs.requests.show', [$row->type, $row->id], absolute: false) }}" title="Review">
                                        <i class="fa-solid fa-eye"></i> Review
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="ofi-empty">
                                <div class="ofi-request-empty">
                                    <i class="fa-regular fa-inbox"></i>
                                    <p>No pending office requests{{ $filter !== 'all' ? ' for ' . strtoupper($filter) : '' }}.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @php
            $pg = (int) ($page ?? 1);
            $pgLast = (int) ($lastPage ?? 1);
            $pgTotal = (int) ($filteredTotal ?? 0);
            $pgSize = 20;
            $pgFrom = $pgTotal === 0 ? 0 : (($pg - 1) * $pgSize) + 1;
            $pgTo = min($pgTotal, $pg * $pgSize);
            $pgStart = max(1, $pg - 2);
            $pgEnd = min($pgLast, $pg + 2);
        @endphp
        @if($pgTotal > 0)
        <div class="upd-pagination">
            <div class="upd-pagination-info">
                Showing {{ $pgFrom }}–{{ $pgTo }} of {{ $pgTotal }}
            </div>
            <div class="upd-pagination-links">
                @if($pg > 1)
                    <button type="button" class="upd-pg" wire:click="goToPage({{ $pg - 1 }})">Prev</button>
                @endif
                @for($p = $pgStart; $p <= $pgEnd; $p++)
                    <button type="button" class="upd-pg {{ $p === $pg ? 'upd-pg-active' : '' }}" wire:click="goToPage({{ $p }})" @if($p === $pg) disabled @endif>{{ $p }}</button>
                @endfor
                @if($pg < $pgLast)
                    <button type="button" class="upd-pg" wire:click="goToPage({{ $pg + 1 }})">Next</button>
                @endif
            </div>
        </div>
        @endif
    </div>
</div>
