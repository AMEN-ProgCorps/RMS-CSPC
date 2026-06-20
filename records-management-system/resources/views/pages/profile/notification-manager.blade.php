<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

new #[Layout('layouts.profile')] #[Title('Profile Manager - Notification Manager')] class extends Component {
    public $notifications = [];
    public $officeName = 'No Office Assigned';

    public function mount()
    {
        $this->loadNotifications();
    }

    public function loadNotifications()
    {
        $userId = Auth::id();
        $user = Auth::user();
        $perms = $user?->permissions;

        // Get user's office details
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

        // Determine accessible subsystems based on role permissions
        $allowedSubsystems = ['Profile Manager']; // Profile Manager is always accessible by default
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

        // Fetch notifications
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

    public function markAsRead($notificationId)
    {
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

    public function dismiss($notificationId)
    {
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
@endpush

<div class="container">
    <div boxid_container="header" class="box-container">
        <div boxid="name" class="box">
            Notifications
        </div>
        <div boxid="datentime" class="box">
            <span id="currTime">--:--:--</span>
            <span id="currDate">--</span>
        </div>
    </div>
    <div class="visible-line">
        <hr>
    </div>
    <div boxid_container="details" class="box-container">
        <div boxid="details" class="box" style="max-width: 100%;">
            <span>Recent Notifications (Office: {{ $officeName }})</span>
            <hr>
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
                                <td>{{ $notification->subsystem_name }}</td>
                                <td>{{ $notification->content }}</td>
                                <td>
                                    <span class="badge" style="padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; text-transform: uppercase; background-color: {{ $notification->status === 'unread' ? '#ffc107' : '#28a745' }}; color: #fff;">
                                        {{ $notification->status }}
                                    </span>
                                </td>
                                <td>{{ \Carbon\Carbon::parse($notification->created_at)->format('Y-m-d H:i:s') }}</td>
                                <td>
                                    <div style="display: flex; gap: 5px;">
                                        @if ($notification->status === 'unread')
                                            <button wire:click="markAsRead({{ $notification->id }})" style="background-color: #043899; color: #fff; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 500; transition: all 0.2s ease;">
                                                Mark as Read
                                            </button>
                                        @endif
                                        <button wire:click="dismiss({{ $notification->id }})" style="background-color: #dc3545; color: #fff; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 500; transition: all 0.2s ease;">
                                            Dismiss
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">No notifications found for this account.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const timeEl = document.getElementById('currTime');
    const dateEl = document.getElementById('currDate');

    if (!timeEl || !dateEl) {
        return;
    }

    const updateDateTime = () => {
        const now = new Date();
        timeEl.textContent = now.toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
        dateEl.textContent = now.toLocaleDateString([], {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
    };

    updateDateTime();
    setInterval(updateDateTime, 1000);
});
</script>
