<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;

new #[Layout('layouts.admin')] #[Title('Admin Console - DCS Activity Logs')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $actionFilter = '';
    public string $dateFilter = '';
    public string $officeFilter = '';

    private function dcsSystemId(): int
    {
        return (int) DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems')
            ->where('subsystem_name', 'Document Control System')
            ->value('subsystem_id');
    }

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if (
            !$perms
            || (
                !$perms->is_sadm
                && !($perms->can_access_activity_logs ?? false)
                && !($perms->can_access_dcs_admin ?? false)
            )
        ) {
            $this->redirect(route('portal'));
            return;
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingActionFilter(): void
    {
        $this->resetPage();
    }

    public function updatingDateFilter(): void
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
        $this->actionFilter = '';
        $this->dateFilter = '';
        $this->officeFilter = '';
        $this->resetPage();
    }

    public function with(): array
    {
        $systemId = $this->dcsSystemId();

        $adminLogsTable = \Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs')
            ? 'sys_admin_logs'
            : 'admin_logs';

        if ($systemId < 1 || !\Illuminate\Support\Facades\Schema::hasTable($adminLogsTable)) {
            return [
                'logs' => new \Illuminate\Pagination\LengthAwarePaginator([], 0, 15),
                'stats' => ['total' => 0, 'views' => 0, 'documents' => 0, 'office_intake' => 0, 'blocked' => 0, 'other' => 0],
                'offices' => collect(),
                'tableReady' => false,
            ];
        }

        $accountTable = \Illuminate\Support\Facades\Schema::hasTable('sys_account') ? 'sys_account' : 'account';
        $accountDetailsTable = \Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
        $officeTable = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';

        $base = DB::table($adminLogsTable)->where('what_system', $systemId);

        $query = DB::table($adminLogsTable . ' as admin_logs')
            ->leftJoin($accountTable . ' as account', 'admin_logs.admin_id', '=', 'account.id')
            ->leftJoin($accountDetailsTable . ' as account_details', 'account.id', '=', 'account_details.account_id')
            ->leftJoin($officeTable . ' as office', 'account_details.office_id', '=', 'office.id')
            ->where('admin_logs.what_system', $systemId)
            ->select([
                'admin_logs.*',
                'account.username',
                'account_details.email',
                'account_details.first_name',
                'account_details.last_name',
                'office.office_name',
                'office.office_code',
            ]);

        if ($this->search !== '') {
            $s = '%' . $this->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('admin_logs.changes', 'like', $s)
                    ->orWhere('account.username', 'like', $s)
                    ->orWhere('account_details.first_name', 'like', $s)
                    ->orWhere('account_details.last_name', 'like', $s)
                    ->orWhere('office.office_name', 'like', $s)
                    ->orWhere('office.office_code', 'like', $s);
            });
        }

        if ($this->actionFilter !== '') {
            if ($this->actionFilter === 'calendar') {
                $query->where('admin_logs.changes', 'like', '%calendar%');
            } elseif ($this->actionFilter === 'blocked') {
                $query->where('admin_logs.changes', 'like', 'Blocked DCS access%');
            } else {
                $query->where('admin_logs.changes', 'like', $this->actionFilter . '%');
            }
        }

        if ($this->officeFilter !== '') {
            $query->where('office.office_code', $this->officeFilter);
        }

        if ($this->dateFilter !== '') {
            $query->whereDate('admin_logs.when_changes', $this->dateFilter);
        }

        $stats = [
            'total' => (clone $base)->count(),
            'views' => (clone $base)->where(function ($q) {
                $q->where('changes', 'like', 'Opened %')
                    ->orWhere('changes', 'like', 'Viewed %')
                    ->orWhere('changes', 'like', 'Printed %')
                    ->orWhere('changes', 'like', 'Accessed DCS%');
            })->count(),
            'documents' => (clone $base)->where(function ($q) {
                $q->where('changes', 'like', 'Registered document%')
                    ->orWhere('changes', 'like', 'Updated document%')
                    ->orWhere('changes', 'like', 'Deleted document%')
                    ->orWhere('changes', 'like', 'Restored document%')
                    ->orWhere('changes', 'like', 'Applied stamp%')
                    ->orWhere('changes', 'like', 'Removed stamp%');
            })->count(),
            'office_intake' => (clone $base)->where(function ($q) {
                $q->where('changes', 'like', 'Created office DRF%')
                    ->orWhere('changes', 'like', 'Created office DCN%');
            })->count(),
            'blocked' => (clone $base)->where('changes', 'like', 'Blocked DCS access%')->count(),
            'other' => (clone $base)->where(function ($q) {
                $q->where('changes', 'like', 'Exported %')
                    ->orWhere('changes', 'like', 'DCS settings:%')
                    ->orWhere('changes', 'like', '%calendar%')
                    ->orWhere('changes', 'like', 'Saved OPCR%')
                    ->orWhere('changes', 'like', '%syllabi monitoring%')
                    ->orWhere('changes', 'like', '%report template%');
            })->count(),
        ];

        $offices = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')
            ->where('is_active', true)
            ->orderBy('office_name')
            ->get(['office_code', 'office_name']);

        return [
            'logs' => $query->orderByDesc('admin_logs.when_changes')->paginate(15),
            'stats' => $stats,
            'offices' => $offices,
            'tableReady' => true,
        ];
    }
};
?>

