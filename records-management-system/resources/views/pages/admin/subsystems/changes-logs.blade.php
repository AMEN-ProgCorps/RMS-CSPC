<?php
/**
 * Admin Console - Subsystem Changes Logs Volt Component
 * 
 * Lists version changes history for all subsystems.
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Admin Console - Subsystem Changes Logs')] class extends Component {
    use WithPagination;

    /** @var string Search query */
    public string $search = '';

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
     * Query changes logs and pass them to the Blade template.
     */
    public function with(): array
    {
        $query = \DB::table('subsystem_versions_log')
            ->leftJoin('subsystems', 'subsystem_versions_log.subsystem_key', '=', 'subsystems.subsystem_id')
            ->select([
                'subsystem_versions_log.changes_id',
                'subsystem_versions_log.version_change',
                'subsystem_versions_log.changes_on',
                'subsystems.subsystem_name',
            ]);

        if ($this->search !== '') {
            $searchVal = '%' . $this->search . '%';
            $query->where(function ($q) use ($searchVal) {
                $q->where('subsystems.subsystem_name', 'like', $searchVal)
                  ->orWhere('subsystem_versions_log.version_change', 'like', $searchVal);
            });
        }

        $logs = $query->orderBy('subsystem_versions_log.changes_on', 'desc')->paginate(15);

        return [
            'logs' => $logs,
        ];
    }
};
?>

@push('styles')
    @vite(['resources/css/admin/activity_logs.css', 'resources/css/admin/subsystems.css'])
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
@endpush

<div class="activity-logs-container">
    <!-- Header -->
    <div class="page-header">
        <h1>Subsystem Version Logs</h1>
        <p>Audit historical records of subsystem installations, registrations, and version updates.</p>
    </div>

    <!-- Controls Panel -->
    <div class="logs-controls-card">
        <div class="search-filter-group">
            <!-- Search Input -->
            <div class="search-box-wrapper">
                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                <input type="text" 
                       class="search-input" 
                       placeholder="Search by subsystem name or version..." 
                       wire:model.live="search">
            </div>

            <!-- Clear Button -->
            @if($search !== '')
                <button type="button" class="btn-clear-filters" wire:click="clearFilters">
                    <i class="fa-solid fa-filter-circle-xmark"></i> Clear Search
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
                        <th style="width: 15%">Log ID</th>
                        <th style="width: 45%">Subsystem Name</th>
                        <th style="width: 20%">Version Change</th>
                        <th style="width: 20%">Updated On</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        <tr wire:key="sub-log-{{ $log->changes_id }}">
                            <td>
                                <span style="font-weight: 600; color: #64748b;">#{{ $log->changes_id }}</span>
                            </td>
                            <td>
                                <div class="admin-name-cell">
                                    <span class="name">{{ $log->subsystem_name ?? 'Unknown Subsystem' }}</span>
                                </div>
                            </td>
                            <td>
                                <span class="subsystem-badge">
                                    <i class="fa-solid fa-code-branch" style="margin-right: 4px;"></i> v{{ $log->version_change }}
                                </span>
                            </td>
                            <td>
                                <div class="log-timestamp">
                                    {{ \Carbon\Carbon::parse($log->changes_on)->format('Y-m-d H:i:s') }}
                                    <span class="time-ago">{{ \Carbon\Carbon::parse($log->changes_on)->diffForHumans() }}</span>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4">
                                <div class="empty-state">
                                    <i class="fa-solid fa-clock-rotate-left"></i>
                                    <h3>No Version Logs Found</h3>
                                    <p>No historical changes match your query.</p>
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
