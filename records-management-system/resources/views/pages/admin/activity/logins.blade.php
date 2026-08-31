<?php
/**
 * Admin Console - Logins Security History Volt Component
 * 
 * Provides search, status filtering, stats overview, and paginated access to security_logs.
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Admin Console - Logins')] class extends Component {
    use WithPagination;

    /** @var string Real-time search query */
    public string $search = '';

    /** @var string Selected status filter */
    public string $statusFilter = '';

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if (!$perms || (!$perms->is_sadm && !$perms->can_access_activity_logs)) {
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

    public function clearFilters(): void
    {
        $this->search = '';
        $this->statusFilter = '';
        $this->resetPage();
    }

    public function with(): array
    {
        $query = \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_security_logs') ? 'sys_security_logs' : 'security_logs')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_security_status') ? 'sys_security_status' : 'security_status'), 'security_logs.status', '=', 'security_status.status_id')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_account') ? 'sys_account' : 'account'), 'security_logs.account', '=', 'account.id')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details'), 'account.id', '=', 'account_details.account_id')
            ->select([
                'security_logs.id',
                'security_logs.time',
                'security_logs.user_ipaddr',
                'security_logs.status as status_id',
                'security_status.status_name',
                'security_status.description',
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
                  ->orWhere('security_logs.user_ipaddr', 'like', $searchVal)
                  ->orWhere('security_status.status_name', 'like', $searchVal)
                  ->orWhere('security_status.description', 'like', $searchVal);
            });
        }

        // Status Filter
        if ($this->statusFilter !== '') {
            $query->where('security_logs.status', $this->statusFilter);
        }

        $logs = $query->orderBy('security_logs.time', 'desc')->paginate(15);
        $statuses = \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_security_status') ? 'sys_security_status' : 'security_status')->orderBy('status_name')->get();

        // Calculate Overview Statistics
        $stats = [
            'total'      => \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_security_logs') ? 'sys_security_logs' : 'security_logs')->count(),
            'successful' => \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_security_logs') ? 'sys_security_logs' : 'security_logs')->where('status', 1)->count(),
            'failed'     => \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_security_logs') ? 'sys_security_logs' : 'security_logs')->where('status', 2)->count(),
            'warnings'   => \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_security_logs') ? 'sys_security_logs' : 'security_logs')->whereIn('status', [4, 5])->count(),
        ];

        return [
            'logs'     => $logs,
            'statuses' => $statuses,
            'stats'    => $stats,
        ];
    }
};
?>

@push('styles')
    @vite('resources/css/admin/activity_logs.css')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
@endpush

