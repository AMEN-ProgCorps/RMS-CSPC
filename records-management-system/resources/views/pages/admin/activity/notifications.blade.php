<?php
/**
 * Admin Console - Notification Logs Volt Component
 * 
 * Provides search, status filtering, subsystem filtering, and visibility filtering
 * for notification logs records in the notification_div database table.
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Admin Console - Notification Logs')] class extends Component {
    use WithPagination;

    /** @var string Real-time search query */
    public string $search = '';

    /** @var string Selected status filter */
    public string $statusFilter = '';

    /** @var string Selected subsystem filter */
    public string $subsystemFilter = '';

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if (!$perms || (!$perms->is_sadm && !$perms->can_access_activity_logs)) {
            $this->redirect(route('portal'));
            return;
        }
    }

    /** @var string Selected visibility filter */
    public string $visibilityFilter = '';

    /**
     * Component Lifecycle Hooks - reset page pagination on filter updates.
     */
    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingSubsystemFilter(): void
    {
        $this->resetPage();
    }

    public function updatingVisibilityFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Resets all search and filter properties.
     */
    public function clearFilters(): void
    {
        $this->search = '';
        $this->statusFilter = '';
        $this->subsystemFilter = '';
        $this->visibilityFilter = '';
        $this->resetPage();
    }

    /**
     * Fetch logs and pass them to the Blade template.
     */
    public function with(): array
    {
        $query = \DB::table('notification_div')
            ->leftJoin('notifications', 'notification_div.id', '=', 'notifications.id')
            ->leftJoin('notif_content', 'notifications.contents', '=', 'notif_content.id')
            ->leftJoin('subsystems', 'notif_content.system', '=', 'subsystems.subsystem_id')
            ->leftJoin('account', 'notification_div.account_rec', '=', 'account.id')
            ->leftJoin('account_details', 'account.id', '=', 'account_details.account_id')
            ->leftJoin('office', 'notifications.office', '=', 'office.office_code')
            ->select([
                'notification_div.id',
                'notification_div.status',
                'notification_div.processed_on',
                'notification_div.is_in_user_list',
                'notif_content.content',
                'subsystems.subsystem_name',
                'office.office_name',
                'notifications.office as office_code',
                'account.username',
                'account_details.first_name',
                'account_details.last_name',
            ]);

        // Search Filter
        if ($this->search !== '') {
            $searchVal = '%' . $this->search . '%';
            $query->where(function ($q) use ($searchVal) {
                $q->where('account.username', 'like', $searchVal)
                  ->orWhere('account_details.first_name', 'like', $searchVal)
                  ->orWhere('account_details.last_name', 'like', $searchVal)
                  ->orWhere('notif_content.content', 'like', $searchVal)
                  ->orWhere('office.office_name', 'like', $searchVal)
                  ->orWhere('notifications.office', 'like', $searchVal)
                  ->orWhere('subsystems.subsystem_name', 'like', $searchVal);
            });
        }

        // Status Filter
        if ($this->statusFilter !== '') {
            $query->where('notification_div.status', $this->statusFilter);
        }

        // Subsystem Filter
        if ($this->subsystemFilter !== '') {
            $query->where('notif_content.system', $this->subsystemFilter);
        }

        // Visibility Filter
        if ($this->visibilityFilter !== '') {
            $query->where('notification_div.is_in_user_list', $this->visibilityFilter === 'active' ? 1 : 0);
        }

        // Paginate records (15 per page)
        $logs = $query->orderBy('notification_div.processed_on', 'desc')->paginate(15);

        // Fetch subsystems list for filter dropdown
        $subsystems = \DB::table('subsystems')->orderBy('subsystem_name')->get();

        return [
            'logs' => $logs,
            'subsystems' => $subsystems,
        ];
    }
};
?>

@push('styles')
    @vite('resources/css/admin/activity_logs.css')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        .badge-read {
            background-color: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
        }
        .badge-unread {
            background-color: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
        }
        .badge-visible {
            background-color: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
        }
        .badge-cleared {
            background-color: #fff7ed;
            color: #c2410c;
            border: 1px solid #ffedd5;
        }
        .office-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            background-color: #f8fafc;
            color: #334155;
            border: 1px solid #e2e8f0;
        }
        .btn-clear-filters {
            height: 40px;
            box-sizing: border-box;
        }
    </style>
@endpush

