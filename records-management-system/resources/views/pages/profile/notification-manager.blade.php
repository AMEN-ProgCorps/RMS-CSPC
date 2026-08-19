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
        $office = DB::table('account_details')
            ->join('office', 'account_details.office_id', '=', 'office.id')
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

        // Fetch notifications list, combining with read/unread statuses in notification_div table
        $this->notifications = DB::table('notifications')
            ->join('notif_content', 'notifications.contents', '=', 'notif_content.id')
            ->join('subsystems', 'notif_content.system', '=', 'subsystems.subsystem_id')
            ->leftJoin('notification_div', function ($join) use ($userId) {
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
                'subsystems.subsystem_name',
                'notif_content.content',
                'notifications.created_at',
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
        $exists = DB::table('notifications')->where('id', $notificationId)->exists();
        if (!$exists) {
            $this->loadNotifications();
            return;
        }

        $userId = Auth::id();
        DB::table('notification_div')->updateOrInsert(
            [
                'id' => $notificationId,
                'account_rec' => $userId
            ],
            [
                'status' => 'read',
                'processed_on' => now()
            ]
        );
        $this->loadNotifications();
    }

    /**
     * Dismisses a notification, removing it from the user's dashboard view.
     * Sets 'is_in_user_list' flag to false.
     * 
     * @param int $notificationId The ID of the notification
     */
    public function dismiss($notificationId)
    {
        $exists = DB::table('notifications')->where('id', $notificationId)->exists();
        if (!$exists) {
            $this->loadNotifications();
            return;
        }

        $userId = Auth::id();
        DB::table('notification_div')->updateOrInsert(
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
    }
};
?>

@push('styles')
    @vite('resources/css/profile/personal_details.css')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
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
        
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Subsystem</th>
                        <th>Message</th>
                        <th>Status</th>
                        <th>Received At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($notifications as $notification)
                        <tr>
                            <td style="font-weight: 600; color: #003699;">{{ $notification->subsystem_name }}</td>
                            <td>{{ $notification->content }}</td>
                            <td>
                                <span class="badge" style="padding: 4px 10px; border-radius: 99px; font-size: 11px; font-weight: bold; text-transform: uppercase; background-color: {{ $notification->status === 'unread' ? 'rgba(245, 158, 11, 0.15)' : 'rgba(16, 185, 129, 0.15)' }}; color: {{ $notification->status === 'unread' ? '#d97706' : '#10b981' }}; border: 1px solid {{ $notification->status === 'unread' ? 'rgba(245, 158, 11, 0.3)' : 'rgba(16, 185, 129, 0.3)' }};">
                                    {{ $notification->status }}
                                </span>
                            </td>
                            <td>{{ \Carbon\Carbon::parse($notification->created_at)->format('Y-m-d H:i:s') }}</td>
                            <td>
                                <div style="display: flex; gap: 8px;">
                                    @if ($notification->status === 'unread')
                                        <button wire:click="markAsRead({{ $notification->id }})" style="background-color: #003699; color: #fff; border: 1px solid #003699; padding: 6px 14px; border-radius: 99px; cursor: pointer; font-size: 11px; font-weight: 600; font-family: 'Inter', sans-serif; transition: all 0.2s ease; display: flex; align-items: center; gap: 4px;" onmouseover="this.style.backgroundColor='#002873'; this.style.borderColor='#002873'; this.style.transform='scale(1.05)';" onmouseout="this.style.backgroundColor='#003699'; this.style.borderColor='#003699'; this.style.transform='none';">
                                            <i class="fa-solid fa-check"></i> Mark as Read
                                        </button>
                                    @endif
                                    <button wire:click="dismiss({{ $notification->id }})" style="background-color: transparent; color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); padding: 6px 14px; border-radius: 99px; cursor: pointer; font-size: 11px; font-weight: 600; font-family: 'Inter', sans-serif; transition: all 0.2s ease; display: flex; align-items: center; gap: 4px;" onmouseover="this.style.backgroundColor='rgba(239, 68, 68, 0.1)'; this.style.transform='scale(1.05)';" onmouseout="this.style.backgroundColor='transparent'; this.style.transform='none';">
                                        <i class="fa-solid fa-trash-can"></i> Dismiss
                                    </button>
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
</div>