<div class="activity-logs-container">
    <!-- Header Section -->
    <div class="page-header">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
            <div>
                <h1>Logins Security History</h1>
                <p>Monitor security, authentication, and session activities across the records management console.</p>
            </div>
            <div>
                <span class="live-status-pill">
                    <span class="pulse-dot"></span> Live Security Feed
                </span>
            </div>
        </div>
    </div>

    <!-- Overview Stats Cards Grid -->
    <div class="stats-overview-grid">
        <div class="stat-card blue">
            <div class="stat-icon"><i class="fa-solid fa-shield-halved"></i></div>
            <div class="stat-details">
                <span class="stat-value">{{ number_format($stats['total']) }}</span>
                <span class="stat-label">Total Security Events</span>
            </div>
        </div>

        <div class="stat-card green">
            <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
            <div class="stat-details">
                <span class="stat-value">{{ number_format($stats['successful']) }}</span>
                <span class="stat-label">Successful Logins</span>
            </div>
        </div>

        <div class="stat-card red">
            <div class="stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div class="stat-details">
                <span class="stat-value">{{ number_format($stats['failed']) }}</span>
                <span class="stat-label">Failed Logins</span>
            </div>
        </div>

        <div class="stat-card orange">
            <div class="stat-icon"><i class="fa-solid fa-user-lock"></i></div>
            <div class="stat-details">
                <span class="stat-value">{{ number_format($stats['warnings']) }}</span>
                <span class="stat-label">Locks & Warnings</span>
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
                       placeholder="Search admin, username, IP..." 
                       wire:model.live.debounce.300ms="search">
            </div>

            <!-- Status Dropdown Filter -->
            <select class="filter-select" wire:model.live="statusFilter">
                <option value="">All Security Statuses</option>
                @foreach($statuses as $status)
                    <option value="{{ $status->status_id }}">{{ $status->status_name }}</option>
                @endforeach
            </select>

            <!-- Clear Filters Button -->
            @if($search !== '' || $statusFilter !== '')
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
                        <th style="width: 16%">SECURITY STATUS</th>
                        <th style="width: 26%">ACCOUNT / ADMINISTRATOR</th>
                        <th style="width: 32%">ACTIVITY DESCRIPTION</th>
                        <th style="width: 12%">IP ADDRESS</th>
                        <th style="width: 14%">TIMESTAMP</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        <tr wire:key="log-{{ $log->id }}">
                            <td>
                                @php
                                    $sid = $log->status_id ?? null;
                                    $badgeIcon = 'fa-circle-info';
                                    $bg = 'rgba(148,163,184,0.1)';
                                    $color = '#64748b';
                                    $border = 'rgba(148,163,184,0.2)';

                                    switch ($sid) {
                                        case 1: // Login Successful
                                        case 7: // Password Reset Successful
                                            $badgeIcon = 'fa-circle-check';
                                            $bg = 'rgba(16,185,129,0.12)';
                                            $color = '#059669';
                                            $border = 'rgba(16,185,129,0.25)';
                                            break;
                                        case 3: // Logout
                                            $badgeIcon = 'fa-right-from-bracket';
                                            $bg = 'rgba(245,158,11,0.12)';
                                            $color = '#d97706';
                                            $border = 'rgba(245,158,11,0.25)';
                                            break;
                                        case 2: // Login Failed
                                        case 5: // Account Locked
                                            $badgeIcon = 'fa-circle-xmark';
                                            $bg = 'rgba(239,68,68,0.12)';
                                            $color = '#dc2626';
                                            $border = 'rgba(239,68,68,0.25)';
                                            break;
                                        case 4: // Unauthorized Access
                                            $badgeIcon = 'fa-shield-cat';
                                            $bg = 'rgba(249,115,22,0.12)';
                                            $color = '#ea580c';
                                            $border = 'rgba(249,115,22,0.25)';
                                            break;
                                        case 6: // Password Reset Requested
                                            $badgeIcon = 'fa-key';
                                            $bg = 'rgba(139,92,246,0.12)';
                                            $color = '#7c3aed';
                                            $border = 'rgba(139,92,246,0.22)';
                                            break;
                                        default:
                                            $statusLower = strtolower($log->status_name ?? '');
                                            if (str_contains($statusLower, 'success')) {
                                                $badgeIcon = 'fa-circle-check';
                                                $bg = 'rgba(16,185,129,0.12)';
                                                $color = '#059669';
                                                $border = 'rgba(16,185,129,0.25)';
                                            } elseif (str_contains($statusLower, 'logout')) {
                                                $badgeIcon = 'fa-right-from-bracket';
                                                $bg = 'rgba(245,158,11,0.12)';
                                                $color = '#d97706';
                                                $border = 'rgba(245,158,11,0.25)';
                                            } elseif (str_contains($statusLower, 'failed') || str_contains($statusLower, 'lock')) {
                                                $badgeIcon = 'fa-circle-xmark';
                                                $bg = 'rgba(239,68,68,0.12)';
                                                $color = '#dc2626';
                                                $border = 'rgba(239,68,68,0.25)';
                                            } else {
                                                $badgeIcon = 'fa-circle-info';
                                                $bg = 'rgba(59,130,246,0.1)';
                                                $color = '#2563eb';
                                                $border = 'rgba(59,130,246,0.2)';
                                            }
                                            break;
                                    }
                                @endphp
                                <span class="badge" style="background-color: {{ $bg }}; color: {{ $color }}; border: 1px solid {{ $border }};">
                                    <i class="fa-solid {{ $badgeIcon }}"></i>
                                    <span>{{ $log->status_name }}</span>
                                </span>
                            </td>
                            <td>
                                <div class="admin-user-row">
                                    <div class="user-avatar-circle">
                                        @if($log->first_name)
                                            {{ strtoupper(substr($log->first_name, 0, 1)) }}
                                        @elseif($log->username)
                                            {{ strtoupper(substr($log->username, 0, 1)) }}
                                        @else
                                            ?
                                        @endif
                                    </div>
                                    <div class="admin-name-cell">
                                        @if($log->first_name || $log->last_name)
                                            <span class="name">{{ $log->first_name }} {{ $log->last_name }}</span>
                                            <span class="email-sub">@ {{ $log->username }}</span>
                                        @elseif($log->username)
                                            <span class="name">{{ $log->username }}</span>
                                            <span class="email-sub">System User</span>
                                        @else
                                            <span class="name" style="color: #94a3b8; font-style: italic;">Anonymous / Invalid Attempt</span>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="log-description">
                                    {{ $log->description }}
                                </div>
                            </td>
                            <td>
                                <span class="ip-address-pill">
                                    <i class="fa-solid fa-network-wired" style="font-size: 10px; color: #94a3b8;"></i>
                                    {{ $log->user_ipaddr }}
                                </span>
                            </td>
                            <td>
                                <div class="log-timestamp">
                                    @php
                                        $time = $log->time ? \Carbon\Carbon::parse($log->time) : null;
                                        $formattedDate = $time ? $time->format('M d, Y') : '--';
                                        $epoch = $time ? $time->getTimestamp() : '';
                                        $formattedTime = $time ? $time->format('h:i:s A') : '--';
                                        $timeAgo = $time ? $time->diffForHumans() : '';
                                    @endphp
                                    <span class="log-date">{{ $formattedDate }}</span>
                                    <span class="log-time" data-time="{{ $epoch }}">{{ $formattedTime }}</span>
                                    <span class="time-ago">{{ $timeAgo }}</span>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <div class="empty-state">
                                    <i class="fa-solid fa-shield-halved"></i>
                                    <h3>No Security Logs Found</h3>
                                    <p>No login records match your current search or filter criteria.</p>
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

<script>
(() => {
    const pad = (v) => String(v).padStart(2, '0');

    const formatLocalTime = (d) => {
        let hours = d.getHours();
        const minutes = pad(d.getMinutes());
        const seconds = pad(d.getSeconds());
        const period = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12;
        if (hours === 0) hours = 12;
        return String(hours).padStart(2, '0') + ':' + minutes + ':' + seconds + ' ' + period;
    };

    const updateLogTimes = () => {
        document.querySelectorAll('.log-time').forEach(el => {
            const ts = el.getAttribute('data-time');
            if (!ts) return;
            const num = Number(ts);
            if (Number.isNaN(num)) return;
            const dt = new Date(num * 1000);
            if (isNaN(dt)) return;
            el.textContent = formatLocalTime(dt);
        });
    };

    const run = () => {
        try { updateLogTimes(); } catch (e) {}
    };

    document.addEventListener('livewire:load', () => {
        run();
        if (window.Livewire && typeof Livewire.hook === 'function') {
            Livewire.hook('message.processed', () => {
                run();
            });
        }
        setInterval(run, 30000);
    });

    if (!window.Livewire) {
        document.addEventListener('DOMContentLoaded', () => {
            run();
            setInterval(run, 30000);
        });
    }
})();
</script>
