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

        $accDetailsTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
        $officeTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $notifTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_notifications') ? 'sys_notifications' : 'notifications';
        $notifContentTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_notif_content') ? 'sys_notif_content' : 'notif_content';
        $subsystemsTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems';
        $notifDivTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_notification_div') ? 'sys_notification_div' : 'notification_div';

        // Get user's office details to restrict notifications scoping
        $office = DB::table($accDetailsTbl)
            ->join($officeTbl, "{$accDetailsTbl}.office_id", '=', "{$officeTbl}.id")
            ->where("{$accDetailsTbl}.account_id", $userId)
            ->select("{$officeTbl}.office_code")
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

        if (request()->is('dcs', 'dcs/*') && in_array('Document Control System', $allowedSubsystems, true)) {
            $allowedSubsystems = ['Document Control System'];
        }

        // Fetch notifications list, combining with read/unread statuses in notification_div table
        $this->notifications = DB::table($notifTbl)
            ->join($notifContentTbl, "{$notifTbl}.contents", '=', "{$notifContentTbl}.id")
            ->join($subsystemsTbl, "{$notifContentTbl}.system", '=', "{$subsystemsTbl}.subsystem_id")
            ->leftJoin($notifDivTbl, function ($join) use ($userId, $notifTbl, $notifDivTbl) {
                $join->on("{$notifTbl}.id", '=', "{$notifDivTbl}.id")
                     ->where("{$notifDivTbl}.account_rec", '=', $userId);
            })
            ->where("{$notifTbl}.office", $office->office_code)
            ->whereIn("{$subsystemsTbl}.subsystem_name", $allowedSubsystems)
            ->where(function ($query) use ($notifDivTbl) {
                $query->whereNull("{$notifDivTbl}.is_in_user_list")
                      ->orWhere("{$notifDivTbl}.is_in_user_list", 1);
            })
            ->orderBy("{$notifTbl}.created_at", 'desc')
            ->select(
                "{$notifTbl}.id",
                "{$subsystemsTbl}.subsystem_name",
                "{$notifContentTbl}.content",
                "{$notifContentTbl}.redirect_url",
                "{$notifTbl}.created_at",
                DB::raw("COALESCE({$notifDivTbl}.status, 'unread') as status")
            )
            ->get();

        $this->unreadCount = $this->notifications->where('status', 'unread')->count();
    }

    /**
     * Handles notification message click: marks it as read and redirects if redirect_url is populated.
     */
    public function handleNotificationClick($notificationId)
    {
        $notifTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_notifications') ? 'sys_notifications' : 'notifications';
        $notifContentTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_notif_content') ? 'sys_notif_content' : 'notif_content';

        $notification = DB::table($notifTbl)
            ->join($notifContentTbl, "{$notifTbl}.contents", '=', "{$notifContentTbl}.id")
            ->where("{$notifTbl}.id", $notificationId)
            ->select("{$notifContentTbl}.redirect_url")
            ->first();

        // 1. Mark notification as read
        $this->markAsRead($notificationId);

        // 2. Office intake submissions open in a modal on the current DCS page
        // (Livewire requests hit /livewire/update — do not use request()->is('dcs*') here)
        if ($notification && $notification->redirect_url) {
            $intake = \App\Helpers\OfficeIntakeHelper::parseIntakeNotificationUrl($notification->redirect_url);
            if ($intake) {
                $this->showDropdown = false;
                $this->dispatch('close-notifications');

                if ($this->isOnDcsPage()) {
                    $this->dispatch('open-office-intake-modal', type: $intake['type'], id: $intake['id']);
                    $this->js(
                        'window.dispatchEvent(new CustomEvent("open-office-intake-modal",{detail:'
                        . json_encode(['type' => $intake['type'], 'id' => $intake['id']])
                        . '}));'
                    );
                } else {
                    $this->redirect(
                        '/dcs?intake=' . $intake['type'] . '&id=' . $intake['id'],
                        navigate: false
                    );
                }

                return;
            }

            $this->redirect($notification->redirect_url, navigate: true);
        }
    }

    /**
     * Detect whether the user is currently viewing a DCS page.
     * Livewire updates use /livewire/update, so check the referer / previous URL.
     */
    private function isOnDcsPage(): bool
    {
        $candidates = [
            (string) request()->headers->get('referer'),
            (string) url()->previous(),
        ];

        foreach ($candidates as $url) {
            if ($url === '') {
                continue;
            }
            $path = ltrim((string) (parse_url($url, PHP_URL_PATH) ?? ''), '/');
            if ($path === 'dcs' || str_starts_with($path, 'dcs/')) {
                return true;
            }
        }

        return request()->is('dcs', 'dcs/*');
    }

    /**
     * Marks an active notification as read.
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
                'processed_on' => now()
            ]
        );
        $this->loadNotifications();
        $this->dispatch('rms-notification-updated');
    }

    /**
     * Marks all unread notifications visible to the user as read.
     */
    public function markAllAsRead()
    {
        $notifTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_notifications') ? 'sys_notifications' : 'notifications';
        $notifDivTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_notification_div') ? 'sys_notification_div' : 'notification_div';

        $userId = Auth::id();
        $unreadItems = $this->notifications->where('status', 'unread');
        
        foreach ($unreadItems as $item) {
            $exists = DB::table($notifTbl)->where('id', $item->id)->exists();
            if ($exists) {
                DB::table($notifDivTbl)->updateOrInsert(
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
        $this->dispatch('rms-notification-updated');
    }

    /**
     * Dismisses/deletes a notification for the current user.
     * 
     * @param int $notificationId The ID of the notification
     */
    public function deleteNotification($notificationId)
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
                'is_in_user_list' => false,
                'processed_on' => now()
            ]
        );
        $this->loadNotifications();
        $this->dispatch('rms-notification-updated');
    }
};
?>

<div class="notif-wrapper" x-data="{ open: @entangle('showDropdown') }" @click.outside="open = false" @close-notifications.window="open = false">
    <!-- Bell Button -->
    <button class="notif-bell-btn" @click="open = !open; if (open) { window.closeActionsDropdown ? window.closeActionsDropdown() : (typeof closeActionsDropdown !== 'undefined' ? closeActionsDropdown() : null); }" type="button" aria-label="Toggle notifications menu">
        <svg class="notif-bell-svg" viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
        </svg>
        <span id="header-notif-badge" class="notif-badge" style="{{ $unreadCount > 0 ? '' : 'display: none;' }}"></span>
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
                    <div class="notif-content-wrapper" wire:click="handleNotificationClick({{ $notification->id }})" style="cursor: pointer; flex-grow: 1;">
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
