<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

new #[Layout('layouts.profile')] #[Title('Profile Manager - Security Logs')] class extends Component {
    public $securityLogs;

    public function mount()
    {
        $userId = Auth::id(); // Get the authenticated user's ID
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
@push('style')
    @vite('resources/css/profile/personal_details.css')
@endpush
<div class="container">
    <div boxid_container="header" class="box-container">
        <div boxid="name" class="box">
            Login History
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
        <div boxid="details" class="box">
            <span>Recent Login Activities</span>
            <hr>
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
                                <td>{{ $log->status_name }}</td>
                                <td>{{ $log->description }}</td>
                                <td>{{ $log->user_ipaddr }}</td>
                                <td>{{ \Carbon\Carbon::parse($log->time)->format('Y-m-d H:i:s') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4">No security logs found for this account.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