@push('styles')
    @vite('resources/css/admin/activity_logs.css')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        .action-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 10px; border-radius: 20px;
            font-size: 11.5px; font-weight: 600; white-space: nowrap;
        }
        .action-badge.register { background: rgba(16,185,129,0.12); color: #059669; }
        .action-badge.update { background: rgba(245,158,11,0.12); color: #d97706; }
        .action-badge.stamp { background: rgba(99,102,241,0.12); color: #4f46e5; }
        .action-badge.delete { background: rgba(239,68,68,0.12); color: #dc2626; }
        .action-badge.restore { background: rgba(14,165,233,0.12); color: #0284c7; }
        .action-badge.access { background: rgba(59,130,246,0.12); color: #2563eb; }
        .action-badge.export { background: rgba(20,184,166,0.12); color: #0d9488; }
        .action-badge.settings { background: rgba(107,114,128,0.12); color: #4b5563; }
        .action-badge.intake { background: rgba(168,85,247,0.12); color: #7e22ce; }
        .action-badge.blocked { background: rgba(239,68,68,0.12); color: #b91c1c; }
        .action-badge.default-badge { background: rgba(100,116,139,0.12); color: #475569; }
        .subsystem-ref {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 12px; font-weight: 700; color: #1d4ed8;
            background: #eff6ff; border: 1px solid #bfdbfe;
            padding: 4px 10px; border-radius: 999px;
        }
    </style>
@endpush

<div class="activity-logs-container">
    <div class="page-header">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
            <div>
                <h1>DCS Activity Logs</h1>
                <p>Track who accessed DCS, which office they belong to, and what pages or actions they performed.</p>
            </div>
            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                <span class="subsystem-ref">
                    <i class="fa-solid fa-link"></i> Document Control System
                </span>
                <span class="live-status-pill">
                    <span class="pulse-dot"></span> Live Audit Feed
                </span>
            </div>
        </div>
    </div>

    <div class="stats-overview-grid">
        <div class="stat-card blue">
            <div class="stat-icon"><i class="fa-solid fa-list-check"></i></div>
            <div class="stat-details">
                <span class="stat-value">{{ number_format($stats['total']) }}</span>
                <span class="stat-label">Total DCS Logs</span>
            </div>
        </div>
        <div class="stat-card blue">
            <div class="stat-icon"><i class="fa-solid fa-door-open"></i></div>
            <div class="stat-details">
                <span class="stat-value">{{ number_format($stats['views']) }}</span>
                <span class="stat-label">Page Views</span>
            </div>
        </div>
        <div class="stat-card green">
            <div class="stat-icon"><i class="fa-solid fa-file-circle-plus"></i></div>
            <div class="stat-details">
                <span class="stat-value">{{ number_format($stats['documents']) }}</span>
                <span class="stat-label">Document Actions</span>
            </div>
        </div>
        <div class="stat-card orange">
            <div class="stat-icon"><i class="fa-solid fa-building"></i></div>
            <div class="stat-details">
                <span class="stat-value">{{ number_format($stats['office_intake']) }}</span>
                <span class="stat-label">Office DRF/DCN</span>
            </div>
        </div>
        <div class="stat-card red">
            <div class="stat-icon"><i class="fa-solid fa-ban"></i></div>
            <div class="stat-details">
                <span class="stat-value">{{ number_format($stats['blocked']) }}</span>
                <span class="stat-label">Blocked Access</span>
            </div>
        </div>
        <div class="stat-card red">
            <div class="stat-icon"><i class="fa-solid fa-sliders"></i></div>
            <div class="stat-details">
                <span class="stat-value">{{ number_format($stats['other']) }}</span>
                <span class="stat-label">Exports &amp; Settings</span>
            </div>
        </div>
    </div>

    @if(!$tableReady)
        <div class="logs-table-card" style="padding: 48px; text-align: center; color: #94a3b8;">
            Shared admin activity log is not available, or Document Control System is missing from subsystems.
        </div>
    @else
        <div class="logs-controls-card">
            <div class="search-filter-group" style="flex-wrap: wrap; gap: 10px;">
                <div class="search-box-wrapper">
                    <i class="fa-solid fa-magnifying-glass search-icon"></i>
                    <input type="text"
                           class="search-input"
                           placeholder="Search changes, user, or office..."
                           wire:model.live.debounce.300ms="search">
                </div>

                <select class="filter-select" wire:model.live="actionFilter">
                    <option value="">All Action Types</option>
                    <option value="Opened ">Page Opened</option>
                    <option value="Viewed ">Page Viewed</option>
                    <option value="Printed ">Printed</option>
                    <option value="Registered document">Registered Document</option>
                    <option value="Updated document">Updated Document</option>
                    <option value="Deleted document">Deleted Document</option>
                    <option value="Restored document">Restored Document</option>
                    <option value="Applied stamp">Applied Stamp</option>
                    <option value="Removed stamp">Removed Stamp</option>
                    <option value="Created office DRF">Created Office DRF</option>
                    <option value="Created office DCN">Created Office DCN</option>
                    <option value="blocked">Blocked Access</option>
                    <option value="Exported ">Exported</option>
                    <option value="DCS settings:">Settings Change</option>
                    <option value="calendar">Calendar</option>
                </select>

                <select class="filter-select" wire:model.live="officeFilter" title="Filter by office">
                    <option value="">All Offices</option>
                    @foreach($offices as $off)
                        <option value="{{ $off->office_code }}">{{ $off->office_name }}</option>
                    @endforeach
                </select>

                <input type="date" class="filter-select" wire:model.live="dateFilter" title="Filter by date" style="min-width: 150px;">

                @if($search !== '' || $actionFilter !== '' || $dateFilter !== '' || $officeFilter !== '')
                    <button type="button" class="btn-clear-filters" wire:click="clearFilters">
                        <i class="fa-solid fa-filter-circle-xmark"></i> Clear Filters
                    </button>
                @endif
            </div>
        </div>

        <div class="logs-table-card">
            <div class="table-responsive">
                <table class="logs-table">
                    <thead>
                        <tr>
                            <th style="width: 16%">TIMESTAMP</th>
                            <th style="width: 18%">USER</th>
                            <th style="width: 18%">OFFICE</th>
                            <th style="width: 14%">ACTION</th>
                            <th style="width: 34%">CHANGES</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($logs as $log)
                            @php
                                $fullName = trim(($log->first_name ?? '') . ' ' . ($log->last_name ?? ''));
                                $changes = (string) ($log->changes ?? '');
                                $badgeClass = 'default-badge';
                                $actionIcon = 'fa-bolt';
                                $actionLabel = 'DCS Activity';
                                if (str_starts_with($changes, 'Opened ') || str_starts_with($changes, 'Accessed DCS')) {
                                    $badgeClass = 'access'; $actionIcon = 'fa-door-open'; $actionLabel = 'Opened';
                                } elseif (str_starts_with($changes, 'Viewed ')) {
                                    $badgeClass = 'access'; $actionIcon = 'fa-eye'; $actionLabel = 'Viewed';
                                } elseif (str_starts_with($changes, 'Printed ')) {
                                    $badgeClass = 'access'; $actionIcon = 'fa-print'; $actionLabel = 'Printed';
                                } elseif (str_starts_with($changes, 'Registered document')) {
                                    $badgeClass = 'register'; $actionIcon = 'fa-file-circle-plus'; $actionLabel = 'Registered';
                                } elseif (str_starts_with($changes, 'Updated document')) {
                                    $badgeClass = 'update'; $actionIcon = 'fa-pen-to-square'; $actionLabel = 'Updated';
                                } elseif (str_starts_with($changes, 'Deleted document') || str_contains(strtolower($changes), 'deleted calendar')) {
                                    $badgeClass = 'delete'; $actionIcon = 'fa-trash-can'; $actionLabel = 'Deleted';
                                } elseif (str_starts_with($changes, 'Restored document')) {
                                    $badgeClass = 'restore'; $actionIcon = 'fa-rotate-left'; $actionLabel = 'Restored';
                                } elseif (str_starts_with($changes, 'Applied stamp')) {
                                    $badgeClass = 'stamp'; $actionIcon = 'fa-stamp'; $actionLabel = 'Stamp Applied';
                                } elseif (str_starts_with($changes, 'Removed stamp')) {
                                    $badgeClass = 'stamp'; $actionIcon = 'fa-eraser'; $actionLabel = 'Stamp Removed';
                                } elseif (str_starts_with($changes, 'Created office DRF') || str_starts_with($changes, 'Created office DCN')) {
                                    $badgeClass = 'intake'; $actionIcon = 'fa-building'; $actionLabel = 'Office Intake';
                                } elseif (str_starts_with($changes, 'Blocked DCS access')) {
                                    $badgeClass = 'blocked'; $actionIcon = 'fa-ban'; $actionLabel = 'Blocked';
                                } elseif (str_starts_with($changes, 'Exported ')) {
                                    $badgeClass = 'export'; $actionIcon = 'fa-file-export'; $actionLabel = 'Exported';
                                } elseif (str_starts_with($changes, 'DCS settings:')) {
                                    $badgeClass = 'settings'; $actionIcon = 'fa-sliders'; $actionLabel = 'Settings';
                                } elseif (str_contains(strtolower($changes), 'calendar')) {
                                    $badgeClass = 'settings'; $actionIcon = 'fa-calendar'; $actionLabel = 'Calendar';
                                } elseif (str_starts_with($changes, 'Saved OPCR') || str_contains(strtolower($changes), 'syllabi monitoring')) {
                                    $badgeClass = 'update'; $actionIcon = 'fa-chart-line'; $actionLabel = 'Report Data';
                                } elseif (str_contains(strtolower($changes), 'report template')) {
                                    $badgeClass = 'settings'; $actionIcon = 'fa-file-pdf'; $actionLabel = 'Template';
                                }
                            @endphp
                            <tr>
                                <td>
                                    <div style="font-size: 13px; font-weight: 500; color: var(--text-primary, #1e293b);">
                                        {{ \Carbon\Carbon::parse($log->when_changes)->timezone('Asia/Manila')->format('M d, Y') }}
                                    </div>
                                    <div style="font-size: 11.5px; color: var(--text-secondary, #64748b);">
                                        {{ \Carbon\Carbon::parse($log->when_changes)->timezone('Asia/Manila')->format('h:i:s A') }}
                                    </div>
                                </td>
                                <td>
                                    <div style="font-weight: 600; font-size: 13.5px;">
                                        {{ $fullName ?: ($log->username ?? 'Unknown') }}
                                    </div>
                                    @if($log->username)
                                        <div style="font-size: 11.5px; color: var(--text-secondary, #64748b);">
                                            {{ '@' . $log->username }}
                                        </div>
                                    @endif
                                    <div style="font-size: 11px; color: #94a3b8;">
                                        ID: {{ $log->admin_id }}
                                    </div>
                                </td>
                                <td>
                                    <div style="font-weight: 600; font-size: 13px;">
                                        {{ $log->office_name ?: 'Unassigned' }}
                                    </div>
                                    @if($log->office_code)
                                        <div style="font-size: 11.5px; color: var(--text-secondary, #64748b);">
                                            {{ $log->office_code }}
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <span class="action-badge {{ $badgeClass }}">
                                        <i class="fa-solid {{ $actionIcon }}" style="font-size: 10px;"></i>
                                        {{ $actionLabel }}
                                    </span>
                                </td>
                                <td style="font-size: 13px; color: #334155; line-height: 1.45;">
                                    {{ $changes }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 56px 24px; color: #94a3b8;">
                                    <i class="fa-solid fa-box-open" style="font-size: 36px; display: block; margin-bottom: 12px; color: #cbd5e1;"></i>
                                    No DCS activity records found in admin logs.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($logs->hasPages())
                <div style="padding: 16px 20px; border-top: 1px solid #e2e8f0; display: flex; justify-content: center;">
                    {{ $logs->links('components.pagination') }}
                </div>
            @endif
        </div>
    @endif
</div>
