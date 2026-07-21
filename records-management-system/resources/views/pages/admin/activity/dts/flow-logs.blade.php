<?php
/**
 * Admin Console - DTS Transaction Flows Logs Volt Component
 * 
 * Lists audit history of predefined and custom flow configurations.
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Admin Console - DTS Flow Logs')] class extends Component {
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
        $query = \DB::table('admin_logs')
            ->leftJoin('account', 'admin_logs.admin_id', '=', 'account.id')
            ->leftJoin('account_details', 'account.id', '=', 'account_details.account_id')
            ->leftJoin('condition_key', 'account.account_role', '=', 'condition_key.id')
            ->select([
                'admin_logs.id',
                'admin_logs.changes',
                'admin_logs.when_changes',
                'account.username',
                'account_details.first_name',
                'account_details.last_name',
                'condition_key.key_name as role_name',
            ])
            ->where(function ($q) {
                $q->where('admin_logs.changes', 'like', '%flow%')
                  ->orWhere('admin_logs.changes', 'like', '%sequence%');
            });

        if ($this->search !== '') {
            $searchVal = '%' . $this->search . '%';
            $query->where(function ($q) use ($searchVal) {
                $q->where('admin_logs.changes', 'like', $searchVal)
                  ->orWhere('account.username', 'like', $searchVal)
                  ->orWhere('account_details.first_name', 'like', $searchVal)
                  ->orWhere('account_details.last_name', 'like', $searchVal);
            });
        }

        $logs = $query->orderBy('admin_logs.when_changes', 'desc')->paginate(15);

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
        .flow-log-desc {
            font-size: 13px;
            font-weight: 500;
            color: #1e293b;
        }
        .admin-meta {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .admin-name {
            font-weight: 600;
            color: #334155;
            font-size: 13px;
        }
        .admin-role {
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 600;
        }
    </style>
@endpush

<div class="activity-logs-container">
    <!-- Header -->
    <div class="page-header">
        <h1>Transaction Flows Logs</h1>
        <p>Audit historical records of predefined transaction flows, route updates, text file imports, and modifications.</p>
    </div>

    <!-- Controls Panel -->
    <div class="logs-controls-card">
        <div class="search-filter-group">
            <!-- Search Box -->
            <div class="search-box-wrapper">
                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                <input type="text" 
                       class="search-input" 
                       placeholder="Search by log content, admin username or name..." 
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
                        <th style="width: 10%">Log ID</th>
                        <th style="width: 50%">Change Action</th>
                        <th style="width: 25%">Added By</th>
                        <th style="width: 15%">Logged On</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        <tr wire:key="flow-log-{{ $log->id }}">
                            <td>
                                <span style="font-weight: 600; color: #64748b;">#{{ $log->id }}</span>
                            </td>
                            <td>
                                <span class="flow-log-desc">{{ $log->changes }}</span>
                            </td>
                            <td>
                                <div class="admin-meta">
                                    <span class="admin-name">
                                        {{ $log->first_name ? trim($log->first_name . ' ' . $log->last_name) : $log->username }}
                                    </span>
                                    @if($log->role_name)
                                        <span class="admin-role">{{ $log->role_name }}</span>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <div class="log-timestamp">
                                    {{ \Carbon\Carbon::parse($log->when_changes)->format('Y-m-d H:i:s') }}
                                    <span class="time-ago">{{ \Carbon\Carbon::parse($log->when_changes)->diffForHumans() }}</span>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4">
                                <div class="empty-state">
                                    <i class="fa-solid fa-route"></i>
                                    <h3>No Transaction Flow Logs Found</h3>
                                    <p>No transaction flow creations, updates, or import logs match your criteria.</p>
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
