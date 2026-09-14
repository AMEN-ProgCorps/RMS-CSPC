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
use Illuminate\Support\Facades\DB;

new #[Layout('layouts.admin')] #[Title('Admin Console - DTS Transactions Logs')] class extends Component {
    use WithPagination;

    /** @var string Real-time search query */
    public string $search = '';

    /** @var string Selected action/status filter */
    public string $statusFilter = '';

    /** @var string Selected office filter */
    public string $officeFilter = '';

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if (!$perms || (!$perms->is_sadm && !$perms->can_access_dts_admin && !$perms->can_dts_modify_docflow)) {
            $this->redirect(route('portal'));
            return;
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingOfficeFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->statusFilter = '';
        $this->officeFilter = '';
        $this->resetPage();
    }

    public function with(): array
    {
        $logsTbl = \Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs';
        $logTypesTbl = \Illuminate\Support\Facades\Schema::hasTable('dts_transaction_log_types') ? 'dts_transaction_log_types' : 'sub_document_tracking_system_logs_types';
        $accountTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_account') ? 'sys_account' : 'account';
        $accDetailsTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
        $officeTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';

        $query = DB::table($logsTbl)
            ->leftJoin($logTypesTbl, "{$logsTbl}.type", '=', "{$logTypesTbl}.type_id")
            ->leftJoin($accountTbl, "{$logsTbl}.performed_by", '=', "{$accountTbl}.id")
            ->leftJoin($accDetailsTbl, "{$accountTbl}.id", '=', "{$accDetailsTbl}.account_id")
            ->leftJoin($officeTbl, "{$logsTbl}.office_code", '=', "{$officeTbl}.office_code")
            ->leftJoin('dts_transaction_details', "{$logsTbl}.transaction_id", '=', 'dts_transaction_details.id')
            ->select([
                "{$logsTbl}.id",
                "{$logsTbl}.transaction_id",
                "{$logsTbl}.type",
                "{$logsTbl}.date_in",
                "{$logsTbl}.date_out",
                "{$logsTbl}.notes",
                "{$logTypesTbl}.type_label",
                "{$officeTbl}.office_name",
                "{$officeTbl}.office_code",
                "{$accountTbl}.username",
                "{$accDetailsTbl}.first_name",
                "{$accDetailsTbl}.last_name",
                'dts_transaction_details.control_number',
            ]);

        // Search Filter
        if ($this->search !== '') {
            $searchVal = '%' . trim($this->search) . '%';
            $query->where(function ($q) use ($searchVal, $logsTbl, $officeTbl, $accountTbl, $accDetailsTbl) {
                $q->where('dts_transaction_details.control_number', 'ilike', $searchVal)
                  ->orWhere("{$accountTbl}.username", 'ilike', $searchVal)
                  ->orWhere("{$accDetailsTbl}.first_name", 'ilike', $searchVal)
                  ->orWhere("{$accDetailsTbl}.last_name", 'ilike', $searchVal)
                  ->orWhere("{$officeTbl}.office_name", 'ilike', $searchVal)
                  ->orWhere("{$officeTbl}.office_code", 'ilike', $searchVal)
                  ->orWhere("{$logsTbl}.notes", 'ilike', $searchVal);
            });
        }

        // Status Filter
        if ($this->statusFilter !== '') {
            switch ($this->statusFilter) {
                case 'in_transit':
                    $query->whereNull("{$logsTbl}.date_in");
                    break;
                case 'holding':
                    $query->whereNotNull("{$logsTbl}.date_in")
                          ->whereNull("{$logsTbl}.date_out");
                    break;
                case 'forwarded':
                    $query->whereNotNull("{$logsTbl}.date_out");
                    break;
                case 'returned':
                    $query->where(function($sub) use ($logsTbl) {
                        $sub->where("{$logsTbl}.type", 'returned')
                            ->orWhere("{$logsTbl}.notes", 'like', '%revision%')
                            ->orWhere("{$logsTbl}.notes", 'like', '%returned%');
                    });
                    break;
                default:
                    $query->where("{$logsTbl}.type", $this->statusFilter);
                    break;
            }
        }

        // Office Filter
        if ($this->officeFilter !== '') {
            $query->where("{$logsTbl}.office_code", $this->officeFilter);
        }

        $logs = $query->orderBy("{$logsTbl}.id", 'desc')->paginate(15);

        // Calculate Overview Statistics
        $stats = [
            'total'      => DB::table($logsTbl)->count(),
            'in_transit' => DB::table($logsTbl)->whereNull('date_in')->count(),
            'holding'    => DB::table($logsTbl)->whereNotNull('date_in')->whereNull('date_out')->count(),
            'forwarded'  => DB::table($logsTbl)->whereNotNull('date_out')->count(),
        ];

        // Office List for Filter
        $offices = DB::table($officeTbl)
            ->where('is_active', true)
            ->whereNotIn('office_code', ['ORIGIN', '[H]', '[HUB]'])
            ->orderBy('office_name', 'asc')
            ->get();

        return [
            'logs'    => $logs,
            'stats'   => $stats,
            'offices' => $offices,
        ];
    }
};
?>

