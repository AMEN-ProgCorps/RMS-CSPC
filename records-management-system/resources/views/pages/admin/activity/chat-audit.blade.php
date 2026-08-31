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
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_account') ? 'sys_account' : 'account') . ' as a', 'cal.account_id', '=', 'a.id')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details') . ' as ad', 'a.id', '=', 'ad.account_id')
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
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details') . ' as ad', 'b.archived_by', '=', 'ad.account_id')
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_account') ? 'sys_account' : 'account') . ' as acc', 'b.archived_by', '=', 'acc.id')
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
            <div class="stat-icon"><i class="fa-solid fa-list-check"></i></div>
            <div class="stat-details">
                <span class="stat-value">{{ number_format($stats['total']) }}</span>
                <span class="stat-label">Total Actions Logged</span>
            </div>
        </div>
        <div class="stat-card green">
            <div class="stat-icon"><i class="fa-solid fa-comment-dots"></i></div>
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
