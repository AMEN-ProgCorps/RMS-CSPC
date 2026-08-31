<?php
/**
 * Admin Console - Account Changes Audit History Volt Component
 * 
 * Provides search, subsystem filtering, and custom paginated access to the admin_logs database table.
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Admin Console - Account Changes')] class extends Component {
    use WithPagination;

    /** @var string Real-time search query */
    public string $search = '';

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

    /**
     * Component Lifecycle Hook - reset page pagination on search update.
     */
    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Component Lifecycle Hook - reset page pagination on subsystem filter update.
     */
    public function updatingSubsystemFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Resets all search and filter properties.
     */
    public function clearFilters(): void
    {
        $this->search = '';
        $this->subsystemFilter = '';
        $this->resetPage();
    }

    /**
     * Fetch admin logs and pass them to the Blade template.
     */
    public function with(): array
    {
        $query = \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_account') ? 'sys_account' : 'account'), 'admin_logs.admin_id', '=', 'account.id')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details'), 'account.id', '=', 'account_details.account_id')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems'), 'admin_logs.what_system', '=', 'subsystems.subsystem_id')
            ->select([
                'admin_logs.id',
                'admin_logs.changes',
                'admin_logs.when_changes',
                'subsystems.subsystem_name',
                'account.username',
                'account_details.first_name',
                'account_details.last_name',
            ]);

        // Search Filter
        if ($this->search !== '') {
            $searchVal = '%' . $this->search . '%';
            $query->where(function ($q) use ($searchVal) {
                $q->where('admin_logs.changes', 'like', $searchVal)
                  ->orWhere('account.username', 'like', $searchVal)
                  ->orWhere('account_details.first_name', 'like', $searchVal)
                  ->orWhere('account_details.last_name', 'like', $searchVal)
                  ->orWhere('subsystems.subsystem_name', 'like', $searchVal);
            });
        }

        // Subsystem Filter
        if ($this->subsystemFilter !== '') {
            $query->where('admin_logs.what_system', $this->subsystemFilter);
        }

        // Paginate records (15 per page)
        $logs = $query->orderBy('admin_logs.when_changes', 'desc')->paginate(15);

        // Fetch subsystems list for filter dropdown
        $subsystems = \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems')->orderBy('subsystem_name')->get();

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
@endpush

<div class="activity-logs-container">
    <!-- Header -->
    <div class="page-header">
        <h1>Administrative Account Changes</h1>
        <p>Review audit logs of system modifications, user settings, role policies, and office data.</p>
    </div>

    <!-- Controls Panel -->
    <div class="logs-controls-card">
        <div class="search-filter-group">
            <!-- Search Input -->
            <div class="search-box-wrapper">
                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                <input type="text" 
                       class="search-input" 
                       placeholder="Search by details, admin name, subsystem..." 
                       wire:model.live="search">
            </div>

            <!-- Subsystem Dropdown -->
            <select class="filter-select" wire:model.live="subsystemFilter">
                <option value="">All Subsystems</option>
                @foreach($subsystems as $subsystem)
                    <option value="{{ $subsystem->subsystem_id }}">{{ $subsystem->subsystem_name }}</option>
                @endforeach
            </select>

            <!-- Clear Button -->
            @if($search !== '' || $subsystemFilter !== '')
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
                        <th style="width: 25%">Administrator</th>
                        <th style="width: 45%">Log Action Details</th>
                        <th style="width: 15%">Subsystem</th>
                        <th style="width: 15%">Timestamp</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        <tr wire:key="log-{{ $log->id }}">
                            <td>
                                <div class="admin-name-cell">
                                    @if($log->first_name || $log->last_name)
                                        <span class="name">{{ $log->first_name }} {{ $log->last_name }}</span>
                                        <span class="email-sub">{{ $log->username }}</span>
                                    @elseif($log->username)
                                        <span class="name">{{ $log->username }}</span>
                                        <span class="email-sub">No details available</span>
                                    @else
                                        <span class="name" style="color: #94a3b8; font-style: italic;">System Automatic</span>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <div class="log-description">
                                    @php
                                        // Add subtle icon indicators based on keyword in description
                                        $desc = $log->changes;
                                        $icon = '<i class="fa-solid fa-gear" style="color: #64748b; margin-right: 6px;"></i>';
                                        if (str_contains(strtolower($desc), 'create')) {
                                            $icon = '<i class="fa-solid fa-circle-plus" style="color: #10b981; margin-right: 6px;"></i>';
                                        } elseif (str_contains(strtolower($desc), 'toggle') || str_contains(strtolower($desc), 'active')) {
                                            $icon = '<i class="fa-solid fa-toggle-on" style="color: #003699; margin-right: 6px;"></i>';
                                        } elseif (str_contains(strtolower($desc), 'update') || str_contains(strtolower($desc), 'detail')) {
                                            $icon = '<i class="fa-solid fa-pen-to-square" style="color: #f59e0b; margin-right: 6px;"></i>';
                                        }
                                    @endphp
                                    {!! $icon !!} {{ $desc }}
                                </div>
                            </td>
                            <td>
                                <span class="subsystem-badge">
                                    <i class="fa-solid fa-laptop-code" style="margin-right: 4px;"></i> {{ $log->subsystem_name ?? 'N/A' }}
                                </span>
                            </td>
                            <td>
                                <div class="log-timestamp">
                                    @php
                                        // Safely parse once and expose epoch seconds for JS formatting
                                        $t = $log->when_changes ? \Carbon\Carbon::parse($log->when_changes) : null;
                                        $formattedDate = $t ? $t->format('Y-m-d') : '--';
                                        $formattedTime = $t ? $t->format('h:i:s A') : '--';
                                        $epoch = $t ? $t->getTimestamp() : '';
                                        $timeAgo = $t ? $t->diffForHumans() : '';
                                    @endphp
                                    <span class="log-date">{{ $formattedDate }}</span>
                                    <span class="log-time" data-time="{{ $epoch }}">{{ $formattedTime }}</span>
                                    <span class="time-ago">{{ $timeAgo }}</span>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4">
                                <div class="empty-state">
                                    <i class="fa-solid fa-clipboard-list"></i>
                                    <h3>No Changes Recorded</h3>
                                    <p>No administrative log records match your query.</p>
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
// Livewire-aware timestamp formatter (uses epoch seconds in data-time)
(function(){
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

    const run = () => { try { updateLogTimes(); } catch (e){} };

    document.addEventListener('livewire:load', () => {
        run();
        if (window.Livewire && typeof Livewire.hook === 'function') {
            Livewire.hook('message.processed', () => { run(); });
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
