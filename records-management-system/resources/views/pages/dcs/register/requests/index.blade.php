<?php

use App\Helpers\OfficeIntakeHelper;
use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('Request — CSPC DCS')] class extends Component {
    #[Url]
    public string $filter = 'all';

    public function mount(): void
    {
        abort_unless(RegisterQueryHelper::canBrowseAllOfficeIntake(), 403);
        if (! in_array($this->filter, ['all', 'drf', 'dcn'], true)) {
            $this->filter = 'all';
        }
    }

    public function with(): array
    {
        $rows = OfficeIntakeHelper::listPendingOfficeRequests($this->filter);
        $allRows = $this->filter === 'all'
            ? $rows
            : OfficeIntakeHelper::listPendingOfficeRequests('all');

        return [
            'rows' => $rows,
            'filter' => $this->filter,
            'totalCount' => $allRows->count(),
            'drfCount' => $allRows->where('type', 'drf')->count(),
            'dcnCount' => $allRows->where('type', 'dcn')->count(),
            'pendingCount' => $allRows->where('received', false)->count(),
            'receivedCount' => $allRows->where('received', true)->count(),
        ];
    }
}; ?>

<div class="upd-container main-content ofi-request-page">
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

    <div class="upd-table-card ofi-request-card" style="position:relative;" wire:loading.class="is-loading">
        <div class="dcs-loading-overlay" wire:loading.flex>
            <div class="dcs-loading-spinner" aria-hidden="true"></div>
            <h4>Loading requests…</h4>
            <p>Fetching records and preparing the list.</p>
        </div>
        <div class="upd-table-scroll">
            <table class="upd-table ofi-request-table">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Title</th>
                        <th>Office</th>
                        <th>Submitted by</th>
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
                            <td class="upd-doc-title">
                                <a class="ofi-request-title-link" href="{{ route('dcs.requests.show', [$row->type, $row->id], absolute: false) }}">
                                    {{ $row->title }}
                                </a>
                            </td>
                            <td>{{ $row->submitting_office }}</td>
                            <td>{{ $row->submitter_name }}</td>
                            <td>
                                @if(!empty($row->received))
                                    <span class="ofi-status-pill is-received">Received</span>
                                @else
                                    <span class="ofi-status-pill is-pending">Pending</span>
                                @endif
                            </td>
                            <td>{{ $row->created_at ? \Carbon\Carbon::parse($row->created_at)->timezone('Asia/Manila')->format('M d, Y g:i A') : '—' }}</td>
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
    </div>
</div>
