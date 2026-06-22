<?php
/**
 * Shared Header Notification Bell and Dropdown Volt Component
 */

use Livewire\Volt\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

new class extends Component {
    /** @var mixed Holds the collection of active notifications for display */
    public $notifications = [];

    /** @var int Tracks total count of unread notifications */
    public $unreadCount = 0;

    /** @var bool Controlled via Alpine.js, keeps sync of dropdown state */
    public $showDropdown = false;

    /**
     * Component mount hook.
     */
    public function mount()
    {
        $this->loadNotifications();
    }

    /**
     * Loads notifications for the current authenticated user and office scope,
     * restricted by accessible subsystems.
     */
    public function loadNotifications()
    {
        $userId = Auth::id();
        if (!$userId) {
            $this->notifications = collect();
            $this->unreadCount = 0;
            return;
        }

        $user = Auth::user();
        $perms = $user?->permissions;

        // Get user's office details to restrict notifications scoping
        $office = DB::table('account_details')
            ->join('office', 'account_details.office_id', '=', 'office.id')
            ->where('account_details.account_id', $userId)
            ->select('office.office_code')
            ->first();

        if (!$office) {
            $this->notifications = collect();
            $this->unreadCount = 0;
            return;
        }

        // Determine accessible subsystems based on role permissions
        $allowedSubsystems = ['Profile Manager']; // Default accessible subsystem
        if ($perms) {
            if ($perms->is_sadm) {
                $allowedSubsystems[] = 'Document Tracking System';
                $allowedSubsystems[] = 'Records Disposition Program';
                $allowedSubsystems[] = 'Admin Console';
            } else {
                if ($perms->can_access_dts) {
                    $allowedSubsystems[] = 'Document Tracking System';
                }
                if ($perms->can_access_archv) {
                    $allowedSubsystems[] = 'Records Disposition Program';
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
            ->where('notifications.office', $office->office_code)
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

        $this->unreadCount = $this->notifications->where('status', 'unread')->count();
    }

    /**
     * Marks an active notification as read.
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
     * Marks all unread notifications visible to the user as read.
     */
    public function markAllAsRead()
    {
        $userId = Auth::id();
        $unreadItems = $this->notifications->where('status', 'unread');
        
        foreach ($unreadItems as $item) {
            $exists = DB::table('notifications')->where('id', $item->id)->exists();
            if ($exists) {
                DB::table('notification_div')->updateOrInsert(
                    [
                        'id' => $item->id,
                        'account_rec' => $userId
                    ],
                    [
                        'status' => 'read',
                        'processed_on' => now()
                    ]
                );
            }
        }
        $this->loadNotifications();
    }

    /**
     * Dismisses/deletes a notification for the current user.
     * 
     * @param int $notificationId The ID of the notification
     */
    public function deleteNotification($notificationId)
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

<div class="notif-wrapper" x-data="{ open: @entangle('showDropdown') }" @click.outside="open = false">
    <!-- Bell Button -->
    <button class="notif-bell-btn" @click="open = !open" type="button" aria-label="Toggle notifications menu">
        <svg class="notif-bell-svg" viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
        </svg>
        @if ($unreadCount > 0)
            <span class="notif-badge"></span>
        @endif
    </button>

    <!-- Dropdown Menu -->
    <div class="notif-dropdown" x-show="open" x-transition.opacity.scale x-cloak>
        <div class="notif-dropdown-header">
            <h3>Notifications</h3>
            @if ($unreadCount > 0)
                <button class="notif-mark-all-btn" wire:click="markAllAsRead" type="button">
                    Mark all as read
                </button>
            @endif
        </div>

        <div class="notif-dropdown-body">
            @forelse ($notifications as $notification)
                <div class="notif-item">
                    <!-- Status dot indicator -->
                    @if ($notification->status === 'unread')
                        <div class="notif-unread-dot" title="Unread"></div>
                    @else
                        <div class="notif-read-placeholder"></div>
                    @endif

                    <!-- Content -->
                    <div class="notif-content-wrapper">
                        <p class="notif-message">{{ $notification->content }}</p>
                        <span class="notif-time">{{ \Carbon\Carbon::parse($notification->created_at)->diffForHumans() }}</span>
                    </div>

                    <!-- Item Actions Menu -->
                    <div class="notif-menu-wrapper" x-data="{ menuOpen: false }" @click.outside="menuOpen = false">
                        <button class="notif-actions-btn" @click.stop="menuOpen = !menuOpen" type="button" aria-label="Notification actions">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="1"></circle>
                                <circle cx="19" cy="12" r="1"></circle>
                                <circle cx="5" cy="12" r="1"></circle>
                            </svg>
                        </button>
                        <div class="notif-actions-menu" x-show="menuOpen" x-transition x-cloak>
                            @if ($notification->status === 'unread')
                                <button class="notif-action-item" wire:click="markAsRead({{ $notification->id }}); menuOpen = false" type="button">
                                    Mark as Read
                                </button>
                            @endif
                            <button class="notif-action-item delete" wire:click="deleteNotification({{ $notification->id }}); menuOpen = false" type="button">
                                Delete
                            </button>
                        </div>
                    </div>
                </div>
            @empty
                <div class="notif-empty-state">
                    <svg style="margin: 0 auto 12px auto; display: block;" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#d1d5db" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                    </svg>
                    <p>No notifications found for this account.</p>
                </div>
            @endforelse
        </div>
    </div>
</div>
