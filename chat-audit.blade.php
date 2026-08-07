<?php
/**
 * Admin Console - Chatify Audit Trail
 *
 * Two tabs:
 *   1. Audit Log  — paginated log of all user actions inside Chatify
 *   2. Chat Backups — list of archived backup sessions with download
 *
 * Data sources:
 *   - chatify_audit_logs        (Audit Log tab)
 *   - chatify_chat_backup       (Chat Backups tab)
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Admin Console - Chat Audit Trail')] class extends Component {
    use WithPagination;

    public string $activeTab    = 'audit';
    public string $search       = '';
    public string $actionFilter = '';
    public string $dateFrom     = '';
    public string $dateTo       = '';

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if (!$perms || (!$perms->is_sadm && !$perms->can_access_activity_logs)) {
            $this->redirect(route('portal'));
            return;
        }
    }

    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetPage();
    }

    public function updatingSearch(): void      { $this->resetPage(); }
    public function updatingActionFilter(): void { $this->resetPage(); }
    public function updatingDateFrom(): void    { $this->resetPage(); }
    public function updatingDateTo(): void      { $this->resetPage(); }

    public function clearFilters(): void
    {
        $this->search       = '';
        $this->actionFilter = '';
        $this->dateFrom     = '';
        $this->dateTo       = '';
        $this->resetPage();
    }

    private function actionLabel(string $action): string
    {
        return match($action) {
            'send_message'   => 'Sent Global Message',
            'send_dm'        => 'Sent Direct Message',
            'edit_message'   => 'Edited Message',
            'clear_chat'     => 'Cleared Conversation',
            'clear_all_chat' => 'Deleted All Chats',
            'delete_message' => 'Deleted Conversation',
            'upload_file'    => 'Uploaded File (Global)',
            'upload_dm_file' => 'Uploaded File (DM)',
            default          => ucwords(str_replace('_', ' ', $action)),
        };
    }

    public function with(): array
    {
        // ── Audit Log tab ────────────────────────────────────────────────────
        $query = \DB::table('chatify_audit_logs as cal')
            ->leftJoin('account as a', 'cal.account_id', '=', 'a.id')
            ->leftJoin('account_details as ad', 'a.id', '=', 'ad.account_id')
            ->select([
                'cal.id', 'cal.account_id', 'cal.action',
                'cal.target_id', 'cal.meta', 'cal.ip_address', 'cal.created_at',
                'a.username', 'ad.first_name', 'ad.last_name',
            ]);

        if ($this->search !== '') {
            $s = '%' . $this->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('a.username', 'like', $s)
                  ->orWhere('ad.first_name', 'like', $s)
                  ->orWhere('ad.last_name', 'like', $s);
            });
        }
        if ($this->actionFilter !== '') {
            $query->where('cal.action', $this->actionFilter);
        }
        if ($this->dateFrom !== '') {
            $query->whereDate('cal.created_at', '>=', $this->dateFrom);
        }
        if ($this->dateTo !== '') {
            $query->whereDate('cal.created_at', '<=', $this->dateTo);
        }

        $logs = $query->orderBy('cal.created_at', 'desc')->paginate(20);

        // Stats
        $sb    = \DB::table('chatify_audit_logs');
        $stats = [
            'total'    => (clone $sb)->count(),
            'messages' => (clone $sb)->whereIn('action', ['send_message', 'send_dm'])->count(),
            'uploads'  => (clone $sb)->whereIn('action', ['upload_file', 'upload_dm_file'])->count(),
            'clears'   => (clone $sb)->whereIn('action', ['clear_chat', 'clear_all_chat', 'delete_message'])->count(),
        ];

        $actionTypes = \DB::table('chatify_audit_logs')
            ->select('action')->distinct()->orderBy('action')->pluck('action');

        // ── Chat Backups tab ─────────────────────────────────────────────────
        // Group backup records by (archived_at truncated to minute, archived_by)
        // so each admin "clear" session appears as one row with a download button
        $backupSessions = collect();
        try {
            $backupSessions = \DB::table('chatify_chat_backup as b')
                ->leftJoin('account_details as ad', 'b.archived_by', '=', 'ad.account_id')
                ->leftJoin('account as acc', 'b.archived_by', '=', 'acc.id')
                ->selectRaw("
                    DATE_TRUNC('minute', b.archived_at) AS session_time,
                    b.archived_by,
                    acc.username       AS archivist_username,
                    ad.first_name      AS archivist_first,
                    ad.last_name       AS archivist_last,
                    COUNT(*)           AS message_count,
                    COUNT(DISTINCT b.conv_id) AS conv_count,
                    MIN(b.archived_at) AS archived_at
                ")
                ->groupByRaw("DATE_TRUNC('minute', b.archived_at), b.archived_by, acc.username, ad.first_name, ad.last_name")
                ->orderByRaw("MIN(b.archived_at) DESC")
                ->get();
        } catch (\Throwable $e) {
            // Table may not exist yet
        }

        return [
            'logs'           => $logs,
            'stats'          => $stats,
            'actionTypes'    => $actionTypes,
            'backupSessions' => $backupSessions,
        ];
    }

    public function getActionLabel(string $action): string
    {
        return $this->actionLabel($action);
    }
};
?>

@push('styles')
    @vite('resources/css/admin/activity_logs.css')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        /* ── Tab switcher ────────────────────────────────────────────────── */
        .audit-tab-bar {
            display: flex;
            gap: 0;
            border-bottom: 2px solid var(--border-color, #e2e8f0);
            margin-bottom: 20px;
        }
        .audit-tab-btn {
            padding: 10px 22px;
            font-size: 13.5px;
            font-weight: 600;
            background: transparent;
            border: none;
            border-bottom: 2.5px solid transparent;
            margin-bottom: -2px;
            cursor: pointer;
            color: var(--text-secondary, #64748b);
            transition: color 0.18s, border-color 0.18s;
        }
        .audit-tab-btn.active {
            color: #1b74e4;
            border-bottom-color: #1b74e4;
        }
        .audit-tab-btn:hover:not(.active) {
            color: var(--text-primary, #1e293b);
        }

        /* ── Action badges ───────────────────────────────────────────────── */
        .action-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 10px; border-radius: 20px;
            font-size: 11.5px; font-weight: 600; white-space: nowrap;
        }
        .action-badge.send_message,
        .action-badge.send_dm       { background: rgba(16,185,129,0.12); color: #059669; }
        .action-badge.edit_message  { background: rgba(245,158,11,0.12); color: #d97706; }
        .action-badge.clear_chat,
        .action-badge.delete_message{ background: rgba(239,68,68,0.12);  color: #dc2626; }
        .action-badge.clear_all_chat{ background: rgba(153,27,27,0.12);  color: #991b1b; }
        .action-badge.upload_file,
        .action-badge.upload_dm_file{ background: rgba(99,102,241,0.12); color: #4f46e5; }
        .action-badge.default-badge { background: rgba(100,116,139,0.12); color: #475569; }

        /* ── Meta pills ──────────────────────────────────────────────────── */
        .meta-pill {
            display: inline-block; font-size: 11px;
            background: rgba(148,163,184,0.15); color: var(--text-secondary, #64748b);
            border-radius: 4px; padding: 1px 6px; margin: 1px 2px; font-family: monospace;
        }
        .target-chip {
            font-size: 11.5px; color: #64748b; font-family: monospace; word-break: break-all;
        }

        /* ── Backup session table ────────────────────────────────────────── */
        .backup-session-row td { vertical-align: middle; }
        .backup-session-pill {
            display: inline-block; padding: 2px 8px; border-radius: 20px;
            font-size: 11px; font-weight: 600;
            background: rgba(99,102,241,0.12); color: #4f46e5; font-family: monospace;
        }
        .btn-download-backup {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 14px; border-radius: 6px; font-size: 12.5px;
            font-weight: 600; cursor: pointer; border: 1.5px solid #1b74e4;
            background: transparent; color: #1b74e4; text-decoration: none;
            transition: background 0.15s, color 0.15s;
        }
        .btn-download-backup:hover {
            background: #1b74e4; color: #fff;
        }
    </style>
@endpush

<div class="activity-logs-container">
    <!-- Header -->
    <div class="page-header">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
            <div>
                <h1>Chatify Audit Trail</h1>
                <p>Monitor all user actions and manage chat message backups.</p>
            </div>
            <div>
                <span class="live-status-pill">
                    <span class="pulse-dot"></span> Live Chat Activity
                </span>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats-overview-grid">
        <div class="stat-card blue">
            <div class="stat-icon"><svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 62 62" fill="none">
<path fill-rule="evenodd" clip-rule="evenodd" d="M22.2812 1.9375C21.5105 1.9375 20.7712 2.24369 20.2262 2.78872C19.6812 3.33375 19.375 4.07296 19.375 4.84375V57.1562C19.375 57.927 19.6812 58.6663 20.2262 59.2113C20.7712 59.7563 21.5105 60.0625 22.2812 60.0625C23.052 60.0625 23.7913 59.7563 24.3363 59.2113C24.8813 58.6663 25.1875 57.927 25.1875 57.1562V4.84375C25.1875 4.07296 24.8813 3.33375 24.3363 2.78872C23.7913 2.24369 23.052 1.9375 22.2812 1.9375ZM14.5312 15.5C14.5312 16.7846 14.0209 18.0167 13.1125 18.925C12.2042 19.8334 10.9721 20.3438 9.6875 20.3438C8.40286 20.3438 7.17083 19.8334 6.26245 18.925C5.35407 18.0167 4.84375 16.7846 4.84375 15.5C4.84375 14.2154 5.35407 12.9833 6.26245 12.075C7.17083 11.1666 8.40286 10.6562 9.6875 10.6562C10.9721 10.6562 12.2042 11.1666 13.1125 12.075C14.0209 12.9833 14.5312 14.2154 14.5312 15.5ZM30.0312 15.5C30.0312 14.7292 30.3374 13.99 30.8825 13.445C31.4275 12.8999 32.1667 12.5938 32.9375 12.5938H55.2188C55.9895 12.5938 56.7288 12.8999 57.2738 13.445C57.8188 13.99 58.125 14.7292 58.125 15.5C58.125 16.2708 57.8188 17.01 57.2738 17.555C56.7288 18.1001 55.9895 18.4062 55.2188 18.4062H32.9375C32.1667 18.4062 31.4275 18.1001 30.8825 17.555C30.3374 17.01 30.0313 16.2708 30.0312 15.5ZM30.0312 46.5C30.0312 45.7292 30.3374 44.99 30.8825 44.445C31.4275 43.8999 32.1667 43.5938 32.9375 43.5938H55.2188C55.9895 43.5938 56.7288 43.8999 57.2738 44.445C57.8188 44.99 58.125 45.7292 58.125 46.5C58.125 47.2708 57.8188 48.01 57.2738 48.555C56.7288 49.1001 55.9895 49.4062 55.2188 49.4062H32.9375C32.1667 49.4062 31.4275 49.1001 30.8825 48.555C30.3374 48.01 30.0313 47.2708 30.0312 46.5ZM32.9375 28.0938C32.1667 28.0938 31.4275 28.3999 30.8825 28.945C30.3374 29.49 30.0313 30.2292 30.0312 31C30.0313 31.7708 30.3374 32.51 30.8825 33.055C31.4275 33.6001 32.1667 33.9062 32.9375 33.9062H55.2188C55.9895 33.9062 56.7288 33.6001 57.2738 33.055C57.8188 32.51 58.125 31.7708 58.125 31C58.125 30.2292 57.8188 29.49 57.2738 28.945C56.7288 28.3999 55.9895 28.0938 55.2188 28.0938H32.9375ZM9.6875 35.8438C10.9721 35.8438 12.2042 35.3334 13.1125 34.425C14.0209 33.5167 14.5312 32.2846 14.5312 31C14.5312 29.7154 14.0209 28.4833 13.1125 27.575C12.2042 26.6666 10.9721 26.1562 9.6875 26.1562C8.40286 26.1562 7.17083 26.6666 6.26245 27.575C5.35407 28.4833 4.84375 29.7154 4.84375 31C4.84375 32.2846 5.35407 33.5167 6.26245 34.425C7.17083 35.3334 8.40286 35.8437 9.6875 35.8438ZM14.5312 46.5C14.5312 47.7846 14.0209 49.0167 13.1125 49.925C12.2042 50.8334 10.9721 51.3438 9.6875 51.3438C8.40286 51.3438 7.17083 50.8334 6.26245 49.925C5.35407 49.0167 4.84375 47.7846 4.84375 46.5C4.84375 45.2154 5.35407 43.9833 6.26245 43.075C7.17083 42.1666 8.40286 41.6563 9.6875 41.6562C10.9721 41.6562 12.2042 42.1666 13.1125 43.075C14.0209 43.9833 14.5312 45.2154 14.5312 46.5Z" fill="#0059FF"/>
</svg></i></div>
            <div class="stat-details">
                <span class="stat-value">{{ number_format($stats['total']) }}</span>
                <span class="stat-label">Total Actions Logged</span>
            </div>
        </div>
        <div class="stat-card green">
            <div class="stat-icon"><svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 65 65" fill="none">
<path d="M59.5832 32.5C59.5832 47.4577 47.4576 59.5833 32.4998 59.5833C24.4107 59.5833 5.4165 59.5833 5.4165 59.5833C5.4165 59.5833 5.4165 39.3686 5.4165 32.5C5.4165 17.5422 17.5421 5.41663 32.4998 5.41663C47.4576 5.41663 59.5832 17.5422 59.5832 32.5Z" fill="#00CD3A" stroke="black" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
<path d="M18.958 35.2083L27.0831 43.3333L44.6873 25.7291" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
</svg></i></div>
            <div class="stat-details">
                <span class="stat-value">{{ number_format($stats['messages']) }}</span>
                <span class="stat-label">Messages Sent</span>
            </div>
        </div>
        <div class="stat-card orange">
            <div class="stat-icon"><i class="fa-solid fa-file-arrow-up"></i></div>
            <div class="stat-details">
                <span class="stat-value">{{ number_format($stats['uploads']) }}</span>
                <span class="stat-label">File Uploads</span>
            </div>
        </div>
        <div class="stat-card red">
            <div class="stat-icon"><i class="fa-solid fa-trash-can"></i></div>
            <div class="stat-details">
                <span class="stat-value">{{ number_format($stats['clears']) }}</span>
                <span class="stat-label">Conversation Clears</span>
            </div>
        </div>
    </div>

    <!-- Tab Bar -->
    <div class="audit-tab-bar">
        <button class="audit-tab-btn {{ $activeTab === 'audit' ? 'active' : '' }}"
                wire:click="switchTab('audit')" type="button">
            <i class="fa-solid fa-clipboard-list" style="margin-right: 6px;"></i> Audit Log
        </button>
        <button class="audit-tab-btn {{ $activeTab === 'backups' ? 'active' : '' }}"
                wire:click="switchTab('backups')" type="button">
            <i class="fa-solid fa-box-archive" style="margin-right: 6px;"></i> Chat Backups
        </button>
    </div>

    {{-- ────────────────────────────────────────────────────────────────────────
         TAB 1: AUDIT LOG
    ──────────────────────────────────────────────────────────────────────── --}}
    @if($activeTab === 'audit')

        <!-- Controls Panel -->
        <div class="logs-controls-card">
            <div class="search-filter-group" style="flex-wrap: wrap; gap: 10px;">
                <div class="search-box-wrapper">
                    <i class="fa-solid fa-magnifying-glass search-icon"></i>
                    <input type="text"
                           class="search-input"
                           placeholder="Search username or name..."
                           wire:model.live.debounce.300ms="search">
                </div>

                <select class="filter-select" wire:model.live="actionFilter">
                    <option value="">All Action Types</option>
                    @foreach($actionTypes as $type)
                        <option value="{{ $type }}">
                            {{ match($type) {
                                'send_message'   => 'Sent Global Message',
                                'send_dm'        => 'Sent Direct Message',
                                'edit_message'   => 'Edited Message',
                                'clear_chat'     => 'Cleared Conversation',
                                'clear_all_chat' => 'Deleted All Chats',
                                'delete_message' => 'Deleted Conversation (legacy)',
                                'upload_file'    => 'Uploaded File (Global)',
                                'upload_dm_file' => 'Uploaded File (DM)',
                                default          => ucwords(str_replace('_', ' ', $type))
                            } }}
                        </option>
                    @endforeach
                </select>

                <input type="date" class="filter-select" wire:model.live="dateFrom"
                       title="Date From" style="min-width: 150px;">
                <input type="date" class="filter-select" wire:model.live="dateTo"
                       title="Date To" style="min-width: 150px;">

                @if($search !== '' || $actionFilter !== '' || $dateFrom !== '' || $dateTo !== '')
                    <button type="button" class="btn-clear-filters" wire:click="clearFilters">
                        <i class="fa-solid fa-filter-circle-xmark"></i> Clear Filters
                    </button>
                @endif
            </div>
        </div>

        <!-- Data Table -->
        <div class="logs-table-card">
            <div class="table-responsive">
                <table class="logs-table">
                    <thead>
                        <tr>
                            <th style="width: 17%">TIMESTAMP</th>
                            <th style="width: 22%">USER</th>
                            <th style="width: 20%">ACTION</th>
                            <th style="width: 25%">DETAILS / CONTEXT</th>
                            <th style="width: 16%">IP ADDRESS</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($logs as $log)
                            @php
                                $fullName = trim(($log->first_name ?? '') . ' ' . ($log->last_name ?? ''));
                                $meta = $log->meta ? json_decode($log->meta, true) : [];
                                $badgeClass = match($log->action) {
                                    'send_message', 'send_dm'         => 'send_message',
                                    'edit_message'                    => 'edit_message',
                                    'clear_chat', 'delete_message'    => 'clear_chat',
                                    'clear_all_chat'                  => 'clear_all_chat',
                                    'upload_file', 'upload_dm_file'   => 'upload_file',
                                    default                           => 'default-badge',
                                };
                                $actionIcon = match($log->action) {
                                    'send_message'   => 'fa-comment',
                                    'send_dm'        => 'fa-envelope',
                                    'edit_message'   => 'fa-pen-to-square',
                                    'clear_chat',
                                    'delete_message' => 'fa-broom',
                                    'clear_all_chat' => 'fa-trash-can',
                                    'upload_file'    => 'fa-file-arrow-up',
                                    'upload_dm_file' => 'fa-file-arrow-up',
                                    default          => 'fa-bolt',
                                };
                                $actionLabel = match($log->action) {
                                    'send_message'   => 'Sent Global Message',
                                    'send_dm'        => 'Sent Direct Message',
                                    'edit_message'   => 'Edited Message',
                                    'clear_chat'     => 'Cleared Conversation',
                                    'clear_all_chat' => 'Deleted All Chats',
                                    'delete_message' => 'Deleted Conversation',
                                    'upload_file'    => 'Upload (Global)',
                                    'upload_dm_file' => 'Upload (DM)',
                                    default          => ucwords(str_replace('_', ' ', $log->action)),
                                };
                            @endphp
                            <tr>
                                <!-- Timestamp -->
                                <td>
                                    <div style="font-size: 13px; font-weight: 500; color: var(--text-primary, #1e293b);">
                                        {{ \Carbon\Carbon::parse($log->created_at)->timezone('Asia/Manila')->format('M d, Y') }}
                                    </div>
                                    <div style="font-size: 11.5px; color: var(--text-secondary, #64748b);">
                                        {{ \Carbon\Carbon::parse($log->created_at)->timezone('Asia/Manila')->format('h:i:s A') }}
                                    </div>
                                </td>

                                <!-- User -->
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
                                        ID: {{ $log->account_id }}
                                    </div>
                                </td>

                                <!-- Action -->
                                <td>
                                    <span class="action-badge {{ $badgeClass }}">
                                        <i class="fa-solid {{ $actionIcon }}" style="font-size: 10px;"></i>
                                        {{ $actionLabel }}
                                    </span>
                                </td>

                                <!-- Details / Context -->
                                <td>
                                    @if($log->target_id)
                                        <div class="target-chip" style="margin-bottom: 4px;">
                                            <i class="fa-solid fa-tag" style="font-size: 10px; opacity: 0.5;"></i>
                                            {{ Str::limit($log->target_id, 36) }}
                                        </div>
                                    @endif
                                    @if(!empty($meta))
                                        <div style="display: flex; flex-wrap: wrap; gap: 2px;">
                                            @if(isset($meta['recipient_id']))
                                                <span class="meta-pill">to: #{{ $meta['recipient_id'] }}</span>
                                            @endif
                                            @if(isset($meta['conv_id']))
                                                <span class="meta-pill">conv: {{ $meta['conv_id'] }}</span>
                                            @endif
                                            @if(isset($meta['chat_type']))
                                                <span class="meta-pill">{{ $meta['chat_type'] }}</span>
                                            @endif
                                            @if(isset($meta['file_count']))
                                                <span class="meta-pill">{{ $meta['file_count'] }} file(s)</span>
                                            @endif
                                            @if(isset($meta['deleted_conversations']))
                                                <span class="meta-pill">{{ $meta['deleted_conversations'] }} conv(s)</span>
                                            @endif
                                            @if(isset($meta['includes_global']) && $meta['includes_global'])
                                                <span class="meta-pill" style="background:rgba(153,27,27,0.1);color:#991b1b;">incl. global</span>
                                            @endif
                                            @if(isset($meta['is_admin']) && $meta['is_admin'])
                                                <span class="meta-pill" style="background:rgba(239,68,68,0.1);color:#dc2626;">admin</span>
                                            @endif
                                            @if(isset($meta['fully_deleted']) && $meta['fully_deleted'])
                                                <span class="meta-pill" style="background:rgba(239,68,68,0.1);color:#dc2626;">hard-delete</span>
                                            @endif
                                            @if(isset($meta['has_text']) && $meta['has_text'])
                                                <span class="meta-pill">text</span>
                                            @endif
                                            @if(isset($meta['has_file']) && $meta['has_file'])
                                                <span class="meta-pill">file</span>
                                            @endif
                                        </div>
                                    @endif
                                    @if(!$log->target_id && empty($meta))
                                        <span style="color: var(--text-secondary, #64748b); font-size: 12px;">—</span>
                                    @endif
                                </td>

                                <!-- IP Address -->
                                <td>
                                    <span style="font-size: 12.5px; font-family: monospace; color: var(--text-secondary, #64748b);">
                                        {{ $log->ip_address ?? '—' }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 48px 20px; color: var(--text-secondary, #64748b);">
                                    <i class="fa-solid fa-inbox" style="font-size: 28px; opacity: 0.35; display: block; margin-bottom: 10px;"></i>
                                    No chat activity records found.
                                    @if($search !== '' || $actionFilter !== '' || $dateFrom !== '' || $dateTo !== '')
                                        <br><small>Try adjusting or clearing your filters.</small>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($logs->hasPages())
                <div style="padding: 16px 20px; border-top: 1px solid var(--border-color, #e2e8f0);">
                    {{ $logs->links('components.pagination') }}
                </div>
            @endif
        </div>

    @endif

    {{-- ────────────────────────────────────────────────────────────────────────
         TAB 2: CHAT BACKUPS
    ──────────────────────────────────────────────────────────────────────── --}}
    @if($activeTab === 'backups')

        <div class="logs-controls-card" style="margin-bottom: 16px;">
            <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                <i class="fa-solid fa-circle-info" style="color: #1b74e4; font-size: 14px;"></i>
                <span style="font-size: 13px; color: var(--text-secondary, #64748b); line-height: 1.5;">
                    Each row below represents one backup session — a snapshot of messages taken before an admin cleared or wiped the chat.
                    Click <strong>Download</strong> to export the messages as a plain-text JSON file for audit purposes.
                </span>
                <a href="/chatify/get_chat_backup.php"
                   target="_blank"
                   class="btn-download-backup"
                   style="margin-left: auto; white-space: nowrap;">
                    <i class="fa-solid fa-file-arrow-down"></i> Download All Backups
                </a>
            </div>
        </div>

        <div class="logs-table-card">
            <div class="table-responsive">
                <table class="logs-table">
                    <thead>
                        <tr>
                            <th style="width: 20%">BACKUP DATE &amp; TIME</th>
                            <th style="width: 25%">ARCHIVED BY</th>
                            <th style="width: 15%; text-align: center;">MESSAGES</th>
                            <th style="width: 15%; text-align: center;">CONVERSATIONS</th>
                            <th style="width: 25%; text-align: center;">ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($backupSessions as $session)
                            @php
                                $archivistName = trim(($session->archivist_first ?? '') . ' ' . ($session->archivist_last ?? ''))
                                                    ?: ('Admin #' . $session->archived_by);
                                $sessionDate   = \Carbon\Carbon::parse($session->archived_at)->timezone('Asia/Manila');
                                $downloadDate  = substr($session->archived_at, 0, 10);
                            @endphp
                            <tr class="backup-session-row">
                                <td>
                                    <div style="font-size: 13px; font-weight: 500; color: var(--text-primary, #1e293b);">
                                        {{ $sessionDate->format('M d, Y') }}
                                    </div>
                                    <div style="font-size: 11.5px; color: var(--text-secondary, #64748b);">
                                        {{ $sessionDate->format('h:i A') }}
                                    </div>
                                </td>
                                <td>
                                    <div style="font-weight: 600; font-size: 13.5px;">{{ $archivistName }}</div>
                                    @if($session->archivist_username)
                                        <div style="font-size: 11.5px; color: var(--text-secondary, #64748b);">
                                            {{ '@' . $session->archivist_username }}
                                        </div>
                                    @endif
                                    <div style="font-size: 11px; color: #94a3b8;">ID: {{ $session->archived_by }}</div>
                                </td>
                                <td style="text-align: center;">
                                    <span class="backup-session-pill">{{ number_format($session->message_count) }}</span>
                                </td>
                                <td style="text-align: center;">
                                    <span class="backup-session-pill">{{ number_format($session->conv_count) }}</span>
                                </td>
                                <td style="text-align: center;">
                                    <a href="/chatify/get_chat_backup.php?archived_at={{ urlencode($downloadDate) }}"
                                       target="_blank"
                                       class="btn-download-backup"
                                       title="Download messages from this backup session as a plain-text JSON file">
                                        <i class="fa-solid fa-file-arrow-down"></i>
                                        Download JSON
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 48px 20px; color: var(--text-secondary, #64748b);">
                                    <i class="fa-solid fa-box-open" style="font-size: 28px; opacity: 0.35; display: block; margin-bottom: 10px;"></i>
                                    No chat backups found.
                                    <br><small>Backups are automatically created when an admin clears or wipes chat conversations.</small>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    @endif
</div>
