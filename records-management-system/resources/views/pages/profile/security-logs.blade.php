<?php
/**
 * Profile Manager - Security Logs Volt Component
 * 
 * This component fetches and displays the authenticated user's login history
 * and other security audit logs from the database.
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

new #[Layout('layouts.profile')] #[Title('Profile Manager - Security Logs')] class extends Component {
    /** @var array<int, mixed> Holds the collection of queried security logs */
    public $securityLogs = [];

    /**
     * Component mount hook - fetches security logs for the active user account.
     */
    public function mount()
    {
        $userId = Auth::id(); // Get the authenticated user's ID
        
        // Fetch security audit logs combined with security status names/descriptions
        $this->securityLogs = DB::table('security_logs')
            ->join('security_status', 'security_logs.status', '=', 'security_status.status_id')
            ->where('security_logs.account', $userId)
            ->orderBy('security_logs.time', 'desc')
            ->select(
                'security_logs.id',
                'security_status.status_name',
                'security_status.description',
                'security_logs.user_ipaddr',
                'security_logs.time'
            )
            ->get();
    }
};
?>

@push('styles')
    @vite('resources/css/profile/personal_details.css')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
@endpush

<div class="container personal-details-container">
    <!-- Hero Banner -->
    <div class="profile-hero-banner">
        <div class="hero-left">
            <div class="avatar-circle">
                <i class="fa-solid fa-shield-halved" style="font-size: 24px;"></i>
            </div>
            <div class="hero-user-info">
                <span class="hero-greeting">Profile Manager</span>
                <h1 class="hero-name">Login History</h1>
            </div>
        </div>
        <div class="hero-right">
            <div class="hero-clock">
                <span id="currTime" class="clock-time">--:--:--</span>
                <span id="currDate" class="clock-date">--</span>
            </div>
        </div>
    </div>

    <!-- Details Card (Table) -->
    <div class="profile-card" style="width: 100%; box-sizing: border-box;">
        <h2 class="card-title">
            <i class="fa-solid fa-history"></i> Recent Login Activities
        </h2>
        
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Description</th>
                        <th>IP Address</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($securityLogs as $log)
                        <tr>
                            <td>
                                <span class="badge" style="padding: 4px 10px; border-radius: 99px; font-size: 11px; font-weight: bold; text-transform: uppercase; background-color: {{ strtolower($log->status_name) === 'success' ? 'rgba(16, 185, 129, 0.15)' : 'rgba(239, 68, 68, 0.15)' }}; color: {{ strtolower($log->status_name) === 'success' ? '#10b981' : '#ef4444' }}; border: 1px solid {{ strtolower($log->status_name) === 'success' ? 'rgba(16, 185, 129, 0.3)' : 'rgba(239, 68, 68, 0.3)' }};">
                                    {{ $log->status_name }}
                                </span>
                            </td>
                            <td>{{ $log->description }}</td>
                            <td style="font-family: monospace; color: #4a5568;">{{ $log->user_ipaddr }}</td>
                            <td>{{ \Carbon\Carbon::parse($log->time)->format('Y-m-d H:i:s') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="no-permissions" style="text-align: center; padding: 24px;">No security logs found for this account.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
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