@push('styles')
    @vite('resources/css/admin/activity_logs.css')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        .badge-type {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 11.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.02em;
            white-space: nowrap;
        }
        .badge-created { background-color: rgba(37, 99, 235, 0.1); color: #1d4ed8; border: 1px solid rgba(37, 99, 235, 0.25); }
        .badge-in-transit { background-color: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
        .badge-holding { background-color: #f0f9ff; color: #0369a1; border: 1px solid #bae6fd; }
        .badge-forwarded { background-color: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .badge-returned { background-color: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .badge-default { background-color: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; }

        .log-notes-cell {
            font-size: 12.5px;
            color: #334155;
            min-width: 220px;
            max-width: 320px;
            line-height: 1.45;
            word-break: normal;
            overflow-wrap: break-word;
            white-space: normal;
        }

        .actor-badge-sub {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 10.5px;
            font-weight: 600;
            padding: 2px 6px;
            border-radius: 4px;
            margin-top: 3px;
            width: fit-content;
            background: #fffbeb;
            color: #b45309;
            border: 1px solid #fde68a;
        }

        .control-num-text {
            font-weight: 700;
            color: #003699;
            font-family: monospace;
            font-size: 13.5px;
        }

        .control-num-id {
            font-size: 10.5px;
            color: #64748b;
        }

        .office-code-tag {
            color: #003699;
            font-weight: 700;
        }

        .time-label {
            font-weight: 700;
            color: #475569;
        }

        .time-val {
            font-weight: 600;
            color: #0f172a;
        }

        .time-pending {
            color: #64748b;
            font-style: italic;
        }

        .badge-pending {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 1px 6px;
            border-radius: 4px;
            font-size: 10.5px;
            font-weight: 600;
            background: #fffbeb;
            color: #b45309;
            border: 1px solid #fde68a;
        }

        /* Dark Mode Overrides */
        [data-theme="dark"] .badge-created { background-color: rgba(37, 99, 235, 0.15); color: #60a5fa; border-color: rgba(37, 99, 235, 0.3); }
        [data-theme="dark"] .badge-in-transit { background-color: rgba(245, 158, 11, 0.15); color: #fbbf24; border-color: rgba(245, 158, 11, 0.3); }
        [data-theme="dark"] .badge-holding { background-color: rgba(14, 165, 233, 0.15); color: #38bdf8; border-color: rgba(14, 165, 233, 0.3); }
        [data-theme="dark"] .badge-forwarded { background-color: rgba(16, 185, 129, 0.15); color: #34d399; border-color: rgba(16, 185, 129, 0.3); }
        [data-theme="dark"] .badge-returned { background-color: rgba(239, 68, 68, 0.15); color: #f87171; border-color: rgba(239, 68, 68, 0.3); }
        [data-theme="dark"] .badge-default { background-color: #0f172a; color: #94a3b8; border-color: #334155; }
        [data-theme="dark"] .log-notes-cell { color: #cbd5e1; }
        [data-theme="dark"] .actor-badge-sub { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border-color: rgba(245, 158, 11, 0.3); }
        [data-theme="dark"] .control-num-text { color: #38bdf8; }
        [data-theme="dark"] .control-num-id { color: #94a3b8; }
        [data-theme="dark"] .office-code-tag { color: #60a5fa; }
        [data-theme="dark"] .time-label { color: #94a3b8; }
        [data-theme="dark"] .time-val { color: #f8fafc; }
        [data-theme="dark"] .time-pending { color: #64748b; }
        [data-theme="dark"] .badge-pending { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border-color: rgba(245, 158, 11, 0.3); }
    </style>
@endpush

<div class="activity-logs-container" wire:poll.10s.keep-alive>
    <!-- Header Section -->
    <div class="page-header">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
            <div>
                <h1>Document Tracking - Transaction Logs</h1>
                <p>Monitor document routing steps, forwarding pathways, and receipt confirmations inside the tracking system.</p>
            </div>
            <div>
                <span class="live-status-pill">
                    <span class="pulse-dot"></span> Live Tracking Audit
                </span>
            </div>
        </div>
    </div>

    <!-- Overview Stats Cards Grid -->
    <div class="stats-overview-grid">
        <div class="stat-card blue">
            <div class="stat-icon"><i class="fa-solid fa-route"></i></div>
            <div class="stat-details">
                <span class="stat-value">{{ number_format($stats['total']) }}</span>
                <span class="stat-label">Total Routing Logs</span>
            </div>
        </div>

        <div class="stat-card orange">
            <div class="stat-icon"><i class="fa-solid fa-hourglass-half"></i></div>
            <div class="stat-details">
                <span class="stat-value">{{ number_format($stats['in_transit']) }}</span>
                <span class="stat-label">In Transit / Pending</span>
            </div>
        </div>

        <div class="stat-card blue">
            <div class="stat-icon"><i class="fa-solid fa-inbox"></i></div>
            <div class="stat-details">
                <span class="stat-value">{{ number_format($stats['holding']) }}</span>
                <span class="stat-label">Received / Holding</span>
            </div>
        </div>

        <div class="stat-card green">
            <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
            <div class="stat-details">
                <span class="stat-value">{{ number_format($stats['forwarded']) }}</span>
                <span class="stat-label">Released / Forwarded</span>
            </div>
        </div>
    </div>

    <!-- Controls Panel Card -->
    <div class="logs-controls-card">
        <div class="search-filter-group">
            <!-- Search Input -->
            <div class="search-box-wrapper">
                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                <input type="text" 
                       class="search-input" 
                       placeholder="Search control number, user, office, notes..." 
                       wire:model.live.debounce.300ms="search">
            </div>

            <!-- Action / Status Filter -->
            <select class="filter-select" wire:model.live="statusFilter">
                <option value="">All Movement Statuses</option>
                <option value="in_transit">⏳ In Transit (Awaiting Receipt)</option>
                <option value="holding">📥 Received (Currently Holding)</option>
                <option value="forwarded">📤 Released / Forwarded</option>
                <option value="returned">↺ Returned for Revision</option>
            </select>

            <!-- Office Filter -->
            <select class="filter-select" wire:model.live="officeFilter" style="max-width: 240px;">
                <option value="">All Office Scopes</option>
                @foreach($offices as $off)
                    <option value="{{ $off->office_code }}">{{ $off->office_name }} ({{ $off->office_code }})</option>
                @endforeach
            </select>

            <!-- Clear Filters Button -->
            @if($search !== '' || $statusFilter !== '' || $officeFilter !== '')
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
                        <th style="width: 14%">Control Number</th>
                        <th style="width: 15%">Movement Status</th>
                        <th style="width: 19%">Office Scope</th>
                        <th style="width: 20%">Action Performer</th>
                        <th style="width: 16%">Time In / Out</th>
                        <th style="width: 16%">Notes & Particulars</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        @php
                            $isInTransit = is_null($log->date_in);
                            $isHolding = !is_null($log->date_in) && is_null($log->date_out);
                            $isReleased = !is_null($log->date_out);
                            $isReturned = ($log->type === 'returned') || str_contains(strtolower($log->notes ?? ''), 'revision') || str_contains(strtolower($log->notes ?? ''), 'returned');

                            if ($isInTransit) {
                                $badgeClass = 'badge-in-transit';
                                $badgeIcon = 'fa-hourglass-half';
                                $badgeLabel = 'In Transit';
                            } elseif ($isReturned) {
                                $badgeClass = 'badge-returned';
                                $badgeIcon = 'fa-rotate-left';
                                $badgeLabel = 'Returned for Revision';
                            } elseif ($isHolding) {
                                $badgeClass = 'badge-holding';
                                $badgeIcon = 'fa-inbox';
                                $badgeLabel = 'Received / Held';
                            } elseif ($isReleased) {
                                $badgeClass = 'badge-forwarded';
                                $badgeIcon = 'fa-paper-plane';
                                $badgeLabel = 'Released / Forwarded';
                            } else {
                                $badgeClass = 'badge-default';
                                $badgeIcon = 'fa-circle-info';
                                $badgeLabel = ucfirst($log->type_label ?? ($log->type ?: 'Log'));
                            }
                        @endphp
                        <tr wire:key="dts-log-{{ $log->id }}">
                            <td>
                                <div style="display: flex; flex-direction: column; gap: 2px;">
                                    <span class="control-num-text">{{ $log->control_number ?? $log->transaction_id }}</span>
                                    <span class="control-num-id">ID #{{ $log->id }}</span>
                                </div>
                            </td>
                            <td>
                                <span class="badge-type {{ $badgeClass }}">
                                    <i class="fa-solid {{ $badgeIcon }}"></i> {{ $badgeLabel }}
                                </span>
                            </td>
                            <td>
                                <div class="admin-name-cell">
                                    <span class="name" style="font-size: 13px; font-weight: 700;">{{ $log->office_name ?? ($log->office_code ?: 'N/A') }}</span>
                                    @if($log->office_code)
                                        <span class="email-sub" style="font-size: 11.5px;">Code: <strong class="office-code-tag">{{ $log->office_code }}</strong></span>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <div class="admin-user-row">
                                    <div class="user-avatar-circle" style="width: 32px; height: 32px; font-size: 12px;">
                                        @if($log->first_name)
                                            {{ strtoupper(substr($log->first_name, 0, 1)) }}
                                        @elseif($log->username)
                                            {{ strtoupper(substr($log->username, 0, 1)) }}
                                        @else
                                            ?
                                        @endif
                                    </div>
                                    <div class="admin-name-cell">
                                        @if($isInTransit)
                                            <span class="name" style="font-weight: 600; font-size: 13px;">{{ $log->first_name ? trim($log->first_name . ' ' . $log->last_name) : ($log->username ?: 'Staff') }}</span>
                                            <span class="actor-badge-sub">
                                                <i class="fa-solid fa-paper-plane" style="font-size: 9px;"></i> Dispatched by Sender
                                            </span>
                                            <span class="email-sub" style="font-size: 11px; margin-top: 2px;">(Awaiting receipt at {{ $log->office_code }})</span>
                                        @else
                                            @if($log->first_name || $log->last_name)
                                                <span class="name" style="font-weight: 600; font-size: 13px;">{{ $log->first_name }} {{ $log->last_name }}</span>
                                                <span class="email-sub" style="font-size: 11.5px;">@ {{ $log->username }}</span>
                                            @elseif($log->username)
                                                <span class="name" style="font-weight: 600; font-size: 13px;">{{ $log->username }}</span>
                                                <span class="email-sub" style="font-size: 11.5px;">System Account</span>
                                            @else
                                                <span class="name" style="color: #94a3b8; font-style: italic; font-size: 12.5px;">System Automatic</span>
                                            @endif
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div style="display: flex; flex-direction: column; gap: 6px;">
                                    <!-- Time In -->
                                    <div class="log-timestamp">
                                        <div style="display: flex; align-items: center; gap: 4px; font-size: 11px;">
                                            <span class="time-label">IN:</span>
                                            @if($log->date_in)
                                                <span class="time-val">{{ \Carbon\Carbon::parse($log->date_in)->format('M d, Y h:i A') }}</span>
                                            @else
                                                <span class="time-pending">Not yet received</span>
                                            @endif
                                        </div>
                                    </div>
                                    <!-- Time Out -->
                                    <div class="log-timestamp">
                                        <div style="display: flex; align-items: center; gap: 4px; font-size: 11px;">
                                            <span class="time-label">OUT:</span>
                                            @if($log->date_out)
                                                <span class="time-val">{{ \Carbon\Carbon::parse($log->date_out)->format('M d, Y h:i A') }}</span>
                                            @else
                                                <span class="badge-pending">
                                                    <i class="fa-solid fa-clock" style="font-size: 9px;"></i> Pending
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="log-notes-cell">
                                    {{ $log->notes ?: 'No description provided.' }}
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <i class="fa-solid fa-route"></i>
                                    <h3>No Transaction Logs Found</h3>
                                    <p>No document tracking movement logs match your search and filter criteria.</p>
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
