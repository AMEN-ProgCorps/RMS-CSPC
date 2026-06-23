<?php
/**
 * Admin Console - Dashboard Main Page
 * 
 * Provides administrators with high-level statistics and a feed of recent security activities.
 * Mapped queries:
 *  - Total registered accounts (active + suspended)
 *  - Currently active accounts count
 *  - Aggregated system activities count (total security logs)
 *  - Recent security audit logs joined with account roles and personal names
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] #[Title('Admin Console - Dashboard')] class extends Component {
    
    /**
     * Fetch statistics metrics and recent activities for the dashboard view.
     * 
     * @return array Metrics and activity log collections
     */
    public function with(): array
    {
        // 1. Query counts
        $totalUsers = \App\Models\User::count();
        $activeUsers = \App\Models\User::where('account_active', true)->count();
        
        // Summarize all activities (security logs + admin changes logs)
        $totalActivities = \DB::table('security_logs')->count() + \DB::table('admin_logs')->count();

        // 2. Fetch union of security logs and admin logs, sorted by time
        $securityQuery = \DB::table('security_logs')
            ->leftJoin('security_status', 'security_logs.status', '=', 'security_status.status_id')
            ->leftJoin('account', 'security_logs.account', '=', 'account.id')
            ->leftJoin('account_details', 'account.id', '=', 'account_details.account_id')
            ->leftJoin('condition_key', 'account.account_role', '=', 'condition_key.id')
            ->select([
                'security_logs.time as time',
                'security_status.status_name as action_name',
                'account.username',
                'account_details.first_name',
                'account_details.last_name',
                'condition_key.key_name as role_name',
            ]);

        $adminQuery = \DB::table('admin_logs')
            ->leftJoin('account', 'admin_logs.admin_id', '=', 'account.id')
            ->leftJoin('account_details', 'account.id', '=', 'account_details.account_id')
            ->leftJoin('condition_key', 'account.account_role', '=', 'condition_key.id')
            ->select([
                'admin_logs.when_changes as time',
                'admin_logs.changes as action_name',
                'account.username',
                'account_details.first_name',
                'account_details.last_name',
                'condition_key.key_name as role_name',
            ]);

        $activities = $securityQuery->unionAll($adminQuery)
            ->orderBy('time', 'desc')
            ->limit(7) // Retrieve last 7 items matching the artist mockup list size
            ->get();

        return [
            'totalUsers' => $totalUsers,
            'activeUsers' => $activeUsers,
            'totalActivities' => $totalActivities,
            'activities' => $activities,
        ];
    }
};
?>

@push('styles')
    @vite('resources/css/admin/console_dashboard.css')
@endpush

<div class="dashboard-wrapper">
    <h1 class="dashboard-title">Dashboard</h1>

    <!-- Statistics Grid -->
    <div class="stats-grid">
        <!-- Card 1: Total Users -->
        <div class="stat-card">
            <div class="stat-info">
                <span class="stat-label">Total Users</span>
                <span class="stat-value">{{ $totalUsers }}</span>
            </div>
            <div class="stat-icon-wrapper">
                <!-- Group of Users Icon -->
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 28 28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H4a4 4 0 0 0-4 4v2" />
                    <circle cx="8" cy="7" r="4" />
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                    <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                </svg>
            </div>
        </div>

        <!-- Card 2: Active Users -->
        <div class="stat-card">
            <div class="stat-info">
                <span class="stat-label">Active Users</span>
                <span class="stat-value">{{ $activeUsers }}</span>
            </div>
            <div class="stat-icon-wrapper">
                <!-- User with Checkmark Icon -->
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 28 28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H4a4 4 0 0 0-4 4v2" />
                    <circle cx="8" cy="7" r="4" />
                    <polyline points="17 11 19 13 23 9" />
                </svg>
            </div>
        </div>

        <!-- Card 3: Activities -->
        <div class="stat-card">
            <div class="stat-info">
                <span class="stat-label">Activities</span>
                <span class="stat-value">{{ $totalActivities }}</span>
            </div>
            <div class="stat-icon-wrapper">
                <!-- Heartbeat Graph Icon -->
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 28 28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 14h5l3 9L15 5l3 9h5" />
                </svg>
            </div>
        </div>
    </div>

    <!-- Recent Activities Panel -->
    <div class="activities-panel" wire:poll.15s>
        <h2 class="activities-title">Recent Activities</h2>
        
        <div class="activities-list">
            @forelse($activities as $activity)
                    @php
                    // Resolve display name: use full name if available, fall back to username
                    $displayName = ($activity->first_name || $activity->last_name) 
                        ? trim($activity->first_name . ' ' . $activity->last_name)
                        : ($activity->username ?? 'Anonymous System');

                    // Resolve role name
                    $roleLabel = $activity->role_name ?: 'System';

                    // Keep the date server-side (do not change it) and render the time as ISO for client-side sync
                    $formattedDate = \Carbon\Carbon::parse($activity->time)->format('M d,');
                    $isoTime = \Carbon\Carbon::parse($activity->time)->toIso8601String();
                    $formattedTime = \Carbon\Carbon::parse($activity->time)->format('g:i:s A');
                @endphp
                <div class="activity-row">
                    <div class="activity-left">
                        <span class="activity-name">{{ $displayName }}</span>
                        <span class="activity-meta">{{ $roleLabel }} &nbsp;&nbsp;&nbsp; <span class="activity-date">{{ $formattedDate }}</span> <span class="activity-time" data-time="{{ $isoTime }}">{{ $formattedTime }}</span></span>
                    </div>
                    <div class="activity-right">
                        <span>{{ $activity->action_name }}</span>
                    </div>
                </div>
            @empty
                <div style="text-align: center; color: #94a3b8; padding: 24px; font-size: 13.5px;">
                    No recent activities recorded.
                </div>
            @endforelse
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const pad = (v) => String(v).padStart(2, '0');

    const formatLocalTime = (d) => {
        let hours = d.getHours();
        const minutes = pad(d.getMinutes());
        const seconds = pad(d.getSeconds());
        const period = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12;
        if (hours === 0) hours = 12;
        return String(hours).padStart(2, '0') + ':' + minutes + ':' + seconds + ' ' + period;
    };

    const updateActivityTimes = () => {
        document.querySelectorAll('.activity-time').forEach(el => {
            const iso = el.getAttribute('data-time');
            if (!iso) return;
            const dt = new Date(iso);
            if (isNaN(dt)) return;
            el.textContent = formatLocalTime(dt);
        });
    };

    updateActivityTimes();
    setInterval(updateActivityTimes, 1000);
});
</script>
