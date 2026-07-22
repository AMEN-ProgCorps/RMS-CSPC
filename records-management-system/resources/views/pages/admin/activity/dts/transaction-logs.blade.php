<?php
/**
 * Admin Console - DTS Transactions Logs Volt Component
 * 
 * Lists audit history of document transactions movements (created, forwarded, received, returned, completed).
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Admin Console - DTS Transactions Logs')] class extends Component {
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
     * Reset pagination on search input.
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
        $query = \DB::table('sub_document_tracking_system_logs')
            ->leftJoin('sub_document_tracking_system_logs_types', 'sub_document_tracking_system_logs.type', '=', 'sub_document_tracking_system_logs_types.type_id')
            ->leftJoin('account', 'sub_document_tracking_system_logs.performed_by', '=', 'account.id')
            ->leftJoin('account_details', 'account.id', '=', 'account_details.account_id')
            ->leftJoin('office', 'sub_document_tracking_system_logs.office_code', '=', 'office.office_code')
            ->leftJoin('dts_transaction_details', 'sub_document_tracking_system_logs.transaction_id', '=', 'dts_transaction_details.id')
            ->select([
                'sub_document_tracking_system_logs.id',
                'sub_document_tracking_system_logs.transaction_id',
                'sub_document_tracking_system_logs.date_in',
                'sub_document_tracking_system_logs.date_out',
                'sub_document_tracking_system_logs.notes',
                'sub_document_tracking_system_logs_types.type_label',
                'office.office_name',
                'office.office_code',
                'account.username',
                'account_details.first_name',
                'account_details.last_name',
                'dts_transaction_details.control_number',
            ]);

        if ($this->search !== '') {
            $searchVal = '%' . $this->search . '%';
            $query->where(function ($q) use ($searchVal) {
                $q->where('dts_transaction_details.control_number', 'like', $searchVal)
                  ->orWhere('account.username', 'like', $searchVal)
                  ->orWhere('account_details.first_name', 'like', $searchVal)
                  ->orWhere('account_details.last_name', 'like', $searchVal)
                  ->orWhere('office.office_name', 'like', $searchVal)
                  ->orWhere('sub_document_tracking_system_logs.notes', 'like', $searchVal)
                  ->orWhere('sub_document_tracking_system_logs_types.type_label', 'like', $searchVal);
            });
        }

        $logs = $query->orderBy('sub_document_tracking_system_logs.id', 'desc')->paginate(15);

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
        .badge-type {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11.5px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge-created { background-color: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .badge-forwarded { background-color: #fef3c7; color: #d97706; border: 1px solid #fde68a; }
        .badge-received { background-color: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .badge-returned { background-color: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .badge-completed { background-color: #f0fdf4; color: #16a34a; border: 1px solid #dcfce7; }
        .badge-default { background-color: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
    </style>
@endpush

<div class="activity-logs-container">
    <!-- Header -->
    <div class="page-header">
        <h1>Document Tracking - Transaction Logs</h1>
        <p>Monitor document routing steps, forwarding pathways, and receipt confirmations inside the tracking system.</p>
    </div>

    <!-- Controls Panel -->
    <div class="logs-controls-card">
        <div class="search-filter-group">
            <!-- Search Input -->
            <div class="search-box-wrapper">
                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                <input type="text" 
                       class="search-input" 
                       placeholder="Search by control number, user, office..." 
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
                        <th style="width: 15%">Control Number</th>
                        <th style="width: 12%">Action</th>
                        <th style="width: 20%">Performed By</th>
                        <th style="width: 18%">Office Scope</th>
                        <th style="width: 15%">Time In</th>
                        <th style="width: 15%">Time Out</th>
                        <th style="width: 15%">Notes</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        @php
                            $actionLabel = $log->type_label ?? 'Log';
                            $badgeClass = match (strtolower($actionLabel)) {
                                'created' => 'badge-created',
                                'forwarded' => 'badge-forwarded',
                                'received' => 'badge-received',
                                'returned for revision' => 'badge-returned',
                                'completed' => 'badge-completed',
                                default => 'badge-default',
                            };
                        @endphp
                        <tr wire:key="dts-log-{{ $log->id }}">
                            <td>
                                <span style="font-weight: 700; color: #003699;">{{ $log->control_number ?? $log->transaction_id }}</span>
                            </td>
                            <td>
                                <span class="badge-type {{ $badgeClass }}">
                                    {{ $actionLabel }}
                                </span>
                            </td>
                            <td>
                                <div class="admin-name-cell">
                                    @if($log->first_name || $log->last_name)
                                        <span class="name">{{ $log->first_name }} {{ $log->last_name }}</span>
                                        <span class="email-sub">{{ $log->username }}</span>
                                    @else
                                        <span class="name">{{ $log->username ?? 'System Process' }}</span>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <div class="admin-name-cell">
                                    <span class="name" style="font-size: 13px;">{{ $log->office_name ?? 'N/A' }}</span>
                                    @if($log->office_code)
                                        <span class="email-sub">Code: {{ $log->office_code }}</span>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <span class="log-timestamp">
                                    {{ $log->date_in ? \Carbon\Carbon::parse($log->date_in)->format('Y-m-d H:i:s') : 'N/A' }}
                                    @if($log->date_in)
                                        <span class="time-ago">{{ \Carbon\Carbon::parse($log->date_in)->diffForHumans() }}</span>
                                    @endif
                                </span>
                            </td>
                            <td>
                                <span class="log-timestamp">
                                    {{ $log->date_out ? \Carbon\Carbon::parse($log->date_out)->format('Y-m-d H:i:s') : 'Pending / Ongoing' }}
                                    @if($log->date_out)
                                        <span class="time-ago">{{ \Carbon\Carbon::parse($log->date_out)->diffForHumans() }}</span>
                                    @endif
                                </span>
                            </td>
                            <td>
                                <div class="log-description" style="font-size: 12.5px; color: #475569;">
                                    {{ $log->notes ?: 'No description' }}
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <div class="empty-state">
                                    <i class="fa-solid fa-route"></i>
                                    <h3>No Transaction Logs Found</h3>
                                    <p>No routing entries were found matching your search term.</p>
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
