<?php
/**
 * Admin Console - DTS Update & Append Logs Volt Component
 * 
 * Lists document append/linking history in the Document Tracking System.
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Admin Console - DTS Update Logs')] class extends Component {
    use WithPagination;

    /** @var string Real-time search query */
    public string $search = '';

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if (!$perms || (!$perms->is_sadm && !$perms->can_access_dts_admin && !$perms->can_dts_modify_docflow)) {
            $this->redirect(route('portal'));
            return;
        }
    }

    /**
     * Reset pagination page on search update.
     */
    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Clear search filter.
     */
    public function clearFilters(): void
    {
        $this->search = '';
        $this->resetPage();
    }

    /**
     * Fetch logs and pass them to the Blade template.
     */
    public function with(): array
    {
        $query = \DB::table('dts_transaction_version')
            ->leftJoin('dts_transaction_details as child_details', 'dts_transaction_version.child_transaction_id', '=', 'child_details.id')
            ->leftJoin('dts_transaction_details as parent_details', 'dts_transaction_version.parent_transaction_id', '=', 'parent_details.id')
            ->select([
                'dts_transaction_version.append_id',
                'dts_transaction_version.date_append',
                'dts_transaction_version.child_transaction_id',
                'dts_transaction_version.parent_transaction_id',
                'child_details.control_number as child_control',
                'parent_details.control_number as parent_control',
                'child_details.type as child_type',
                'parent_details.type as parent_type',
            ]);

        if ($this->search !== '') {
            $searchVal = '%' . $this->search . '%';
            $query->where(function ($q) use ($searchVal) {
                $q->where('dts_transaction_version.append_id', 'like', $searchVal)
                  ->orWhere('child_details.control_number', 'like', $searchVal)
                  ->orWhere('parent_details.control_number', 'like', $searchVal);
            });
        }

        $logs = $query->orderBy('dts_transaction_version.date_append', 'desc')->paginate(15);

        return [
            'logs' => $logs,
        ];
    }
};
?>

@push('styles')
    @vite('resources/css/admin/activity_logs.css')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        .doc-link-card {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
        }
        .doc-tag {
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            background-color: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
            text-transform: uppercase;
        }
        .arrow-connector {
            color: #94a3b8;
            font-size: 14px;
        }
    </style>
@endpush

<div class="activity-logs-container">
    <!-- Header -->
    <div class="page-header">
        <h1>Document Tracking - Update & Append Logs</h1>
        <p>Audit historical records of document links, child transactions, and version updates appended to parent transactions.</p>
    </div>

    <!-- Controls Panel -->
    <div class="logs-controls-card">
        <div class="search-filter-group">
            <!-- Search Input -->
            <div class="search-box-wrapper">
                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                <input type="text" 
                       class="search-input" 
                       placeholder="Search by link ID or control numbers..." 
                       wire:model.live="search">
            </div>

            <!-- Clear Button -->
            @if($search !== '')
                <button type="button" class="btn-clear-filters" wire:click="clearFilters">
                    <i class="fa-solid fa-filter-circle-xmark"></i> Clear Filters
                </button>
            @endif
        </div>
    </div>

    <!-- Data Table Card -->
    <div class="logs-table-card">
        <div class="table-responsive">
            <table class="logs-table">
                <thead>
                    <tr>
                        <th style="width: 15%">Link ID</th>
                        <th style="width: 35%">Child Document (Appended)</th>
                        <th style="width: 10%; text-align: center;">Linkage</th>
                        <th style="width: 35%">Parent Document (Main)</th>
                        <th style="width: 15%">Appended On</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        <tr wire:key="dts-append-{{ $log->append_id }}">
                            <td>
                                <span style="font-weight: 600; color: #64748b;">{{ $log->append_id }}</span>
                            </td>
                            <td>
                                <div class="doc-link-card">
                                    <span style="font-weight: 700; color: #003699;">{{ $log->child_control ?? $log->child_transaction_id }}</span>
                                    @if($log->child_type)
                                        <span class="doc-tag">{{ $log->child_type }}</span>
                                    @endif
                                </div>
                            </td>
                            <td style="text-align: center;">
                                <i class="fa-solid fa-arrow-right-long arrow-connector" title="appended to"></i>
                            </td>
                            <td>
                                <div class="doc-link-card">
                                    <span style="font-weight: 700; color: #334155;">{{ $log->parent_control ?? $log->parent_transaction_id }}</span>
                                    @if($log->parent_type)
                                        <span class="doc-tag">{{ $log->parent_type }}</span>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <div class="log-timestamp">
                                    {{ \Carbon\Carbon::parse($log->date_append)->format('Y-m-d H:i:s') }}
                                    <span class="time-ago">{{ \Carbon\Carbon::parse($log->date_append)->diffForHumans() }}</span>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <div class="empty-state">
                                    <i class="fa-solid fa-link-slash"></i>
                                    <h3>No Document Linkages Found</h3>
                                    <p>No document updates or append logs match your criteria.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Custom Pagination -->
        @if($logs->hasPages())
            <div class="pagination-container">
                <div class="pagination-info">
                    Showing {{ $logs->firstItem() ?? 0 }} to {{ $logs->lastItem() ?? 0 }} of {{ $logs->total() }} entries
                </div>
                <div class="pagination-links">
                    @if ($logs->onFirstPage())
                        <button type="button" class="pagination-btn" disabled>&laquo;</button>
                    @else
                        <button type="button" class="pagination-btn" wire:click="previousPage" wire:loading.attr="disabled">&laquo;</button>
                    @endif

                    @foreach ($logs->getUrlRange(max(1, $logs->currentPage() - 2), min($logs->lastPage(), $logs->currentPage() + 2)) as $page => $url)
                        @if ($page == $logs->currentPage())
                            <button type="button" class="pagination-btn active">{{ $page }}</button>
                        @else
                            <button type="button" class="pagination-btn" wire:click="gotoPage({{ $page }})" wire:loading.attr="disabled">{{ $page }}</button>
                        @endif
                    @endforeach

                    @if ($logs->hasMorePages())
                        <button type="button" class="pagination-btn" wire:click="nextPage" wire:loading.attr="disabled">&raquo;</button>
                    @else
                        <button type="button" class="pagination-btn" disabled>&raquo;</button>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>
