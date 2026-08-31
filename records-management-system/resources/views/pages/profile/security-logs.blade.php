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
    public ?int $currentRoleId = null;
    public array $currentPermissions = [];

    public function mount()
    {
        $userId = Auth::id(); // Get the authenticated user's ID
        
        $secLogsTable = \Illuminate\Support\Facades\Schema::hasTable('sys_security_logs') ? 'sys_security_logs' : 'security_logs';
        $secStatusTable = \Illuminate\Support\Facades\Schema::hasTable('sys_security_status') ? 'sys_security_status' : 'security_status';

        // Fetch security audit logs combined with security status names/descriptions
        $this->securityLogs = DB::table($secLogsTable . ' as security_logs')
            ->join($secStatusTable . ' as security_status', 'security_logs.status', '=', 'security_status.status_id')
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
                <i class="fa-solid fa-shield-halved" style="font-size: 24px;"></i>
            </div>
            <div class="hero-user-info">
                <span class="hero-greeting">Profile Manager</span>
                <h1 class="hero-name">Login History</h1>
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
                                <span class="badge" style="padding: 4px 10px; border-radius: 99px; font-size: 11px; font-weight: bold; text-transform: uppercase; background-color: {{ stripos($log->status_name, 'success') !== false ? 'rgba(16, 185, 129, 0.15)' : (stripos($log->status_name, 'logout') !== false ? 'rgba(245, 158, 11, 0.15)' : 'rgba(239, 68, 68, 0.15)') }}; color: {{ stripos($log->status_name, 'success') !== false ? '#10b981' : (stripos($log->status_name, 'logout') !== false ? '#f59e0b' : '#ef4444') }}; border: 1px solid {{ stripos($log->status_name, 'success') !== false ? 'rgba(16, 185, 129, 0.3)' : (stripos($log->status_name, 'logout') !== false ? 'rgba(245, 158, 11, 0.3)' : 'rgba(239, 68, 68, 0.3)') }};">
                                    {{ $log->status_name }}
                                </span>
                            </td>
                            <td>{{ $log->description }}</td>
                            <td style="font-family: monospace; color: #4a5568;">{{ $log->user_ipaddr }}</td>
                            <td class="log-time" data-time="{{ \Carbon\Carbon::parse($log->time)->getTimestamp() }}">{{ \Carbon\Carbon::parse($log->time)->format('Y-m-d h:i:s A') }}</td>
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
    // Update log times to user's local time and keep them in sync every second
    const pad = (v) => String(v).padStart(2, '0');
    const formatLocal = (d) => {
        const year = d.getFullYear();
        const month = pad(d.getMonth() + 1);
        const day = pad(d.getDate());
        let hours = d.getHours();
        const minutes = pad(d.getMinutes());
        const seconds = pad(d.getSeconds());
        const period = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12;
        if (hours === 0) hours = 12;
        const hourStr = pad(hours);
        return year + '-' + month + '-' + day + ' ' + hourStr + ':' + minutes + ':' + seconds + ' ' + period;
    };

    const updateLogTimes = () => {
        document.querySelectorAll('.log-time').forEach(el => {
            const ts = el.getAttribute('data-time');
            if (!ts) return;
            const ms = Number(ts) * 1000;
            if (Number.isNaN(ms)) return;
            const dt = new Date(ms);
            el.textContent = formatLocal(dt);
        });
    };

    updateLogTimes();
    setInterval(updateLogTimes, 1000);
});
</script>

