<?php
/**
 * Profile Manager - Notification Manager Volt Component
 * 
 * This component handles retrieving, rendering, marking as read, and dismissing
 * system notifications scoped to the authenticated user's office and role permissions.
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

new #[Layout('layouts.profile')] #[Title('Profile Manager - Notification Manager')] class extends Component {
    /** @var array<int, mixed> Holds the collection of active notifications for display */
    public $notifications = [];

    /** @var string Office name associated with the authenticated user account */
    public $officeName = 'No Office Assigned';

    /**
     * Component mount hook - loads initial notifications list.
     */
    public ?int $currentRoleId = null;
    public array $currentPermissions = [];
    public bool $showDetailsModal = false;
    public ?array $selectedDetails = null;

    public function mount()
    {
        $this->loadNotifications();
        $this->checkRoleUpdate();
    }

    public function checkRoleUpdate()
    {
        $user = Auth::user();
        if (!$user) return;
        $user = $user->fresh();
        
        $perms = $user->permissions;
        $permValues = $perms ? [
            $perms->is_sadm, $perms->can_access_dts, $perms->can_access_rdp, $perms->can_access_dcs,
            $perms->can_dts_modify_docflow, $perms->can_sadm_modify_accountlist, $perms->can_sadm_modify_pass,
            $perms->can_sadm_modify_account, $perms->can_dts_view_all_list, $perms->can_dts_view_all_archive,
            $perms->can_dts_view_all_current_trans, $perms->can_dts_create_own_flow
        ] : [];

        if ($this->currentRoleId === null) {
            $this->currentRoleId = $user->account_role;
            $this->currentPermissions = $permValues;
            return;
        }

        if ($user->account_role !== $this->currentRoleId || $permValues !== $this->currentPermissions) {
            $this->js('window.location.reload();');
        }
    }

    /**
     * Queries notifications from database based on user's office and active subsystem access.
     * System accesses are determined by analyzing role permission flags.
     */
    public function loadNotifications()
    {
        $userId = Auth::id();
        $user = Auth::user();
        $perms = $user?->permissions;

        // Get user's office details to restrict notifications scoping
        $office = DB::table((\Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details') . ' as account_details')
            ->join((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as office', 'account_details.office_id', '=', 'office.id')
            ->where('account_details.account_id', $userId)
            ->select('office.office_code', 'office.office_name')
            ->first();

        if ($office) {
            $this->officeName = $office->office_name;
            $officeCode = $office->office_code;
        } else {
            $this->officeName = 'No Office Assigned';
            $officeCode = null;
        }

        if (!$officeCode) {
            $this->notifications = collect();
            return;
        }

        // Determine accessible subsystems based on role permissions (filters notification streams)
        $allowedSubsystems = ['Profile Manager']; // Profile Manager is always accessible by default
        if ($perms) {
            if ($perms->is_sadm) {
                $allowedSubsystems[] = 'Document Tracking System';
                $allowedSubsystems[] = 'Records Disposition Program';
                $allowedSubsystems[] = 'Document Control System';
                $allowedSubsystems[] = 'Admin Console';
            } else {
                if ($perms->can_access_dts) {
                    $allowedSubsystems[] = 'Document Tracking System';
                }
                if ($perms->can_access_rdp) {
                    $allowedSubsystems[] = 'Records Disposition Program';
                }
                if ($perms->can_access_dcs) {
                    $allowedSubsystems[] = 'Document Control System';
                }
            }
        }

        $notifTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_notifications') ? 'sys_notifications' : 'notifications';
        $notifContentTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_notif_content') ? 'sys_notif_content' : 'notif_content';
        $subsystemsTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems';
        $notifDivTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_notification_div') ? 'sys_notification_div' : 'notification_div';

        // Fetch notifications list, combining with read/unread statuses in notification_div table
        $this->notifications = DB::table("{$notifTbl} as notifications")
            ->join("{$notifContentTbl} as notif_content", 'notifications.contents', '=', 'notif_content.id')
            ->join("{$subsystemsTbl} as subsystems", 'notif_content.system', '=', 'subsystems.subsystem_id')
            ->leftJoin("{$notifDivTbl} as notification_div", function ($join) use ($userId) {
                $join->on('notifications.id', '=', 'notification_div.id')
                     ->where('notification_div.account_rec', '=', $userId);
            })
            ->where('notifications.office', $officeCode)
            ->whereIn('subsystems.subsystem_name', $allowedSubsystems)
            ->where(function ($query) {
                $query->whereNull('notification_div.is_in_user_list')
                      ->orWhere('notification_div.is_in_user_list', 1);
            })
            ->orderBy('notifications.created_at', 'desc')
            ->select(
                'notifications.id',
                'notifications.office',
                'subsystems.subsystem_name',
                'notif_content.content',
                'notif_content.redirect_url',
                'notifications.created_at',
                'notification_div.processed_on',
                'notification_div.read_at_session',
                'notification_div.is_dismissed',
                'notification_div.is_in_user_list',
                'notification_div.account_rec',
                DB::raw("COALESCE(notification_div.status, 'unread') as status")
            )
            ->get();
    }

    /**
     * Marks an active notification as read.
     * Inserts/updates record in notification_div helper table.
     * 
     * @param int $notificationId The ID of the notification
     */
    public function markAsRead($notificationId)
    {
        $notifTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_notifications') ? 'sys_notifications' : 'notifications';
        $notifDivTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_notification_div') ? 'sys_notification_div' : 'notification_div';

        $exists = DB::table($notifTbl)->where('id', $notificationId)->exists();
        if (!$exists) {
            $this->loadNotifications();
            return;
        }

        $userId = Auth::id();
        DB::table($notifDivTbl)->updateOrInsert(
            [
                'id' => $notificationId,
                'account_rec' => $userId
            ],
            [
                'status' => 'read',
                'read_at_session' => session()->getId(),
                'processed_on' => now()
            ]
        );
        $this->loadNotifications();
        $this->dispatch('rms-notification-updated');
    }

    /**
     * Marks notification as read and redirects to its expected target subsystem / destination.
     *
     * @param int $notificationId The ID of the notification
     */
    public function openRedirect($notificationId)
    {
        $this->markAsRead($notificationId);

        $notif = collect($this->notifications)->firstWhere('id', $notificationId);
        if (!$notif) {
            return;
        }

        if (!empty($notif->redirect_url)) {
            $intake = \App\Helpers\OfficeIntakeHelper::parseIntakeNotificationUrl($notif->redirect_url);
            if ($intake) {
                return $this->redirect('/dcs?intake=' . $intake['type'] . '&id=' . $intake['id'], navigate: false);
            }
            return $this->redirect($notif->redirect_url, navigate: false);
        }

        $dest = match ($notif->subsystem_name) {
            'Document Tracking System' => '/dts',
            'Records Disposition Program' => '/rdp',
            'Document Control System' => '/dcs',
            'Admin Console' => '/admin',
            'Profile Manager' => '/profile',
            default => '/portal',
        };
        return $this->redirect($dest, navigate: false);
    }

    /**
     * Opens the compact More Details modal populated with all notification attributes.
     *
     * @param int $notificationId The ID of the notification
     */
    public function openDetailsModal($notificationId)
    {
        $notif = collect($this->notifications)->firstWhere('id', $notificationId);
        if (!$notif) {
            return;
        }

        $this->selectedDetails = [
            'id' => $notif->id,
            'office' => $notif->office ?? $this->officeName,
            'subsystem_name' => $notif->subsystem_name,
            'content' => $notif->content,
            'redirect_url' => $notif->redirect_url ?: 'None',
            'status' => $notif->status ?? 'unread',
            'read_at_session' => $notif->read_at_session ?: 'None',
            'is_dismissed' => !empty($notif->is_dismissed) ? 'Yes' : 'No',
            'is_in_user_list' => ($notif->is_in_user_list ?? true) ? 'Yes' : 'No',
            'account_rec' => $notif->account_rec ?? Auth::id(),
            'created_at' => $notif->created_at ? \Carbon\Carbon::parse($notif->created_at)->format('Y-m-d H:i:s') : 'N/A',
            'processed_on' => $notif->processed_on ? \Carbon\Carbon::parse($notif->processed_on)->format('Y-m-d H:i:s') : 'N/A',
        ];
        $this->showDetailsModal = true;
    }

    /**
     * Closes the More Details modal.
     */
    public function closeDetailsModal()
    {
        $this->showDetailsModal = false;
        $this->selectedDetails = null;
    }

    /**
     * Dismisses a notification, removing it from the user's dashboard view.
     * Sets 'is_in_user_list' flag to false.
     * 
     * @param int $notificationId The ID of the notification
     */
    public function dismiss($notificationId)
    {
        $exists = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_notifications') ? 'sys_notifications' : 'notifications')->where('id', $notificationId)->exists();
        if (!$exists) {
            $this->loadNotifications();
            return;
        }

        $userId = Auth::id();
        DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_notification_div') ? 'sys_notification_div' : 'notification_div')->updateOrInsert(
            [
                'id' => $notificationId,
                'account_rec' => $userId
            ],
            [
                'is_in_user_list' => false,
                'processed_on' => now()
            ]
        );
        $this->loadNotifications();
        $this->dispatch('rms-notification-updated');
    }
};
?>

@push('styles')
    @vite('resources/css/profile/personal_details.css')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        /* Action dropdown in table */
        .notif-mgr-dots-btn {
            background: transparent;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 6px 10px;
            color: #4b5563;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            outline: none;
        }
        .notif-mgr-dots-btn:hover {
            background: #f1f5f9;
            color: #0f172a;
            border-color: #cbd5e1;
        }
        .notif-mgr-dropdown-menu {
            position: absolute;
            right: 0;
            top: calc(100% + 4px);
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
            z-index: 1050;
            min-width: 165px;
            padding: 6px 0;
            display: flex;
            flex-direction: column;
            text-align: left;
        }
        .notif-mgr-dropdown-item {
            background: transparent;
            border: none;
            padding: 8px 14px;
            font-size: 12px;
            font-family: 'Inter', sans-serif;
            font-weight: 500;
            color: #334155;
            cursor: pointer;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background 0.15s ease, color 0.15s ease;
            width: 100%;
            outline: none;
        }
        .notif-mgr-dropdown-item:hover {
            background: #f8fafc;
            color: #0f172a;
        }

        /* Compact More Details Modal */
        .notif-modal-backdrop {
            position: fixed;
            inset: 0;
            background-color: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(3px);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            animation: notifFadeIn 0.2s ease-out;
        }
        @keyframes notifFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .notif-compact-modal {
            background: #ffffff;
            border-radius: 14px;
            width: 100%;
            max-width: 440px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            border: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            animation: notifSlideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes notifSlideUp {
            from { transform: translateY(16px) scale(0.97); opacity: 0; }
            to { transform: translateY(0) scale(1); opacity: 1; }
        }
        .notif-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 18px;
            border-bottom: 1px solid #f1f5f9;
            background: #f8fafc;
        }
        .notif-modal-header-title {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .notif-modal-header-title h3 {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
            color: #1e293b;
            font-family: 'Inter', sans-serif;
        }
        .notif-modal-close-btn {
            background: transparent;
            border: none;
            font-size: 15px;
            color: #94a3b8;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 6px;
            transition: all 0.15s ease;
            outline: none;
        }
        .notif-modal-close-btn:hover {
            color: #1e293b;
            background: #e2e8f0;
        }
        .notif-modal-body {
            padding: 16px 18px;
            max-height: 70vh;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .notif-detail-message-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 12px;
        }
        .notif-detail-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: #64748b;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
            font-family: 'Inter', sans-serif;
        }
        .notif-detail-content-text {
            font-size: 13px;
            color: #1e293b;
            line-height: 1.45;
            word-break: break-word;
            font-family: 'Inter', sans-serif;
        }
        .notif-detail-grid {
            display: flex;
            flex-direction: column;
            gap: 8px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 12px;
        }
        .notif-detail-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            padding: 4px 0;
            border-bottom: 1px dashed #f1f5f9;
        }
        .notif-detail-item:last-child {
            border-bottom: none;
        }
        .notif-detail-key {
            color: #64748b;
            font-weight: 500;
            font-family: 'Inter', sans-serif;
        }
        .notif-detail-val {
            color: #1e293b;
            font-weight: 500;
            text-align: right;
            max-width: 60%;
            word-break: break-all;
            font-family: 'Inter', sans-serif;
        }
        .notif-truncate code {
            background: #f1f5f9;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
            color: #0f172a;
        }
        .notif-modal-footer {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 10px;
            padding: 12px 18px;
            border-top: 1px solid #f1f5f9;
            background: #f8fafc;
        }
        .notif-modal-action-btn {
            padding: 7px 16px;
            border-radius: 8px;
            font-size: 12.5px;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
            outline: none;
        }
        .notif-modal-action-btn.primary {
            background: #003699;
            color: #ffffff;
            border: 1px solid #003699;
        }
        .notif-modal-action-btn.primary:hover {
            background: #002873;
            border-color: #002873;
        }
        .notif-modal-action-btn.secondary {
            background: #ffffff;
            color: #475569;
            border: 1px solid #cbd5e1;
        }
        .notif-modal-action-btn.secondary:hover {
            background: #f1f5f9;
            color: #1e293b;
        }

        /* Dark mode overrides */
        [data-theme="dark"] .notif-mgr-dots-btn {
            border-color: #334155;
            color: #94a3b8;
        }
        [data-theme="dark"] .notif-mgr-dots-btn:hover {
            background: #1e293b;
            color: #f1f5f9;
        }
        [data-theme="dark"] .notif-mgr-dropdown-menu {
            background: #1e293b;
            border-color: #334155;
        }
        [data-theme="dark"] .notif-mgr-dropdown-item {
            color: #cbd5e1;
        }
        [data-theme="dark"] .notif-mgr-dropdown-item:hover {
            background: #334155;
            color: #ffffff;
        }
        [data-theme="dark"] .notif-compact-modal {
            background: #1e293b;
            border-color: #334155;
        }
        [data-theme="dark"] .notif-modal-header,
        [data-theme="dark"] .notif-modal-footer {
            background: #0f172a;
            border-color: #334155;
        }
        [data-theme="dark"] .notif-modal-header-title h3 {
            color: #f1f5f9;
        }
        [data-theme="dark"] .notif-detail-message-box,
        [data-theme="dark"] .notif-detail-grid {
            background: #0f172a;
            border-color: #334155;
        }
        [data-theme="dark"] .notif-detail-content-text,
        [data-theme="dark"] .notif-detail-val {
            color: #f1f5f9;
        }
        [data-theme="dark"] .notif-detail-item {
            border-bottom-color: #1e293b;
        }
        [data-theme="dark"] .notif-truncate code {
            background: #1e293b;
            color: #93c5fd;
        }
        [data-theme="dark"] .notif-modal-action-btn.secondary {
            background: #1e293b;
            color: #cbd5e1;
            border-color: #334155;
        }
    </style>
@endpush

<div class="container personal-details-container" wire:poll.5s="checkRoleUpdate">
    <!-- Hero Banner -->
    <div class="profile-hero-banner">
        <div class="hero-left">
            <div class="avatar-circle">
                <i class="fa-solid fa-bell" style="font-size: 24px;"></i>
            </div>
            <div class="hero-user-info">
                <span class="hero-greeting">Profile Manager</span>
                <h1 class="hero-name">Notifications</h1>
            </div>
        </div>
        <div class="hero-right">
            <div class="hero-clock" wire:ignore>
                <span id="currTime" class="clock-time">--:--:--</span>
                <span id="currDate" class="clock-date">--</span>
            </div>
        </div>
    </div>

    <!-- Details Card (Table) -->
    <div class="profile-card" style="width: 100%; box-sizing: border-box;">
        <h2 class="card-title">
            <i class="fa-solid fa-envelope-open-text"></i> Recent Notifications (Office: {{ $officeName }})
        </h2>
        
        <div class="table-responsive" style="min-height: 250px;">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Message</th>
                        <th>Status</th>
                        <th>Subsystem</th>
                        <th>Received At</th>
                        <th style="width: 80px; text-align: center;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($notifications as $notification)
                        <tr>
                            <td style="font-weight: 500; color: #1f2937; line-height: 1.45;">
                                {{ $notification->content }}
                            </td>
                            <td>
                                <span class="badge" style="padding: 4px 10px; border-radius: 99px; font-size: 11px; font-weight: bold; text-transform: uppercase; background-color: {{ $notification->status === 'unread' ? 'rgba(245, 158, 11, 0.15)' : 'rgba(16, 185, 129, 0.15)' }}; color: {{ $notification->status === 'unread' ? '#d97706' : '#10b981' }}; border: 1px solid {{ $notification->status === 'unread' ? 'rgba(245, 158, 11, 0.3)' : 'rgba(16, 185, 129, 0.3)' }};">
                                    {{ $notification->status }}
                                </span>
                            </td>
                            <td style="font-weight: 600; color: #003699;">{{ $notification->subsystem_name }}</td>
                            <td>{{ \Carbon\Carbon::parse($notification->created_at)->format('Y-m-d H:i:s') }}</td>
                            <td style="text-align: center;">
                                <div x-data="{ menuOpen: false }" @click.outside="menuOpen = false" class="notif-mgr-action-wrapper" style="position: relative; display: inline-block;">
                                    <button type="button" @click.stop="menuOpen = !menuOpen" class="notif-mgr-dots-btn" title="Actions" aria-label="Notification actions">
                                        <i class="fa-solid fa-ellipsis-vertical"></i>
                                    </button>
                                    <div x-show="menuOpen" x-transition x-cloak class="notif-mgr-dropdown-menu">
                                        @if ($notification->status === 'unread')
                                            <button type="button" wire:click="markAsRead({{ $notification->id }}); menuOpen = false" class="notif-mgr-dropdown-item">
                                                <i class="fa-solid fa-check" style="color: #10b981; width: 16px;"></i> Mark as read
                                            </button>
                                        @endif
                                        <button type="button" wire:click="openRedirect({{ $notification->id }}); menuOpen = false" class="notif-mgr-dropdown-item">
                                            <i class="fa-solid fa-arrow-up-right-from-square" style="color: #003699; width: 16px;"></i> Open redirect
                                        </button>
                                        <button type="button" wire:click="openDetailsModal({{ $notification->id }}); menuOpen = false" class="notif-mgr-dropdown-item">
                                            <i class="fa-solid fa-circle-info" style="color: #3b82f6; width: 16px;"></i> More details
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="no-permissions" style="text-align: center; padding: 24px;">No notifications found for this account.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Small / Compact More Details Modal -->
    @if ($showDetailsModal && $selectedDetails)
        <div class="notif-modal-backdrop" wire:click.self="closeDetailsModal" x-data @keydown.escape.window="$wire.closeDetailsModal()">
            <div class="notif-compact-modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
                <div class="notif-modal-header">
                    <div class="notif-modal-header-title">
                        <i class="fa-solid fa-circle-info" style="color: #003699;"></i>
                        <h3 id="modalTitle">Notification #{{ $selectedDetails['id'] }}</h3>
                    </div>
                    <button type="button" class="notif-modal-close-btn" wire:click="closeDetailsModal" aria-label="Close modal">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <div class="notif-modal-body">
                    <!-- Message Preview -->
                    <div class="notif-detail-message-box">
                        <div class="notif-detail-label">Message</div>
                        <div class="notif-detail-content-text">{{ $selectedDetails['content'] }}</div>
                    </div>

                    <!-- Metadata Grid -->
                    <div class="notif-detail-grid">
                        <div class="notif-detail-item">
                            <span class="notif-detail-key">Subsystem</span>
                            <span class="notif-detail-val" style="color: #003699; font-weight: 600;">{{ $selectedDetails['subsystem_name'] }}</span>
                        </div>

                        <div class="notif-detail-item">
                            <span class="notif-detail-key">Status</span>
                            <span class="notif-detail-val">
                                <span class="badge" style="padding: 2px 8px; border-radius: 99px; font-size: 10.5px; font-weight: bold; text-transform: uppercase; background-color: {{ $selectedDetails['status'] === 'unread' ? 'rgba(245, 158, 11, 0.15)' : 'rgba(16, 185, 129, 0.15)' }}; color: {{ $selectedDetails['status'] === 'unread' ? '#d97706' : '#10b981' }}; border: 1px solid {{ $selectedDetails['status'] === 'unread' ? 'rgba(245, 158, 11, 0.3)' : 'rgba(16, 185, 129, 0.3)' }};">
                                    {{ $selectedDetails['status'] }}
                                </span>
                            </span>
                        </div>

                        <div class="notif-detail-item">
                            <span class="notif-detail-key">Redirect URL</span>
                            <span class="notif-detail-val notif-truncate" title="{{ $selectedDetails['redirect_url'] }}">
                                <code>{{ $selectedDetails['redirect_url'] }}</code>
                            </span>
                        </div>

                        <div class="notif-detail-item">
                            <span class="notif-detail-key">Session Read</span>
                            <span class="notif-detail-val notif-truncate" title="{{ $selectedDetails['read_at_session'] }}">
                                <code>{{ $selectedDetails['read_at_session'] }}</code>
                            </span>
                        </div>

                        <div class="notif-detail-item">
                            <span class="notif-detail-key">Dismissed</span>
                            <span class="notif-detail-val">{{ $selectedDetails['is_dismissed'] }}</span>
                        </div>

                        <div class="notif-detail-item">
                            <span class="notif-detail-key">In User List</span>
                            <span class="notif-detail-val">{{ $selectedDetails['is_in_user_list'] }}</span>
                        </div>

                        <div class="notif-detail-item">
                            <span class="notif-detail-key">Recipient ID</span>
                            <span class="notif-detail-val">{{ $selectedDetails['account_rec'] }}</span>
                        </div>

                        <div class="notif-detail-item">
                            <span class="notif-detail-key">Target Office</span>
                            <span class="notif-detail-val">{{ $selectedDetails['office'] }}</span>
                        </div>

                        <div class="notif-detail-item">
                            <span class="notif-detail-key">Received At</span>
                            <span class="notif-detail-val">{{ $selectedDetails['created_at'] }}</span>
                        </div>

                        <div class="notif-detail-item">
                            <span class="notif-detail-key">Processed On</span>
                            <span class="notif-detail-val">{{ $selectedDetails['processed_on'] }}</span>
                        </div>
                    </div>
                </div>

                <div class="notif-modal-footer">
                    @if ($selectedDetails['redirect_url'] !== 'None')
                        <button type="button" class="notif-modal-action-btn primary" wire:click="openRedirect({{ $selectedDetails['id'] }})">
                            <i class="fa-solid fa-arrow-up-right-from-square"></i> Open Link
                        </button>
                    @endif
                    <button type="button" class="notif-modal-action-btn secondary" wire:click="closeDetailsModal">
                        Close
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>