<div class="activity-logs-container">
    <!-- Header -->
    <div class="page-header">
        <h1>Notification Dispatch & Action Logs</h1>
        <p>Monitor system alerts, recipient status checks, and user list clearance interactions based on <code>notification_div</code> schema.</p>
    </div>

    <!-- Controls Panel -->
    <div class="logs-controls-card">
        <div class="search-filter-group">
            <!-- Search Input -->
            <div class="search-box-wrapper">
                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                <input type="text" 
                       class="search-input" 
                       placeholder="Search by recipient, message, office..." 
                       wire:model.live="search">
            </div>

            <!-- Status Dropdown -->
            <select class="filter-select" wire:model.live="statusFilter">
                <option value="">All Statuses</option>
                <option value="read">Read</option>
                <option value="unread">Unread</option>
            </select>

            <!-- Subsystem Dropdown -->
            <select class="filter-select" wire:model.live="subsystemFilter">
                <option value="">All Subsystems</option>
                @foreach($subsystems as $subsystem)
                    <option value="{{ $subsystem->subsystem_id }}">{{ $subsystem->subsystem_name }}</option>
                @endforeach
            </select>

            <!-- Visibility Dropdown -->
            <select class="filter-select" wire:model.live="visibilityFilter">
                <option value="">All Visibilities</option>
                <option value="active">Active in List</option>
                <option value="cleared">Cleared/Deleted</option>
            </select>

            <!-- Clear Button -->
            @if($search !== '' || $statusFilter !== '' || $subsystemFilter !== '' || $visibilityFilter !== '')
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
                        <th style="width: 12%">Status</th>
                        <th style="width: 20%">Recipient</th>
                        <th style="width: 32%">Notification Details</th>
                        <th style="width: 15%">Office Scope</th>
                        <th style="width: 11%">Processed On</th>
                        <th style="width: 10%">List State</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        <tr wire:key="log-{{ $log->id }}-{{ $log->username }}">
                            <td>
                                @if($log->status === 'read')
                                    <span class="badge badge-read">
                                        <i class="fa-solid fa-circle-check"></i> Read
                                    </span>
                                @else
                                    <span class="badge badge-unread">
                                        <i class="fa-solid fa-circle-dot"></i> Unread
                                    </span>
                                @endif
                            </td>
                            <td>
                                <div class="admin-name-cell">
                                    @if($log->first_name || $log->last_name)
                                        <span class="name">{{ $log->first_name }} {{ $log->last_name }}</span>
                                        <span class="email-sub">{{ $log->username }}</span>
                                    @elseif($log->username)
                                        <span class="name">{{ $log->username }}</span>
                                        <span class="email-sub">No details available</span>
                                    @else
                                        <span class="name" style="color: #94a3b8; font-style: italic;">Unknown Account</span>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <div class="log-description">
                                    <span class="subsystem-badge" style="margin-bottom: 4px; display: inline-block;">
                                        <i class="fa-solid fa-laptop-code" style="margin-right: 4px;"></i> {{ $log->subsystem_name ?? 'System' }}
                                    </span>
                                    <div style="font-size: 13px; color: #1e293b; font-weight: 500;">
                                        {{ $log->content }}
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="office-badge">
                                    <i class="fa-solid fa-building" style="margin-right: 4px; color: #64748b;"></i>
                                    {{ $log->office_name ?? $log->office_code ?? 'All Offices' }}
                                </div>
                            </td>
                            <td>
                                <div class="log-timestamp">
                                    {{ \Carbon\Carbon::parse($log->processed_on)->format('Y-m-d H:i:s') }}
                                    <span class="time-ago">{{ \Carbon\Carbon::parse($log->processed_on)->diffForHumans() }}</span>
                                </div>
                            </td>
                            <td>
                                @if($log->is_in_user_list)
                                    <span class="badge badge-visible">
                                        <i class="fa-solid fa-eye"></i> Active
                                    </span>
                                @else
                                    <span class="badge badge-cleared">
                                        <i class="fa-solid fa-eye-slash"></i> Cleared
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <i class="fa-solid fa-bell-slash"></i>
                                    <h3>No Notification Logs Found</h3>
                                    <p>No database records match your search and filter criteria.</p>
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
                    {{-- Previous Page Button --}}
                    @if ($logs->onFirstPage())
                        <button type="button" class="pagination-btn" disabled>&laquo;</button>
                    @else
                        <button type="button" class="pagination-btn" wire:click="previousPage" wire:loading.attr="disabled">&laquo;</button>
                    @endif

                    {{-- Page Numbers --}}
                    @foreach ($logs->getUrlRange(max(1, $logs->currentPage() - 2), min($logs->lastPage(), $logs->currentPage() + 2)) as $page => $url)
                        @if ($page == $logs->currentPage())
                            <button type="button" class="pagination-btn active">{{ $page }}</button>
                        @else
                            <button type="button" class="pagination-btn" wire:click="gotoPage({{ $page }})" wire:loading.attr="disabled">{{ $page }}</button>
                        @endif
                    @endforeach

                    {{-- Next Page Button --}}
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
