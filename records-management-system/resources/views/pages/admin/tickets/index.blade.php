<?php
/**
 * Admin Console - Error Tracking & Tickets
 * Miller Columns & Inspector Layout (Matching /admin/rdp/brackets)
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

    public ?int $selectedId = null;
    public string $editStatus = 'open';
    public string $editPriority = 'medium';
    public string $editAssignedTo = '';
    public string $editAdminNotes = '';

    public ?string $successMessage = null;
    public ?string $errorMessage = null;

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if (! $perms || (! $perms->is_sadm && ! $perms->can_access_activity_logs)) {
            $this->redirect(route('portal'));
            return;
        }

        $this->autoSelectFirstTicket();
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
        $this->clearMessages();
        $this->resetPage();
        $this->autoSelectFirstTicket();
    }

    public function clearMessages(): void
    {
        $this->successMessage = null;
        $this->errorMessage = null;
    }

    public function setCategoryFilter(string $status = '', string $priority = '', string $source = ''): void
    {
        $this->statusFilter = $status;
        $this->priorityFilter = $priority;
        $this->sourceFilter = $source;
        $this->clearMessages();
        $this->resetPage();
        $this->autoSelectFirstTicket();
    }

    public function selectTicket(int $id): void
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
        $this->clearMessages();
    }

    public function autoSelectFirstTicket(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('sys_error_tickets')) {
            return;
        }

        $first = $this->baseQuery()->orderByDesc('t.last_seen_at')->orderByDesc('t.created_at')->first();
        if ($first) {
            $this->selectTicket($first->id);
        } else {
        $this->selectedId = null;
        }
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
            'editStatus' => 'required|in:open,in_progress,notice,resolved,closed',
            'editPriority' => 'required|in:low,medium,high,critical',
            'editAssignedTo' => 'nullable|integer',
            'editAdminNotes' => 'nullable|string|max:5000',
        ]);

        $ticket = \App\Models\ErrorTicket::query()->find($this->selectedId);
        if (! $ticket) {
            $this->errorMessage = 'Ticket not found.';
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

        $this->successMessage = "Ticket {$ticket->ticket_number} updated successfully.";
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
                $sourceLabel = match ($row->source) {
                    'user' => 'User Added',
                    'user_auto' => 'User Added | Auto',
                    default => 'Automatically Detected',
                };

                fputcsv($handle, [
                    $row->ticket_number,
                    $sourceLabel,
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
            'resolved' => 0,
            'closed' => 0,
            'critical' => 0,
            'total' => 0,
            'source_user' => 0,
            'source_auto' => 0,
            'source_user_auto' => 0,
        ];

        $tickets = new \Illuminate\Pagination\LengthAwarePaginator([], 0, 15);
        $selected = null;
        $admins = collect();

        if ($hasTable) {
            $stats = [
                'open' => \DB::table('sys_error_tickets')->where('status', 'open')->count(),
                'in_progress' => \DB::table('sys_error_tickets')->where('status', 'in_progress')->count(),
                'notice' => \DB::table('sys_error_tickets')->where('status', 'notice')->count(),
                'resolved' => \DB::table('sys_error_tickets')->where('status', 'resolved')->count(),
                'closed' => \DB::table('sys_error_tickets')->where('status', 'closed')->count(),
                'critical' => \DB::table('sys_error_tickets')->where('priority', 'critical')->whereIn('status', ['open', 'in_progress'])->count(),
                'total' => \DB::table('sys_error_tickets')->count(),
                'source_user' => \DB::table('sys_error_tickets')->where('source', 'user')->count(),
                'source_auto' => \DB::table('sys_error_tickets')->where('source', 'auto')->count(),
                'source_user_auto' => \DB::table('sys_error_tickets')->where('source', 'user_auto')->count(),
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        .miller-container {
            max-width: 1560px;
            margin: 0 auto;
            padding: 16px 20px;
            height: calc(100vh - 100px);
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
            user-select: none;
            font-family: Inter, system-ui, -apple-system, sans-serif;
        }

        /* Top Header & Actions */
        .m-top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .m-top-title {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .m-top-title h1 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.02em;
        }
        .m-top-title p {
            margin: 2px 0 0 0;
            font-size: 0.8rem;
            color: #64748b;
        }
        .m-top-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        /* Quick Filter Source Pills in Top Bar */
        .source-pills-bar {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .source-pill-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 600;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #475569;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .source-pill-btn:hover {
            background: #f1f5f9;
            border-color: #94a3b8;
            color: #0f172a;
        }
        .source-pill-btn.active {
            background: #0284c7;
            border-color: #0284c7;
            color: #ffffff;
        }
        .source-pill-btn .pill-num {
            padding: 1px 6px;
            border-radius: 9999px;
            background: rgba(0, 0, 0, 0.08);
            font-size: 11px;
            font-weight: 700;
        }
        .source-pill-btn.active .pill-num {
            background: rgba(255, 255, 255, 0.25);
            color: #ffffff;
        }

        /* 3-Column Miller Layout (Filters/Categories, Ticket Queue, Inspector) */
        .miller-browser {
            flex: 1 1 auto;
            display: grid;
            grid-template-columns: 240px 1fr 450px;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 10px 10px 0 0;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
            min-height: 540px;
        }
        .miller-column {
            border-right: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            background: #ffffff;
            min-height: 0;
            position: relative;
        }
        .miller-column:nth-child(even) {
            background: #fafbfc;
        }

        .m-col-header {
            padding: 11px 14px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 12px;
            font-weight: 800;
            color: #334155;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .m-col-header span {
            display: flex;
            align-items: center;
            gap: 7px;
        }
        .m-col-search {
            padding: 8px 10px;
            border-bottom: 1px solid #f1f5f9;
            background: #ffffff;
            display: flex;
            gap: 6px;
            align-items: center;
        }
        .m-col-search input {
            width: 100%;
            padding: 6px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 12px;
            outline: none;
            box-sizing: border-box;
            background: #ffffff;
        }
        .m-col-search input:focus {
            border-color: #0284c7;
        }
        .m-col-search select {
            padding: 5px 8px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 11.5px;
            background: #ffffff;
            color: #334155;
            outline: none;
        }

        .m-col-list {
            flex: 1 1 auto;
            overflow-y: auto;
            padding: 4px 0;
        }

        /* Stream / Section Header in Col 1 */
        .m-section-label {
            padding: 8px 14px 4px 14px;
            font-size: 10.5px;
            font-weight: 800;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        /* Queue List Items */
        .m-item {
            padding: 10px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 13px;
            color: #334155;
            cursor: pointer;
            user-select: none;
            transition: all 0.1s ease;
            border-left: 3px solid transparent;
            border-bottom: 1px solid #f1f5f9;
        }
        .m-item:hover {
            background: #f1f5f9;
            color: #0f172a;
        }
        .m-item.active {
            background: #0284c7;
            color: #ffffff;
            border-left-color: #0369a1;
            font-weight: 600;
        }
        .m-item.active .m-item-icon {
            color: #ffffff !important;
        }
        .m-item.active .m-item-badge {
            background: rgba(255, 255, 255, 0.25);
            color: #ffffff;
        }
        .m-item.active .m-item-arrow {
            color: #ffffff;
        }
        .m-item.active .m-item-subtext,
        .m-item.active .m-item-tag {
            color: rgba(255, 255, 255, 0.85);
        }
        .m-item-left {
            display: flex;
            align-items: center;
            gap: 10px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            flex: 1;
        }
        .m-item-icon {
            font-size: 13px;
            color: #64748b;
            flex-shrink: 0;
            width: 16px;
            text-align: center;
        }
        .m-item-title {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 13px;
        }
        .m-item-subtext {
            font-size: 11px;
            color: #64748b;
            margin-top: 1px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .m-item-badge {
            font-size: 11px;
            padding: 1px 7px;
            border-radius: 10px;
            background: #e2e8f0;
            color: #475569;
            font-weight: 700;
            flex-shrink: 0;
        }
        .m-item-arrow {
            font-size: 11px;
            color: #94a3b8;
            margin-left: 8px;
            flex-shrink: 0;
        }

        /* Status Dot Indicator */
        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
            flex-shrink: 0;
        }
        .status-dot.open { background: #2563eb; }
        .status-dot.in_progress { background: #d97706; }
        .status-dot.notice { background: #06b6d4; }
        .status-dot.resolved { background: #16a34a; }
        .status-dot.closed { background: #64748b; }

        .notice-pill {
            font-size: 10px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 4px;
            background: #ecfeff;
            color: #0891b2;
            border: 1px solid #cffafe;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        /* Priority Badge Pills */
        .priority-pill {
            font-size: 10.5px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            flex-shrink: 0;
        }
        .priority-pill.critical { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .priority-pill.high { background: #fff7ed; color: #ea580c; border: 1px solid #fed7aa; }
        .priority-pill.medium { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
        .priority-pill.low { background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; }

        /* Source Badge Tag */
        .source-tag {
            font-size: 10.5px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .source-tag.user { background: #e0f2fe; color: #0284c7; }
        .source-tag.auto { background: #fef3c7; color: #d97706; }
        .source-tag.user_auto { background: #f3e8ff; color: #9333ea; }

        /* Tool Buttons */
        .m-tool-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: #ffffff;
            color: #334155;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .m-tool-btn:hover {
            background: #f1f5f9;
            color: #0f172a;
            border-color: #94a3b8;
        }
        .m-tool-btn.primary {
            background: #0284c7;
            color: #ffffff;
            border-color: #0284c7;
        }
        .m-tool-btn.primary:hover {
            background: #0369a1;
            border-color: #0369a1;
        }

        /* Inspector Pane (Right Side) */
        .m-inspector {
            background: #ffffff;
            display: flex;
            flex-direction: column;
            padding: 16px 18px;
            overflow-y: auto;
            position: relative;
        }
        .m-inspector-preview {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            padding: 12px 0 16px 0;
            border-bottom: 1px solid #e2e8f0;
            margin-bottom: 16px;
        }
        .m-inspector-icon-wrap {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            background: #f1f5f9;
            display: grid;
            place-items: center;
            margin-bottom: 10px;
            color: #0284c7;
            font-size: 24px;
        }
        .m-inspector-title {
            font-size: 15px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.35;
            margin: 0 0 6px 0;
            word-break: break-word;
        }
        .m-inspector-kind {
            font-size: 11.5px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .m-inspector-section-title {
            font-size: 11px;
            font-weight: 800;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin: 14px 0 8px 0;
        }
        .m-inspector-prop {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            font-size: 12px;
            margin-bottom: 8px;
            color: #64748b;
            gap: 12px;
        }
        .m-inspector-prop strong {
            color: #1e293b;
            text-align: right;
            word-break: break-all;
        }
        .m-inspector-pre {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 10px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 11.5px;
            line-height: 1.45;
            color: #334155;
            max-height: 160px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-break: break-word;
            margin: 6px 0 12px 0;
        }

        /* Triage Form in Inspector */
        .triage-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px;
            margin-top: 10px;
        }
        .triage-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .triage-grid label {
            display: block;
            font-size: 11.5px;
            font-weight: 700;
            color: #475569;
        }
        .triage-grid select,
        .triage-grid textarea {
            width: 100%;
            margin-top: 4px;
            padding: 6px 9px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 12px;
            background: #ffffff;
            color: #1e293b;
            box-sizing: border-box;
            outline: none;
        }
        .triage-grid select:focus,
        .triage-grid textarea:focus {
            border-color: #0284c7;
        }
        .triage-full {
            grid-column: 1 / -1;
        }

        /* Bottom Breadcrumb / Path Bar */
        .miller-path-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-top: none;
            border-radius: 0 0 10px 10px;
            padding: 8px 16px;
            font-size: 12px;
            color: #475569;
            overflow-x: auto;
            white-space: nowrap;
        }
        .miller-path-tag {
            font-weight: 700;
            color: #0f172a;
        }

        /* Mini Pagination in Column 2 */
        .m-col-pagination {
            padding: 8px 12px;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 11.5px;
            color: #64748b;
        }
        .m-pager-btn {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            padding: 3px 8px;
            font-size: 11.5px;
            color: #334155;
            cursor: pointer;
        }
        .m-pager-btn:hover:not(:disabled) {
            background: #f1f5f9;
            border-color: #94a3b8;
        }
        .m-pager-btn:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }

        /* Responsive Layout */
        @media (max-width: 1200px) {
            .miller-browser {
                grid-template-columns: 220px 1fr 390px;
            }
        }
        @media (max-width: 992px) {
            .miller-container {
                height: auto;
            }
            .miller-browser {
                display: flex;
                flex-direction: column;
                height: auto;
            }
            .miller-column {
                border-right: none;
                border-bottom: 1px solid #e2e8f0;
                min-height: 260px;
            }
            .m-inspector {
                min-height: 380px;
            }
        }

        /* Dark Mode Overrides (Matching /admin/rdp/brackets) */
        [data-theme="dark"] .miller-browser,
        [data-theme="dark"] .miller-column,
        [data-theme="dark"] .m-inspector {
            background: #1e293b;
            border-color: #334155;
        }
        [data-theme="dark"] .miller-column:nth-child(even) {
            background: #182234;
        }
        [data-theme="dark"] .m-col-header,
        [data-theme="dark"] .miller-path-bar,
        [data-theme="dark"] .m-col-pagination {
            background: #0f172a;
            border-color: #334155;
            color: #f1f5f9;
        }
        [data-theme="dark"] .m-top-title h1 {
            color: #f8fafc;
        }
        [data-theme="dark"] .m-col-search {
            background: #1e293b;
            border-color: #334155;
        }
        [data-theme="dark"] .m-col-search input,
        [data-theme="dark"] .m-col-search select {
            background: #0f172a;
            border-color: #334155;
            color: #f1f5f9;
        }
        [data-theme="dark"] .source-pill-btn {
            background: #1e293b;
            border-color: #334155;
            color: #cbd5e1;
        }
        [data-theme="dark"] .source-pill-btn:hover {
            background: #334155;
            border-color: #475569;
            color: #f8fafc;
        }
        [data-theme="dark"] .source-pill-btn.active {
            background: #0284c7;
            border-color: #0284c7;
            color: #ffffff;
        }
        [data-theme="dark"] .m-item {
            color: #cbd5e1;
            border-bottom-color: #1e293b;
        }
        [data-theme="dark"] .m-item:hover {
            background: #334155;
            color: #f8fafc;
        }
        [data-theme="dark"] .m-item.active {
            background: #0284c7;
            color: #ffffff;
        }
        [data-theme="dark"] .m-item-badge {
            background: #334155;
            color: #cbd5e1;
        }
        [data-theme="dark"] .m-tool-btn {
            background: #1e293b;
            border-color: #334155;
            color: #e2e8f0;
        }
        [data-theme="dark"] .m-tool-btn:hover {
            background: #0f172a;
        }
        [data-theme="dark"] .m-inspector-title {
            color: #f1f5f9;
        }
        [data-theme="dark"] .m-inspector-icon-wrap {
            background: #0f172a;
        }
        [data-theme="dark"] .m-inspector-preview {
            border-bottom-color: #334155;
        }
        [data-theme="dark"] .m-inspector-prop strong {
            color: #e2e8f0;
        }
        [data-theme="dark"] .m-inspector-pre {
            background: #0f172a;
            border-color: #334155;
            color: #e2e8f0;
        }
        [data-theme="dark"] .triage-box {
            background: #0f172a;
            border-color: #334155;
        }
        [data-theme="dark"] .triage-grid label {
            color: #94a3b8;
        }
        [data-theme="dark"] .triage-grid select,
        [data-theme="dark"] .triage-grid textarea {
            background: #1e293b;
            border-color: #334155;
            color: #f8fafc;
        }
        [data-theme="dark"] .m-pager-btn {
            background: #1e293b;
            border-color: #334155;
            color: #e2e8f0;
        }
        [data-theme="dark"] .status-dot.notice { background: #22d3ee; }
        [data-theme="dark"] .notice-pill {
            background: rgba(6, 182, 212, 0.16);
            color: #22d3ee;
            border-color: rgba(6, 182, 212, 0.35);
        }
        [data-theme="dark"] .miller-path-tag {
            color: #f1f5f9;
        }
    </style>
@endpush

<div class="miller-container">
    {{-- Top Title & Quick Filters Bar --}}
    <div class="m-top-bar">
        <div class="m-top-title">
            <i class="fa-solid fa-ticket-simple" style="font-size: 24px; color: #0284c7;"></i>
            <div>
                <h1>Error Tracking & Tickets</h1>
                <p>Triage auto-captured system exceptions and user-submitted incident reports.</p>
            </div>
            </div>

        <div class="m-top-actions">
            {{-- Quick Source Filter Pills --}}
            <div class="source-pills-bar">
                <button type="button" wire:click="setCategoryFilter('', '', '')" class="source-pill-btn {{ $sourceFilter === '' && $statusFilter === '' && $priorityFilter === '' ? 'active' : '' }}">
                    <span>All</span>
                    <span class="pill-num">{{ $stats['total'] }}</span>
                </button>
                <button type="button" wire:click="setCategoryFilter('', '', 'user')" class="source-pill-btn {{ $sourceFilter === 'user' ? 'active' : '' }}">
                    <i class="fa-solid fa-user-pen"></i>
                    <span>User Added</span>
                    <span class="pill-num">{{ $stats['source_user'] }}</span>
                </button>
                <button type="button" wire:click="setCategoryFilter('', '', 'auto')" class="source-pill-btn {{ $sourceFilter === 'auto' ? 'active' : '' }}">
                    <i class="fa-solid fa-robot"></i>
                    <span>Auto Detected</span>
                    <span class="pill-num">{{ $stats['source_auto'] }}</span>
                </button>
                <button type="button" wire:click="setCategoryFilter('', '', 'user_auto')" class="source-pill-btn {{ $sourceFilter === 'user_auto' ? 'active' : '' }}">
                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                    <span>User Added | Auto</span>
                    <span class="pill-num">{{ $stats['source_user_auto'] }}</span>
                </button>
            </div>

            <button type="button" class="m-tool-btn" wire:click="exportCsv" wire:loading.attr="disabled" title="Export tickets as CSV">
                <i class="fa-solid fa-file-csv" wire:loading.remove wire:target="exportCsv"></i>
                <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="exportCsv"></i>
                <span>Export CSV</span>
            </button>

            @if($search !== '' || $statusFilter !== '' || $priorityFilter !== '' || $sourceFilter !== '')
                <button type="button" class="m-tool-btn" wire:click="clearFilters" title="Reset all search & filters">
                    <i class="fa-solid fa-filter-circle-xmark"></i>
                    <span>Reset</span>
                </button>
            @endif
        </div>
    </div>

    {{-- Alert Messages --}}
    @if($successMessage)
        <div role="status" style="margin-bottom: 10px; padding: 9px 14px; border: 1px solid #bbf7d0; border-radius: 8px; background: #f0fdf4; color: #15803d; font-size: 12.5px; font-weight: 600; display: flex; align-items: center; justify-content: space-between;">
            <div><i class="fa-solid fa-circle-check" style="margin-right: 8px;"></i> {{ $successMessage }}</div>
            <button type="button" wire:click="clearMessages" style="background: none; border: none; color: #15803d; cursor: pointer; font-size: 16px;">&times;</button>
        </div>
    @endif

    @if($errorMessage)
        <div role="alert" style="margin-bottom: 10px; padding: 9px 14px; border: 1px solid #fecaca; border-radius: 8px; background: #fef2f2; color: #b91c1c; font-size: 12.5px; font-weight: 600; display: flex; align-items: center; justify-content: space-between;">
            <div><i class="fa-solid fa-triangle-exclamation" style="margin-right: 8px;"></i> {{ $errorMessage }}</div>
            <button type="button" wire:click="clearMessages" style="background: none; border: none; color: #b91c1c; cursor: pointer; font-size: 16px;">&times;</button>
        </div>
    @endif

    @if(!$hasTable)
        <div class="miller-browser" style="display: flex; align-items: center; justify-content: center; padding: 40px; text-align: center;">
            <div>
                <i class="fa-solid fa-database" style="font-size: 40px; color: #94a3b8; margin-bottom: 12px; display: block;"></i>
                <h3 style="margin: 0 0 6px 0; color: #334155;">Tickets Table Not Ready</h3>
                <p style="color: #64748b; font-size: 13px;">Please run migrations to create the <code>sys_error_tickets</code> table, then reload.</p>
            </div>
        </div>
    @else
        {{-- 3-Column Miller Browser --}}
        <div class="miller-browser">
            {{-- ========================================================
                 COLUMN 1: QUEUES & CATEGORIES
                 ======================================================== --}}
            <div class="miller-column">
                <div class="m-col-header">
                    <span><i class="fa-solid fa-folder-tree"></i> 1. Queues</span>
                    <span class="m-item-badge">{{ $stats['total'] }}</span>
                </div>

                <div class="m-col-list">
                    {{-- Status Queues --}}
                    <div class="m-section-label">By Status</div>

                    <div class="m-item {{ $statusFilter === '' && $priorityFilter === '' && $sourceFilter === '' ? 'active' : '' }}"
                         wire:click="setCategoryFilter('', '', '')">
                        <div class="m-item-left">
                            <i class="fa-solid fa-inbox m-item-icon"></i>
                            <span class="m-item-title">All Tickets</span>
            </div>
                        <span class="m-item-badge">{{ $stats['total'] }}</span>
                </div>

                    <div class="m-item {{ $statusFilter === 'open' && $priorityFilter === '' ? 'active' : '' }}"
                         wire:click="setCategoryFilter('open', '', '')">
                        <div class="m-item-left">
                            <span class="status-dot open" style="margin-right: 2px;"></span>
                            <span class="m-item-title">Open Tickets</span>
            </div>
                        <span class="m-item-badge">{{ $stats['open'] }}</span>
                </div>

                    <div class="m-item {{ $statusFilter === 'in_progress' ? 'active' : '' }}"
                         wire:click="setCategoryFilter('in_progress', '', '')">
                        <div class="m-item-left">
                            <span class="status-dot in_progress" style="margin-right: 2px;"></span>
                            <span class="m-item-title">In Progress</span>
            </div>
                        <span class="m-item-badge">{{ $stats['in_progress'] }}</span>
                </div>

                    <div class="m-item {{ $statusFilter === 'notice' ? 'active' : '' }}"
                         wire:click="setCategoryFilter('notice', '', '')">
                        <div class="m-item-left">
                            <span class="status-dot notice" style="margin-right: 2px;"></span>
                            <span class="m-item-title">Notice (Gentle Notice)</span>
            </div>
                        <span class="m-item-badge" style="{{ $stats['notice'] > 0 ? 'background: #ecfeff; color: #0891b2;' : '' }}">{{ $stats['notice'] }}</span>
        </div>

                    <div class="m-item {{ $statusFilter === 'resolved' ? 'active' : '' }}"
                         wire:click="setCategoryFilter('resolved', '', '')">
                        <div class="m-item-left">
                            <span class="status-dot resolved" style="margin-right: 2px;"></span>
                            <span class="m-item-title">Resolved</span>
                        </div>
                        <span class="m-item-badge">{{ $stats['resolved'] }}</span>
                    </div>

                    <div class="m-item {{ $statusFilter === 'closed' ? 'active' : '' }}"
                         wire:click="setCategoryFilter('closed', '', '')">
                        <div class="m-item-left">
                            <span class="status-dot closed" style="margin-right: 2px;"></span>
                            <span class="m-item-title">Closed</span>
                        </div>
                        <span class="m-item-badge">{{ $stats['closed'] }}</span>
                    </div>

                    <div class="m-item {{ $priorityFilter === 'critical' ? 'active' : '' }}"
                         wire:click="setCategoryFilter('open', 'critical', '')">
                        <div class="m-item-left">
                            <i class="fa-solid fa-bolt m-item-icon" style="color: #dc2626;"></i>
                            <span class="m-item-title">Critical Open</span>
                        </div>
                        <span class="m-item-badge" style="background: #fef2f2; color: #dc2626;">{{ $stats['critical'] }}</span>
                    </div>

                    {{-- Source Divisions --}}
                    <div class="m-section-label" style="margin-top: 10px;">By Origin</div>

                    <div class="m-item {{ $sourceFilter === 'user' ? 'active' : '' }}"
                         wire:click="setCategoryFilter('', '', 'user')">
                        <div class="m-item-left">
                            <i class="fa-solid fa-user-pen m-item-icon" style="color: #0284c7;"></i>
                            <span class="m-item-title">User Added</span>
                        </div>
                        <span class="m-item-badge">{{ $stats['source_user'] }}</span>
                    </div>

                    <div class="m-item {{ $sourceFilter === 'auto' ? 'active' : '' }}"
                         wire:click="setCategoryFilter('', '', 'auto')">
                        <div class="m-item-left">
                            <i class="fa-solid fa-robot m-item-icon" style="color: #d97706;"></i>
                            <span class="m-item-title">Auto Detected</span>
                        </div>
                        <span class="m-item-badge">{{ $stats['source_auto'] }}</span>
                    </div>

                    <div class="m-item {{ $sourceFilter === 'user_auto' ? 'active' : '' }}"
                         wire:click="setCategoryFilter('', '', 'user_auto')">
                        <div class="m-item-left">
                            <i class="fa-solid fa-wand-magic-sparkles m-item-icon" style="color: #9333ea;"></i>
                            <span class="m-item-title">User Added | Auto</span>
                        </div>
                        <span class="m-item-badge">{{ $stats['source_user_auto'] }}</span>
                    </div>
                </div>
            </div>

            {{-- ========================================================
                 COLUMN 2: TICKET QUEUE LIST
                 ======================================================== --}}
            <div class="miller-column">
                <div class="m-col-header">
                    <span><i class="fa-solid fa-list-check"></i> 2. Ticket Queue</span>
                    <span class="m-item-badge">{{ $tickets->total() }}</span>
                </div>

                {{-- Live Search & Inline Dropdowns --}}
                <div class="m-col-search">
                    <input type="text"
                           wire:model.live.debounce.300ms="search"
                           placeholder="Search ticket, ref, title, URL, reporter...">

                    <select wire:model.live="statusFilter" title="Filter by status">
                        <option value="">Status: All</option>
                        <option value="open">Open</option>
                        <option value="in_progress">In Progress</option>
                        <option value="notice">Notice</option>
                        <option value="resolved">Resolved</option>
                        <option value="closed">Closed</option>
                    </select>

                    <select wire:model.live="priorityFilter" title="Filter by priority">
                        <option value="">Priority: All</option>
                        <option value="critical">Critical</option>
                        <option value="high">High</option>
                        <option value="medium">Medium</option>
                        <option value="low">Low</option>
                    </select>
                </div>

                <div class="m-col-list">
                    @forelse($tickets as $t)
                        @php
                            $isSelected = $selectedId === (int) $t->id;
                            $seen = $t->last_seen_at ? \Carbon\Carbon::parse($t->last_seen_at) : ($t->created_at ? \Carbon\Carbon::parse($t->created_at) : null);
                        @endphp
                        <div class="m-item {{ $isSelected ? 'active' : '' }}"
                             wire:click="selectTicket({{ $t->id }})"
                             wire:key="queue-ticket-{{ $t->id }}">
                            <div class="m-item-left">
                                <span class="status-dot {{ $t->status }}" title="Status: {{ $t->status }}"></span>

                                <div style="overflow: hidden; flex: 1;">
                                    <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 2px;">
                                        <strong class="m-item-title" style="font-size: 12.5px;">{{ $t->ticket_number }}</strong>
                                        @if($t->reference && $t->reference !== $t->ticket_number)
                                            <span style="font-size: 10px; opacity: 0.75; font-family: monospace;">{{ $t->reference }}</span>
                                        @endif
                                        @if((int) $t->occurrence_count > 1)
                                            <span class="m-item-badge" style="font-size: 9.5px; padding: 1px 4px;">×{{ $t->occurrence_count }}</span>
                    @endif
                </div>

                                    <div class="m-item-subtext" title="{{ $t->title }}">
                                        {{ $t->title }}
        </div>

                                    <div style="display: flex; align-items: center; gap: 6px; margin-top: 4px; font-size: 11px;">
                                        @if($t->status === 'notice')
                                            <span class="notice-pill">
                                                <i class="fa-solid fa-circle-info" style="font-size: 9.5px;"></i> Notice
                                            </span>
                                        @endif

                                        <span class="source-tag {{ $t->source }}">
                                            @if($t->source === 'user')
                                                <i class="fa-solid fa-user-pen" style="font-size: 9.5px;"></i> User
                                            @elseif($t->source === 'user_auto')
                                                <i class="fa-solid fa-wand-magic-sparkles" style="font-size: 9.5px;"></i> User | Auto
                                            @else
                                                <i class="fa-solid fa-robot" style="font-size: 9.5px;"></i> Auto
                                        @endif
                                    </span>

                                        @if($t->subsystem)
                                            <span style="opacity: 0.75; font-size: 10.5px; text-transform: uppercase;">
                                                {{ $t->subsystem }}
                                    </span>
                                        @endif

                                        @if($seen)
                                            <span style="opacity: 0.65; margin-left: auto; font-size: 10.5px;">
                                                {{ $seen->diffForHumans() }}
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 4px; margin-left: 10px;">
                                <span class="priority-pill {{ $t->priority }}">
                                    {{ $t->priority }}
                                </span>
                                <i class="fa-solid fa-chevron-right m-item-arrow" style="margin-top: 6px;"></i>
                            </div>
                        </div>
                        @empty
                        <div style="padding: 32px 16px; text-align: center; color: #94a3b8; font-size: 13px;">
                            <i class="fa-solid fa-ticket-simple" style="font-size: 28px; margin-bottom: 10px; display: block; opacity: 0.5;"></i>
                            No tickets match the current criteria.
                                    </div>
                        @endforelse
            </div>

                {{-- Column Pagination Footer --}}
            @if($tickets->hasPages())
                    <div class="m-col-pagination">
                        <span>Page {{ $tickets->currentPage() }} of {{ $tickets->lastPage() }}</span>
                        <div style="display: flex; gap: 4px;">
                            <button type="button" class="m-pager-btn" wire:click="previousPage" @disabled($tickets->onFirstPage())>
                                <i class="fa-solid fa-chevron-left"></i>
                            </button>
                            <button type="button" class="m-pager-btn" wire:click="nextPage" @disabled(!$tickets->hasMorePages())>
                                <i class="fa-solid fa-chevron-right"></i>
                            </button>
                    </div>
                    </div>
                        @endif
            </div>

            {{-- ========================================================
                 COLUMN 3: INSPECTOR PANE (MATCHING /admin/rdp/brackets)
                 ======================================================== --}}
            <div class="m-inspector">
                @if($selected)
                    @php
                        $detailSource = match ($selected->source) {
                            'user' => [
                                'bg' => 'rgba(14, 165, 233, 0.12)',
                                'color' => '#0284c7',
                                'border' => 'rgba(14, 165, 233, 0.25)',
                                'icon' => 'fa-user-pen',
                                'label' => 'User Added',
                                'desc' => 'Submitted directly by a user report.'
                            ],
                            'user_auto' => [
                                'bg' => 'rgba(168, 85, 247, 0.15)',
                                'color' => '#9333ea',
                                'border' => 'rgba(168, 85, 247, 0.3)',
                                'icon' => 'fa-wand-magic-sparkles',
                                'label' => 'User Added | Auto',
                                'desc' => 'User encounter captured by automatic diagnostics.'
                            ],
                            default => [
                                'bg' => 'rgba(245, 158, 11, 0.12)',
                                'color' => '#d97706',
                                'border' => 'rgba(245, 158, 11, 0.25)',
                                'icon' => 'fa-robot',
                                'label' => 'Automatically Detected',
                                'desc' => 'Caught by server exception handler or error catcher.'
                            ],
                        };
                        $selSeen = $selected->last_seen_at ? \Carbon\Carbon::parse($selected->last_seen_at) : ($selected->created_at ? \Carbon\Carbon::parse($selected->created_at) : null);
                        $selCreated = $selected->created_at ? \Carbon\Carbon::parse($selected->created_at) : null;
                    @endphp

                    {{-- Top Inspector Preview Header --}}
                    <div class="m-inspector-preview">
                        <div class="m-inspector-icon-wrap">
                            <i class="fa-solid fa-ticket-simple"></i>
                        </div>
                        <div class="m-inspector-title">{{ $selected->ticket_number }}</div>
                        <div class="m-inspector-kind" style="margin-bottom: 8px;">
                            {{ $selected->title }}
                        </div>
                        <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap; justify-content: center;">
                            <span class="source-tag {{ $selected->source }}" style="padding: 3px 8px; font-size: 11px;">
                                <i class="fa-solid {{ $detailSource['icon'] }}"></i> {{ $detailSource['label'] }}
                            </span>
                            <span class="priority-pill {{ $selected->priority }}" style="padding: 3px 8px; font-size: 11px;">
                                {{ ucfirst($selected->priority) }}
                            </span>
                            @if($selected->status === 'notice')
                                <span class="notice-pill" style="padding: 3px 8px; font-size: 11px;">
                                    <i class="fa-solid fa-circle-info"></i> NOTICE (GENTLE NOTICE)
                                </span>
                        @else
                                <span class="priority-pill" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 3px 8px; font-size: 11px;">
                                    {{ strtoupper(str_replace('_', ' ', $selected->status)) }}
                                </span>
                        @endif
                    </div>
                    </div>

                    {{-- Properties Sheet --}}
                    <div class="m-inspector-section-title">Ticket Properties</div>

                    <div class="m-inspector-prop">
                        <span>Reference Code</span>
                        <strong><code>{{ $selected->reference ?: $selected->ticket_number }}</code></strong>
                    </div>

                    <div class="m-inspector-prop">
                        <span>Subsystem</span>
                        <strong>{{ $selected->subsystem ? strtoupper($selected->subsystem) : 'Admin Console' }}</strong>
                    </div>

                    <div class="m-inspector-prop">
                        <span>HTTP Status</span>
                        <strong>{{ $selected->http_status ?: '200' }}</strong>
                    </div>

                    <div class="m-inspector-prop">
                        <span>Occurrences</span>
                        <strong>{{ $selected->occurrence_count }}×</strong>
                    </div>

                    <div class="m-inspector-prop">
                        <span>Reporter</span>
                        <strong>{{ $selected->reporter_username ?: ($selected->source === 'auto' ? 'Automated System' : 'User') }}</strong>
                    </div>

                    <div class="m-inspector-prop">
                        <span>Assignee</span>
                        <strong>{{ $selected->assignee_username ?: 'Unassigned' }}</strong>
                    </div>

                    @if($selected->url)
                        <div class="m-inspector-prop">
                            <span>Request URL</span>
                            <strong style="max-width: 220px; font-size: 11px;">{{ $selected->url }}</strong>
                </div>
            @endif

                    @if($selected->exception_class)
                        <div class="m-inspector-prop">
                            <span>Exception</span>
                            <strong style="max-width: 220px; font-size: 11px;">{{ class_basename($selected->exception_class) }}</strong>
        </div>
    @endif

                    @if($selected->file)
                        <div class="m-inspector-prop">
                            <span>Location</span>
                            <strong style="max-width: 220px; font-size: 11px;">{{ basename($selected->file) }}{{ $selected->line ? ':'.$selected->line : '' }}</strong>
                        </div>
                    @endif

                    <div class="m-inspector-prop">
                        <span>First Created</span>
                        <strong>{{ $selCreated ? $selCreated->format('M d, Y h:i A') : '—' }}</strong>
                    </div>

                    <div class="m-inspector-prop">
                        <span>Last Seen</span>
                        <strong>{{ $selSeen ? $selSeen->diffForHumans() : '—' }}</strong>
                    </div>

                    {{-- Diagnostics / Description Box --}}
                    @if($selected->description)
                        <div class="m-inspector-section-title" style="margin-top: 12px;">Diagnostics & User Notes</div>
                        <div class="m-inspector-pre">{{ $selected->description }}</div>
                    @endif

                    {{-- Notice Explanatory Banner --}}
                    @if($selected->status === 'notice')
                        <div style="margin-top: 12px; margin-bottom: 8px; padding: 10px 14px; background: #ecfeff; border: 1px solid #a5f3fc; border-radius: 8px; font-size: 12px; color: #0e7490; display: flex; align-items: flex-start; gap: 8px;">
                            <i class="fa-solid fa-circle-info" style="font-size: 14px; margin-top: 2px; color: #0891b2; flex-shrink: 0;"></i>
                            <div>
                                <strong style="color: #0891b2;">Gentle Notice:</strong> This ticket is logged as a notice (slow internet / connection timeout). It does not require technical debugging or code fixes.
                </div>
                        </div>
                    @endif

                    {{-- Inline Triage Form --}}
                    <div class="m-inspector-section-title" style="margin-top: 14px;">Triage & Resolution</div>

                    <div class="triage-box">
                        <div class="triage-grid">
                            <label>
                                Status
                        <select wire:model="editStatus">
                            <option value="open">Open</option>
                            <option value="in_progress">In Progress</option>
                                    <option value="notice">Notice (Gentle Notice - No Action Required)</option>
                            <option value="resolved">Resolved</option>
                            <option value="closed">Closed</option>
                        </select>
                    </label>

                            <label>
                                Priority
                        <select wire:model="editPriority">
                            <option value="low">Low</option>
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                            <option value="critical">Critical</option>
                        </select>
                    </label>

                            <label class="triage-full">
                                Assigned Admin
                        <select wire:model="editAssignedTo">
                            <option value="">Unassigned</option>
                            @foreach($admins as $admin)
                                <option value="{{ $admin->id }}">{{ $admin->username }}</option>
                            @endforeach
                        </select>
                    </label>

                            <label class="triage-full">
                                Internal Admin Notes
                                <textarea wire:model="editAdminNotes" rows="3" placeholder="Add resolution details or triage notes..."></textarea>
                    </label>
                </div>

                        <div style="margin-top: 12px; display: flex; justify-content: flex-end;">
                            <button type="button" class="m-tool-btn primary" wire:click="saveTicket" wire:loading.attr="disabled" style="width: 100%; justify-content: center;">
                                <i class="fa-solid fa-floppy-disk" wire:loading.remove wire:target="saveTicket"></i>
                                <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="saveTicket"></i>
                                <span wire:loading.remove wire:target="saveTicket">Save Triage Changes</span>
                                <span wire:loading wire:target="saveTicket">Saving...</span>
                    </button>
                </div>
            </div>
                @else
                    {{-- Empty State when no ticket is selected --}}
                    <div style="padding: 40px 20px; text-align: center; color: #94a3b8; font-size: 13px; margin: auto;">
                        <i class="fa-solid fa-ticket-simple" style="font-size: 42px; margin-bottom: 14px; display: block; opacity: 0.5;"></i>
                        <div style="font-weight: 700; color: #475569; font-size: 14px; margin-bottom: 6px;">No Ticket Selected</div>
                        Select any ticket from the queue to inspect error diagnostics, properties, and perform triage.
                    </div>
                @endif
            </div>
        </div>

        {{-- Bottom Breadcrumb / Path Bar (Matching /admin/rdp/brackets) --}}
        <div class="miller-path-bar">
            <div style="display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-terminal" style="font-size: 12px; color: #64748b;"></i>
                <span>Path:</span>
                <span class="miller-path-tag">
                    @if($sourceFilter)
                        [{{ match($sourceFilter) { 'user' => 'User Added', 'auto' => 'Auto Detected', 'user_auto' => 'User Added | Auto', default => $sourceFilter } }}]
                    @else
                        [All Sources]
                    @endif
                </span>
                <span>&gt;</span>
                <span class="miller-path-tag">
                    [{{ $statusFilter ? ucfirst(str_replace('_', ' ', $statusFilter)) : 'All Statuses' }}]
                </span>
                @if($selected)
                    <span>&gt;</span>
                    <span class="miller-path-tag" style="color: #0284c7;">
                        [{{ $selected->ticket_number }}]
                    </span>
                    @if($selected->reference && $selected->reference !== $selected->ticket_number)
                        <span style="font-size: 11px; color: #64748b;">({{ $selected->reference }})</span>
                    @endif
                @endif
            </div>

            <div style="font-size: 11.5px; color: #64748b; display: flex; align-items: center; gap: 12px;">
                <span><strong>{{ $stats['open'] }}</strong> Open</span>
                <span>&bull;</span>
                <span style="color: #0891b2;"><strong>{{ $stats['notice'] ?? 0 }}</strong> Notice</span>
                <span>&bull;</span>
                <span style="color: #dc2626;"><strong>{{ $stats['critical'] }}</strong> Critical</span>
                <span>&bull;</span>
                <span><strong>{{ $stats['total'] }}</strong> Total</span>
            </div>
        </div>
    @endif
</div>
