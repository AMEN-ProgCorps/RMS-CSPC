<?php
/**
 * Admin Console - Error Tracking & Tickets
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Admin Console - Error Tickets')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $statusFilter = '';
    public string $priorityFilter = '';
    public string $sourceFilter = '';

    public bool $showDetail = false;
    public ?int $selectedId = null;
    public string $editStatus = 'open';
    public string $editPriority = 'medium';
    public string $editAssignedTo = '';
    public string $editAdminNotes = '';

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if (! $perms || (! $perms->is_sadm && ! $perms->can_access_activity_logs)) {
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

    public function updatingPriorityFilter(): void
    {
        $this->resetPage();
    }

    public function updatingSourceFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->statusFilter = '';
        $this->priorityFilter = '';
        $this->sourceFilter = '';
        $this->resetPage();
    }

    public function openTicket(int $id): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('sys_error_tickets')) {
            return;
        }

        $ticket = \App\Models\ErrorTicket::query()->find($id);
        if (! $ticket) {
            return;
        }

        $this->selectedId = $ticket->id;
        $this->editStatus = $ticket->status;
        $this->editPriority = $ticket->priority;
        $this->editAssignedTo = $ticket->assigned_to ? (string) $ticket->assigned_to : '';
        $this->editAdminNotes = (string) ($ticket->admin_notes ?? '');
        $this->showDetail = true;
    }

    public function closeDetail(): void
    {
        $this->showDetail = false;
        $this->selectedId = null;
    }

    public function saveTicket(): void
    {
        $perms = auth()->user()?->permissions;
        if (! $perms || (! $perms->is_sadm && ! $perms->can_access_activity_logs)) {
            abort(403, 'Unauthorized access.');
        }

        if (! $this->selectedId || ! \Illuminate\Support\Facades\Schema::hasTable('sys_error_tickets')) {
            return;
        }

        $this->validate([
            'editStatus' => 'required|in:open,in_progress,resolved,closed',
            'editPriority' => 'required|in:low,medium,high,critical',
            'editAssignedTo' => 'nullable|integer',
            'editAdminNotes' => 'nullable|string|max:5000',
        ]);

        $ticket = \App\Models\ErrorTicket::query()->find($this->selectedId);
        if (! $ticket) {
            return;
        }

        $previousAssignee = $ticket->assigned_to ? (int) $ticket->assigned_to : null;
        $newAssignee = $this->editAssignedTo !== '' ? (int) $this->editAssignedTo : null;

        $ticket->status = $this->editStatus;
        $ticket->priority = $this->editPriority;
        $ticket->assigned_to = $newAssignee;
        $ticket->admin_notes = $this->editAdminNotes !== '' ? $this->editAdminNotes : null;

        if (in_array($this->editStatus, ['resolved', 'closed'], true)) {
            $ticket->resolved_at = $ticket->resolved_at ?? now();
        } else {
            $ticket->resolved_at = null;
        }

        $ticket->save();

        if ($newAssignee && $newAssignee !== $previousAssignee && $newAssignee !== (int) auth()->id()) {
            $this->notifyTicketAssignee($ticket, $newAssignee);
        }

        \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
            'changes' => "Updated error ticket {$ticket->ticket_number} → {$ticket->status}/{$ticket->priority}",
            'admin_id' => auth()->id(),
            'what_system' => 3,
            'when_changes' => now(),
        ]);

        $this->showDetail = false;
        $this->selectedId = null;
    }

    private function notifyTicketAssignee(\App\Models\ErrorTicket $ticket, int $assigneeId): void
    {
        try {
            $accDetailsTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
            $officeTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
            $officeCode = \DB::table($accDetailsTbl.' as ad')
                ->join($officeTbl.' as o', 'o.id', '=', 'ad.office_id')
                ->where('ad.account_id', $assigneeId)
                ->value('o.office_code');

            if (! is_string($officeCode) || $officeCode === '') {
                return;
            }

            $subsystemsTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems';
            $subsystemId = \DB::table($subsystemsTbl)
                ->where('subsystem_name', 'Admin Console')
                ->value('subsystem_id');
            if (! $subsystemId) {
                $subsystemId = \DB::table($subsystemsTbl)
                    ->where('subsystem_name', 'Profile Manager')
                    ->value('subsystem_id');
            }
            if (! $subsystemId) {
                return;
            }

            $assigner = trim((string) (auth()->user()?->username ?? 'An admin'));
            $message = "{$assigner} assigned error ticket {$ticket->ticket_number} to you.";
            $notifContentTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_notif_content') ? 'sys_notif_content' : 'notif_content';
            $notifTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_notifications') ? 'sys_notifications' : 'notifications';

            $contentId = \DB::table($notifContentTbl)->insertGetId([
                'system' => $subsystemId,
                'content' => \Illuminate\Support\Str::limit($message, 255, '…'),
                'redirect_url' => '/admin/tickets',
                'created_at' => now(),
            ]);

            \DB::table($notifTbl)->insert([
                'office' => $officeCode,
                'contents' => $contentId,
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Assignment notification must not block triage saves.
        }
    }

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $perms = auth()->user()?->permissions;
        if (! $perms || (! $perms->is_sadm && ! $perms->can_access_activity_logs)) {
            abort(403, 'Unauthorized access.');
        }

        $records = $this->baseQuery()->orderByDesc('t.created_at')->limit(5000)->get();

        \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
            'changes' => 'Exported Error Tickets to CSV ('.count($records).' records)',
            'admin_id' => auth()->id(),
            'what_system' => 3,
            'when_changes' => now(),
        ]);

        $filename = 'error_tickets_'.date('Y-m-d_His').'.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];

        return new \Symfony\Component\HttpFoundation\StreamedResponse(function () use ($records) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'Ticket', 'Source', 'Reference', 'Title', 'Status', 'Priority',
                'Subsystem', 'HTTP', 'Occurrences', 'Reporter', 'Assignee', 'Created', 'Last Seen',
            ]);

            foreach ($records as $row) {
                fputcsv($handle, [
                    $row->ticket_number,
                    $row->source,
                    $row->reference,
                    $row->title,
                    $row->status,
                    $row->priority,
                    $row->subsystem,
                    $row->http_status,
                    $row->occurrence_count,
                    $row->reporter_username,
                    $row->assignee_username,
                    $row->created_at,
                    $row->last_seen_at,
                ]);
            }
            fclose($handle);
        }, 200, $headers);
    }

    private function baseQuery()
    {
        $accountTable = \Illuminate\Support\Facades\Schema::hasTable('sys_account') ? 'sys_account' : 'account';

        $query = \DB::table('sys_error_tickets as t')
            ->leftJoin($accountTable.' as reporter', 't.reported_by', '=', 'reporter.id')
            ->leftJoin($accountTable.' as assignee', 't.assigned_to', '=', 'assignee.id')
            ->select([
                't.*',
                'reporter.username as reporter_username',
                'assignee.username as assignee_username',
            ]);

        if ($this->search !== '') {
            $searchVal = '%'.$this->search.'%';
            $query->where(function ($q) use ($searchVal) {
                $q->where('t.ticket_number', 'ilike', $searchVal)
                    ->orWhere('t.reference', 'ilike', $searchVal)
                    ->orWhere('t.title', 'ilike', $searchVal)
                    ->orWhere('t.description', 'ilike', $searchVal)
                    ->orWhere('t.url', 'ilike', $searchVal)
                    ->orWhere('t.exception_class', 'ilike', $searchVal)
                    ->orWhere('reporter.username', 'ilike', $searchVal)
                    ->orWhere('assignee.username', 'ilike', $searchVal);
            });
        }

        if ($this->statusFilter !== '') {
            $query->where('t.status', $this->statusFilter);
        }
        if ($this->priorityFilter !== '') {
            $query->where('t.priority', $this->priorityFilter);
        }
        if ($this->sourceFilter !== '') {
            $query->where('t.source', $this->sourceFilter);
        }

        return $query;
    }

    public function with(): array
    {
        $hasTable = \Illuminate\Support\Facades\Schema::hasTable('sys_error_tickets');

        $stats = [
            'open' => 0,
            'in_progress' => 0,
            'critical' => 0,
            'total' => 0,
        ];

        $tickets = new \Illuminate\Pagination\LengthAwarePaginator([], 0, 15);
        $selected = null;
        $admins = collect();

        if ($hasTable) {
            $stats = [
                'open' => \DB::table('sys_error_tickets')->where('status', 'open')->count(),
                'in_progress' => \DB::table('sys_error_tickets')->where('status', 'in_progress')->count(),
                'critical' => \DB::table('sys_error_tickets')->where('priority', 'critical')->whereIn('status', ['open', 'in_progress'])->count(),
                'total' => \DB::table('sys_error_tickets')->count(),
            ];

            $tickets = $this->baseQuery()->orderByDesc('t.last_seen_at')->orderByDesc('t.created_at')->paginate(15);

            if ($this->selectedId) {
                $selected = $this->baseQuery()->where('t.id', $this->selectedId)->first();
            }

            $accountTable = \Illuminate\Support\Facades\Schema::hasTable('sys_account') ? 'sys_account' : 'account';
            $conditionKey = \Illuminate\Support\Facades\Schema::hasTable('sys_condition_key') ? 'sys_condition_key' : 'condition_key';
            $conditionDetails = \Illuminate\Support\Facades\Schema::hasTable('sys_condition_details') ? 'sys_condition_details' : 'condition_details';

            $admins = \DB::table($accountTable.' as a')
                ->leftJoin($conditionKey.' as ck', 'a.account_role', '=', 'ck.id')
                ->leftJoin($conditionDetails.' as cd', 'ck.modifier_key', '=', 'cd.key_id')
                ->where(function ($q) {
                    $q->where('cd.is_sadm', true)
                        ->orWhere('cd.is_admin', true)
                        ->orWhere('cd.can_access_activity_logs', true);
                })
                ->where('a.account_active', true)
                ->orderBy('a.username')
                ->select('a.id', 'a.username')
                ->distinct()
                ->limit(200)
                ->get();
        }

        return [
            'hasTable' => $hasTable,
            'tickets' => $tickets,
            'stats' => $stats,
            'selected' => $selected,
            'admins' => $admins,
        ];
    }
};
?>

@push('styles')
    @vite('resources/css/admin/activity_logs.css')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        .ticket-modal-overlay {
            position: fixed; inset: 0; z-index: 100010;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            display: grid; place-items: center; padding: 16px;
        }
        .ticket-modal {
            width: min(720px, 100%);
            max-height: min(90vh, 900px);
            overflow: auto;
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            padding: 20px;
            font-family: Inter, system-ui, sans-serif;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1);
        }
        .ticket-modal h2 { margin: 0 0 4px; font-size: 1.15rem; }
        .ticket-modal .sub { margin: 0 0 14px; color: #64748b; font-size: 0.85rem; }
        .ticket-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .ticket-grid label { display: block; font-size: 0.78rem; font-weight: 600; color: #334155; }
        .ticket-grid select, .ticket-grid textarea, .ticket-notes {
            width: 100%; box-sizing: border-box; margin-top: 4px;
            border: 1px solid #cbd5e1; border-radius: 8px; padding: 9px 11px; font: inherit;
        }
        .ticket-notes { grid-column: 1 / -1; }
        .ticket-meta {
            margin: 12px 0;
            padding: 12px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.85rem;
            color: #334155;
            line-height: 1.5;
            word-break: break-word;
        }
        .ticket-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 14px; }
        .ticket-btn {
            border: 1px solid #cbd5e1; background: #fff; color: #334155;
            border-radius: 8px; padding: 9px 14px; font: 600 0.85rem Inter, system-ui, sans-serif; cursor: pointer;
        }
        .ticket-btn.primary { background: #0f172a; border-color: #0f172a; color: #fff; }
        .clickable-row { cursor: pointer; }
        .clickable-row:hover { background: rgba(37, 99, 235, 0.04); }
        @media (max-width: 700px) {
            .ticket-grid { grid-template-columns: 1fr; }
        }
    </style>
@endpush

<div class="activity-logs-container">
    <div class="page-header">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
            <div>
                <h1>Error Tracking & Tickets</h1>
                <p>Triage auto-captured server errors and user-reported problems across RMS.</p>
            </div>
            <div>
                <span class="live-status-pill">
                    <span class="pulse-dot"></span> Ticket Queue
                </span>
            </div>
        </div>
    </div>

    @if(!$hasTable)
        <div class="logs-table-card">
            <div class="empty-state">
                <i class="fa-solid fa-database"></i>
                <h3>Tickets table not ready</h3>
                <p>Run migrations to create <code>sys_error_tickets</code>, then refresh this page.</p>
            </div>
        </div>
    @else
        <div class="stats-overview-grid">
            <div class="stat-card blue">
                <div class="stat-icon"><i class="fa-solid fa-inbox"></i></div>
                <div class="stat-details">
                    <span class="stat-value">{{ number_format($stats['open']) }}</span>
                    <span class="stat-label">Open</span>
                </div>
            </div>
            <div class="stat-card orange">
                <div class="stat-icon"><i class="fa-solid fa-spinner"></i></div>
                <div class="stat-details">
                    <span class="stat-value">{{ number_format($stats['in_progress']) }}</span>
                    <span class="stat-label">In Progress</span>
                </div>
            </div>
            <div class="stat-card red">
                <div class="stat-icon"><i class="fa-solid fa-bolt"></i></div>
                <div class="stat-details">
                    <span class="stat-value">{{ number_format($stats['critical']) }}</span>
                    <span class="stat-label">Critical Open</span>
                </div>
            </div>
            <div class="stat-card green">
                <div class="stat-icon"><i class="fa-solid fa-ticket"></i></div>
                <div class="stat-details">
                    <span class="stat-value">{{ number_format($stats['total']) }}</span>
                    <span class="stat-label">Total Tickets</span>
                </div>
            </div>
        </div>

        <div class="logs-controls-card">
            <div class="search-filter-group" style="display: flex; justify-content: space-between; align-items: center; width: 100%; flex-wrap: wrap; gap: 12px;">
                <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap; flex: 1;">
                    <div class="search-box-wrapper">
                        <i class="fa-solid fa-magnifying-glass search-icon"></i>
                        <input type="text"
                               class="search-input"
                               placeholder="Search ticket, reference, title..."
                               wire:model.live.debounce.300ms="search">
                    </div>

                    <select class="filter-select" wire:model.live="statusFilter">
                        <option value="">All Statuses</option>
                        <option value="open">Open</option>
                        <option value="in_progress">In Progress</option>
                        <option value="resolved">Resolved</option>
                        <option value="closed">Closed</option>
                    </select>

                    <select class="filter-select" wire:model.live="priorityFilter">
                        <option value="">All Priorities</option>
                        <option value="low">Low</option>
                        <option value="medium">Medium</option>
                        <option value="high">High</option>
                        <option value="critical">Critical</option>
                    </select>

                    <select class="filter-select" wire:model.live="sourceFilter">
                        <option value="">All Sources</option>
                        <option value="auto">Auto</option>
                        <option value="user">User</option>
                    </select>

                    @if($search !== '' || $statusFilter !== '' || $priorityFilter !== '' || $sourceFilter !== '')
                        <button type="button" class="btn-clear-filters" wire:click="clearFilters">
                            <i class="fa-solid fa-filter-circle-xmark"></i> Clear Filters
                        </button>
                    @endif
                </div>

                <div>
                    <button type="button" class="btn-export" wire:click="exportCsv" wire:loading.attr="disabled" title="Export filtered tickets as CSV">
                        <i class="fa-solid fa-file-csv" wire:loading.remove wire:target="exportCsv"></i>
                        <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="exportCsv"></i>
                        <span wire:loading.remove wire:target="exportCsv">Export CSV</span>
                        <span wire:loading wire:target="exportCsv">Exporting...</span>
                    </button>
                </div>
            </div>
        </div>

        <div class="logs-table-card">
            <div class="table-responsive">
                <table class="logs-table">
                    <thead>
                        <tr>
                            <th style="width: 14%">TICKET</th>
                            <th style="width: 12%">STATUS</th>
                            <th style="width: 10%">PRIORITY</th>
                            <th style="width: 28%">TITLE</th>
                            <th style="width: 12%">SOURCE</th>
                            <th style="width: 12%">SUBSYSTEM</th>
                            <th style="width: 12%">LAST SEEN</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($tickets as $ticket)
                            @php
                                $statusStyles = match ($ticket->status) {
                                    'open' => ['bg' => 'rgba(37,99,235,0.12)', 'color' => '#2563eb', 'border' => 'rgba(37,99,235,0.25)', 'icon' => 'fa-circle'],
                                    'in_progress' => ['bg' => 'rgba(245,158,11,0.12)', 'color' => '#d97706', 'border' => 'rgba(245,158,11,0.25)', 'icon' => 'fa-spinner'],
                                    'resolved' => ['bg' => 'rgba(16,185,129,0.12)', 'color' => '#059669', 'border' => 'rgba(16,185,129,0.25)', 'icon' => 'fa-circle-check'],
                                    'closed' => ['bg' => 'rgba(148,163,184,0.12)', 'color' => '#64748b', 'border' => 'rgba(148,163,184,0.25)', 'icon' => 'fa-lock'],
                                    default => ['bg' => 'rgba(148,163,184,0.1)', 'color' => '#64748b', 'border' => 'rgba(148,163,184,0.2)', 'icon' => 'fa-circle-info'],
                                };
                                $priorityStyles = match ($ticket->priority) {
                                    'critical' => ['bg' => 'rgba(220,38,38,0.12)', 'color' => '#dc2626', 'border' => 'rgba(220,38,38,0.25)'],
                                    'high' => ['bg' => 'rgba(234,88,12,0.12)', 'color' => '#ea580c', 'border' => 'rgba(234,88,12,0.25)'],
                                    'medium' => ['bg' => 'rgba(59,130,246,0.12)', 'color' => '#2563eb', 'border' => 'rgba(59,130,246,0.22)'],
                                    default => ['bg' => 'rgba(148,163,184,0.12)', 'color' => '#64748b', 'border' => 'rgba(148,163,184,0.22)'],
                                };
                                $seen = $ticket->last_seen_at ? \Carbon\Carbon::parse($ticket->last_seen_at) : ($ticket->created_at ? \Carbon\Carbon::parse($ticket->created_at) : null);
                            @endphp
                            <tr class="clickable-row" wire:key="ticket-{{ $ticket->id }}" wire:click="openTicket({{ $ticket->id }})">
                                <td>
                                    <div class="admin-name-cell">
                                        <span class="name">{{ $ticket->ticket_number }}</span>
                                        @if($ticket->reference && $ticket->reference !== $ticket->ticket_number)
                                            <span class="email-sub">{{ $ticket->reference }}</span>
                                        @endif
                                        @if((int) $ticket->occurrence_count > 1)
                                            <span class="email-sub">×{{ $ticket->occurrence_count }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <span class="badge" style="background-color: {{ $statusStyles['bg'] }}; color: {{ $statusStyles['color'] }}; border: 1px solid {{ $statusStyles['border'] }};">
                                        <i class="fa-solid {{ $statusStyles['icon'] }}"></i>
                                        <span>{{ str_replace('_', ' ', $ticket->status) }}</span>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge" style="background-color: {{ $priorityStyles['bg'] }}; color: {{ $priorityStyles['color'] }}; border: 1px solid {{ $priorityStyles['border'] }};">
                                        {{ ucfirst($ticket->priority) }}
                                    </span>
                                </td>
                                <td>
                                    <div class="log-description">{{ \Illuminate\Support\Str::limit($ticket->title, 90) }}</div>
                                </td>
                                <td>
                                    <span class="ip-address-pill">{{ $ticket->source === 'auto' ? 'Auto' : 'User' }}</span>
                                </td>
                                <td>{{ $ticket->subsystem ? strtoupper($ticket->subsystem) : '—' }}</td>
                                <td>
                                    <div class="log-timestamp">
                                        <span class="log-date">{{ $seen ? $seen->format('M d, Y') : '—' }}</span>
                                        <span class="log-time">{{ $seen ? $seen->format('h:i A') : '' }}</span>
                                        <span class="time-ago">{{ $seen ? $seen->diffForHumans() : '' }}</span>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">
                                    <div class="empty-state">
                                        <i class="fa-solid fa-ticket"></i>
                                        <h3>No tickets found</h3>
                                        <p>No error tickets match your current search or filters.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($tickets->hasPages())
                <div class="pagination-container">
                    <div class="pagination-info">
                        Showing {{ $tickets->firstItem() ?? 0 }} to {{ $tickets->lastItem() ?? 0 }} of {{ $tickets->total() }} entries
                    </div>
                    <div class="pagination-links">
                        @if ($tickets->onFirstPage())
                            <button type="button" class="pagination-btn" disabled>&laquo;</button>
                        @else
                            <button type="button" class="pagination-btn" wire:click="previousPage" wire:loading.attr="disabled">&laquo;</button>
                        @endif

                        @foreach ($tickets->getUrlRange(max(1, $tickets->currentPage() - 2), min($tickets->lastPage(), $tickets->currentPage() + 2)) as $page => $url)
                            @if ($page == $tickets->currentPage())
                                <button type="button" class="pagination-btn active">{{ $page }}</button>
                            @else
                                <button type="button" class="pagination-btn" wire:click="gotoPage({{ $page }})" wire:loading.attr="disabled">{{ $page }}</button>
                            @endif
                        @endforeach

                        @if ($tickets->hasMorePages())
                            <button type="button" class="pagination-btn" wire:click="nextPage" wire:loading.attr="disabled">&raquo;</button>
                        @else
                            <button type="button" class="pagination-btn" disabled>&raquo;</button>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    @endif

    @if($showDetail && $selected)
        <div class="ticket-modal-overlay" wire:click.self="closeDetail">
            <div class="ticket-modal" role="dialog" aria-modal="true">
                <h2>{{ $selected->ticket_number }}</h2>
                <p class="sub">{{ $selected->title }}</p>

                <div class="ticket-meta">
                    <div><strong>Source:</strong> {{ $selected->source }} @if($selected->reference) · <strong>Ref:</strong> {{ $selected->reference }} @endif</div>
                    <div><strong>URL:</strong> {{ $selected->url ?: '—' }}</div>
                    <div><strong>Subsystem:</strong> {{ $selected->subsystem ? strtoupper($selected->subsystem) : '—' }} · <strong>HTTP:</strong> {{ $selected->http_status ?: '—' }}</div>
                    @if($selected->exception_class || $selected->file)
                        <div><strong>Exception:</strong> {{ $selected->exception_class ?: '—' }}</div>
                        <div><strong>File:</strong> {{ $selected->file }}@if($selected->line) :{{ $selected->line }}@endif</div>
                    @endif
                    <div><strong>Reporter:</strong> {{ $selected->reporter_username ?: 'System' }} · <strong>Occurrences:</strong> {{ $selected->occurrence_count }}</div>
                    @if($selected->description)
                        <div style="margin-top:8px;"><strong>Description</strong><br>{{ $selected->description }}</div>
                    @endif
                </div>

                <div class="ticket-grid">
                    <label>Status
                        <select wire:model="editStatus">
                            <option value="open">Open</option>
                            <option value="in_progress">In Progress</option>
                            <option value="resolved">Resolved</option>
                            <option value="closed">Closed</option>
                        </select>
                    </label>
                    <label>Priority
                        <select wire:model="editPriority">
                            <option value="low">Low</option>
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                            <option value="critical">Critical</option>
                        </select>
                    </label>
                    <label>Assign to
                        <select wire:model="editAssignedTo">
                            <option value="">Unassigned</option>
                            @foreach($admins as $admin)
                                <option value="{{ $admin->id }}">{{ $admin->username }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="ticket-notes">Admin notes
                        <textarea wire:model="editAdminNotes" rows="4" maxlength="5000" placeholder="Internal triage notes…"></textarea>
                    </label>
                </div>

                <div class="ticket-actions">
                    <button type="button" class="ticket-btn" wire:click="closeDetail">Cancel</button>
                    <button type="button" class="ticket-btn primary" wire:click="saveTicket" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="saveTicket">Save changes</span>
                        <span wire:loading wire:target="saveTicket">Saving…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
