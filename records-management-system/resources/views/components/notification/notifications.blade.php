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

        $allowedSubsystems = \App\Helpers\RegisterQueryHelper::scopeBellSubsystemsForRequest($allowedSubsystems);

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
            ->where(function ($query) use ($notifDivTbl) {
                $query->whereNull("{$notifDivTbl}.is_dismissed")
                      ->orWhere("{$notifDivTbl}.is_dismissed", false);
            })
            ->where(function ($query) use ($notifDivTbl) {
                $sessionId = session()->getId();
                $query->whereNull("{$notifDivTbl}.status")
                      ->orWhere("{$notifDivTbl}.status", '!=', 'read')
                      ->orWhere(function ($subQuery) use ($notifDivTbl, $sessionId) {
                          $subQuery->where("{$notifDivTbl}.status", '=', 'read')
                                   ->where("{$notifDivTbl}.read_at_session", '=', $sessionId);
                      });
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

        $this->notifications = \App\Helpers\RegisterQueryHelper::filterBellNotifications($this->notifications);

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

        // 2. Office intake submissions / correction unlocks
        if ($notification && $notification->redirect_url) {
            $intake = \App\Helpers\OfficeIntakeHelper::parseIntakeNotificationUrl($notification->redirect_url);
            if ($intake) {
                $this->showDropdown = false;
                $this->dispatch('close-notifications');

                // Office correction unlock → open the editable form directly
                if (! empty($intake['edit'])) {
                    $this->redirect(
                        route(
                            $intake['type'] === 'dcn' ? 'dcs.office.dcn.edit' : 'dcs.office.drf.edit',
                            $intake['id'],
                            absolute: false
                        ),
                        navigate: false
                    );

                    return;
                }

                if (\App\Helpers\RegisterQueryHelper::canBrowseAllOfficeIntake()) {
                    $this->redirect(
                        \App\Helpers\OfficeIntakeHelper::rfioOpenIntakeUrl($intake['type'], $intake['id']),
                        navigate: false
                    );

                    return;
                }

                // Office user: open their submitted form (view/show — edit if unlocked)
                $this->redirect(
                    route(
                        $intake['type'] === 'dcn' ? 'dcs.office.dcn.show' : 'dcs.office.drf.show',
                        $intake['id'],
                        absolute: false
                    ),
                    navigate: false
                );

                return;
            }

            // Limited intake users must never be sent to register/stamping/etc.
            // Registered-success links (/dcs/office/...?registered=1) open the form show page.
            if (\App\Helpers\RegisterQueryHelper::isLimitedDcsUser()) {
                $this->showDropdown = false;
                $this->dispatch('close-notifications');

                $path = ltrim((string) (parse_url((string) $notification->redirect_url, PHP_URL_PATH) ?? ''), '/');
                if (preg_match('#^dcs/office/(drf|dcn)/(\d+)$#', $path, $matches)) {
                    $this->redirect(
                        route(
                            $matches[1] === 'dcn' ? 'dcs.office.dcn.show' : 'dcs.office.drf.show',
                            (int) $matches[2],
                            absolute: false
                        ),
                        navigate: false
                    );

                    return;
                }

                $this->redirect(route('dcs.office.drf.index', absolute: false), navigate: true);

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
                'read_at_session' => session()->getId(),
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
        $sessionId = session()->getId();
        
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
                        'read_at_session' => $sessionId,
                        'processed_on' => now()
                    ]
                );
            }
        }
        $this->loadNotifications();
        $this->dispatch('rms-notification-updated');
    }

    /**
     * Clears a single notification from the dropdown modal (sets is_dismissed to true).
     *
     * @param int $notificationId The ID of the notification
     */
    public function clearNotification($notificationId)
    {
        $this->dismissNotification($notificationId);
    }

    /**
     * Dismisses a single notification from the dropdown modal (sets is_dismissed to true).
     *
     * @param int $notificationId The ID of the notification
     */
    public function dismissNotification($notificationId)
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
                'is_dismissed' => true,
                'processed_on' => now()
            ]
        );
        $this->loadNotifications();
        $this->dispatch('rms-notification-updated');
    }

    /**
     * Mass dismisses all currently visible notifications in the dropdown modal.
     */
    public function clearAllNotifications()
    {
        $notifTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_notifications') ? 'sys_notifications' : 'notifications';
        $notifDivTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_notification_div') ? 'sys_notification_div' : 'notification_div';

        $userId = Auth::id();
        foreach ($this->notifications as $item) {
            $exists = DB::table($notifTbl)->where('id', $item->id)->exists();
            if ($exists) {
                DB::table($notifDivTbl)->updateOrInsert(
                    [
                        'id' => $item->id,
                        'account_rec' => $userId
                    ],
                    [
                        'is_dismissed' => true,
                        'processed_on' => now()
                    ]
                );
            }
        }
        $this->loadNotifications();
        $this->dispatch('rms-notification-updated');
    }

    /**
     * Deletes a notification from user list (sets is_in_user_list to false).
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

<div
    class="notif-wrapper"
    wire:poll.8s="loadNotifications"
    x-data="{ open: @entangle('showDropdown') }"
    @click.outside="open = false"
    @close-notifications.window="open = false"
>
    <!-- Bell Button -->
    <button
        class="notif-bell-btn"
        @click="
            open = !open;
            if (open) {
                $wire.loadNotifications();
                window.closeActionsDropdown ? window.closeActionsDropdown() : (typeof closeActionsDropdown !== 'undefined' ? closeActionsDropdown() : null);
            }
        "
        type="button"
        aria-label="Toggle notifications menu"
    >
        <svg class="notif-bell-svg" viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
        </svg>
        <span
            id="header-notif-badge"
            class="notif-badge"
            data-system-unread="{{ (int) $unreadCount }}"
            @if($unreadCount < 1) style="display: none;" @endif
        ></span>
    </button>

    <!-- Dropdown Menu -->
    <div class="notif-dropdown" x-show="open" x-transition.opacity.scale x-cloak>
        <div class="notif-dropdown-header">
            <h3>Notifications</h3>
            <div class="notif-header-actions">
                @if ($unreadCount >= 2)
                    <button class="notif-mark-all-btn" wire:click="markAllAsRead" type="button">
                        Mark all as read
                    </button>
                @endif
                @if (count($notifications) > 0)
                    <button class="notif-clear-all-btn" wire:click="clearAllNotifications" type="button">
                        Clear all notification
                    </button>
                @endif
            </div>
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
                            <button class="notif-action-item" wire:click="clearNotification({{ $notification->id }}); menuOpen = false" type="button">
                                Clear
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
                    <p>No notification currently at this session..</p>
                </div>
            @endforelse
        </div>
    </div>
</div>
