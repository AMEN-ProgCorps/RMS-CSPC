<?php
/**
 * Admin Console - Logins Security History Volt Component
 * 
 * Provides search, status filtering, and custom paginated access to the security_logs database table.
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

    /**
     * Component Lifecycle Hook - reset page pagination on search update.
     */
    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Component Lifecycle Hook - reset page pagination on status filter update.
     */
    public function updatingStatusFilter(): void
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
        $this->resetPage();
    }

    /**
     * Fetch logs and pass them to the Blade template.
     */
    public function with(): array
    {
        $query = \DB::table('security_logs')
            ->leftJoin('security_status', 'security_logs.status', '=', 'security_status.status_id')
            ->leftJoin('account', 'security_logs.account', '=', 'account.id')
            ->leftJoin('account_details', 'account.id', '=', 'account_details.account_id')
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

        // Paginate records (15 per page)
        $logs = $query->orderBy('security_logs.time', 'desc')->paginate(15);

        // Fetch available security statuses for filter dropdown
        $statuses = \DB::table('security_status')->orderBy('status_name')->get();

        return [
            'logs' => $logs,
            'statuses' => $statuses,
        ];
    }
};
?>

@push('styles')
    @vite('resources/css/admin/activity_logs.css')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
@endpush

<div class="activity-logs-container">
    <!-- Header -->
    <div class="page-header">
        <h1>Logins Security History</h1>
        <p>Monitor security, authentication, and session activities across the records management console.</p>
    </div>

    <!-- Controls Panel -->
    <div class="logs-controls-card">
        <div class="search-filter-group">
            <!-- Search Input -->
            <div class="search-box-wrapper">
                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                <input type="text" 
                       class="search-input" 
                       placeholder="Search by admin name, username, IP..." 
                       wire:model.live="search">
            </div>

            <!-- Status Dropdown -->
            <select class="filter-select" wire:model.live="statusFilter">
                <option value="">All Statuses</option>
                @foreach($statuses as $status)
                    <option value="{{ $status->status_id }}">{{ $status->status_name }}</option>
                @endforeach
            </select>

            <!-- Clear Button -->
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
                        <th style="width: 15%">Status</th>
                        <th style="width: 25%">Account / Administrator</th>
                        <th style="width: 35%">Description</th>
                        <th style="width: 12%">IP Address</th>
                        <th style="width: 13%">Timestamp</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        <tr wire:key="log-{{ $log->id }}">
                            <td>
                                @php
                                    // Prefer ID-based mapping (safer than string-matching). Fallback to name if id missing.
                                    $sid = $log->status_id ?? null;
                                    $badgeIcon = 'fa-circle-info';
                                    $bg = 'rgba(148,163,184,0.06)';
                                    $color = '#64748b';
                                    $border = 'rgba(148,163,184,0.12)';

                                    switch ($sid) {
                                        case 1: // Login Successful
                                        case 7: // Password Reset Successful -> success styling
                                            $badgeIcon = 'fa-circle-check';
                                            $bg = 'rgba(16,185,129,0.15)';
                                            $color = '#10b981';
                                            $border = 'rgba(16,185,129,0.3)';
                                            break;
                                        case 3: // Logout
                                            $badgeIcon = 'fa-right-from-bracket';
                                            $bg = 'rgba(245,158,11,0.15)';
                                            $color = '#f59e0b';
                                            $border = 'rgba(245,158,11,0.3)';
                                            break;
                                        case 2: // Login Failed
                                        case 5: // Account Locked
                                            $badgeIcon = 'fa-circle-xmark';
                                            $bg = 'rgba(239,68,68,0.15)';
                                            $color = '#ef4444';
                                            $border = 'rgba(239,68,68,0.3)';
                                            break;
                                        case 4: // Unauthorized Access
                                            $badgeIcon = 'fa-triangle-exclamation';
                                            $bg = 'rgba(249,115,22,0.15)';
                                            $color = '#f97316';
                                            $border = 'rgba(249,115,22,0.3)';
                                            break;
                                        case 6: // Password Reset Requested
                                            $badgeIcon = 'fa-key';
                                            $bg = 'rgba(139,92,246,0.12)';
                                            $color = '#8b5cf6';
                                            $border = 'rgba(139,92,246,0.22)';
                                            break;
                                        default:
                                            // Fallback: attempt lightweight name matching
                                            $statusLower = strtolower($log->status_name ?? '');
                                            if (str_contains($statusLower, 'success')) {
                                                $badgeIcon = 'fa-circle-check';
                                                $bg = 'rgba(16,185,129,0.15)';
                                                $color = '#10b981';
                                                $border = 'rgba(16,185,129,0.3)';
                                            } elseif (str_contains($statusLower, 'logout')) {
                                                $badgeIcon = 'fa-right-from-bracket';
                                                $bg = 'rgba(245,158,11,0.15)';
                                                $color = '#f59e0b';
                                                $border = 'rgba(245,158,11,0.3)';
                                            } elseif (str_contains($statusLower, 'failed') || str_contains($statusLower, 'lock')) {
                                                $badgeIcon = 'fa-circle-xmark';
                                                $bg = 'rgba(239,68,68,0.15)';
                                                $color = '#ef4444';
                                                $border = 'rgba(239,68,68,0.3)';
                                            } else {
                                                $badgeIcon = 'fa-circle-info';
                                                $bg = 'rgba(59,130,246,0.08)';
                                                $color = '#3b82f6';
                                                $border = 'rgba(59,130,246,0.12)';
                                            }
                                            break;
                                    }
                                @endphp
                                <span class="badge" style="padding: 4px 10px; border-radius: 99px; font-size: 11px; font-weight: bold; text-transform: uppercase; background-color: {{ $bg }}; color: {{ $color }}; border: 1px solid {{ $border }};">
                                    <i class="fa-solid {{ $badgeIcon }}" style="margin-right:6px;"></i>
                                    {{ $log->status_name }}
                                </span>
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
                                        <span class="name" style="color: #94a3b8; font-style: italic;">Anonymous / Invalid Attempt</span>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <div class="log-description">
                                    {{ $log->description }}
                                </div>
                            </td>
                            <td>
                                <code style="font-size: 12.5px; color: #475569; background: #f1f5f9; padding: 2px 6px; border-radius: 4px;">{{ $log->user_ipaddr }}</code>
                            </td>
                            <td>
                                <div class="log-timestamp">
                                    @php
                                        // Parse once safely to avoid repeated parsing and null errors
                                        $time = $log->time ? \Carbon\Carbon::parse($log->time) : null;
                                        $formattedDate = $time ? $time->format('Y-m-d') : '--';
                                        // store epoch seconds for robust JS parsing
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
                                    <p>No login records match your search criteria.</p>
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
<script>
// Use Livewire hooks and epoch seconds to avoid Date parsing inconsistencies and to re-format after partial updates.
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
        try { updateLogTimes(); } catch (e) { /* silent */ }
    };

    // Run on initial load and every 30s. Also re-run after Livewire updates.
    document.addEventListener('livewire:load', () => {
        run();
        if (window.Livewire && typeof Livewire.hook === 'function') {
            Livewire.hook('message.processed', () => {
                run();
            });
        }
        setInterval(run, 30000);
    });

    // Fallback if Livewire not present
    if (!window.Livewire) {
        document.addEventListener('DOMContentLoaded', () => {
            run();
            setInterval(run, 30000);
        });
    }
})();
</script>
