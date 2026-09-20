<?php

use App\Helpers\NetworkHelper;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Volt\Component;

new class extends Component {
    public int $idleTimeoutMinutes = 15;

    public function mount(): void
    {
        $this->idleTimeoutMinutes = $this->configuredIdleTimeoutMinutes();
    }

    public function activeHeartbeat(): void
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }

        $accDetailsTbl = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
        try {
            DB::table($accDetailsTbl)
                ->where('account_id', $user->id)
                ->update([
                    'is_currently_online' => true,
                    'last_online_time' => now(),
                ]);
        } catch (\Throwable) {}
    }

    public function stay(): void
    {
        $this->activeHeartbeat();
    }

    public function logoutNow()
    {
        $user = Auth::user();
        if ($user) {
            $secLogsTbl = Schema::hasTable('sys_security_logs') ? 'sys_security_logs' : 'security_logs';
            $accDetailsTbl = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';

            try {
                DB::table($secLogsTbl)->insert([
                    'status' => 3, // Inactivity / Auto-logout
                    'account' => $user->id,
                    'user_ipaddr' => NetworkHelper::getClientIp(),
                    'time' => now(),
                ]);
            } catch (\Throwable) {}

            try {
                DB::table($accDetailsTbl)
                    ->where('account_id', $user->id)
                    ->update([
                        'is_currently_online' => false,
                        'last_online_time' => now(),
                    ]);
            } catch (\Throwable) {}
        }

        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        $this->redirect(route('login'));
    }

    private function configuredIdleTimeoutMinutes(): int
    {
        $minutes = 15;
        try {
            $table = Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings';
            if (Schema::hasTable($table)) {
                $settingVal = DB::table($table)->where('key', 'tab_close_idle_timeout_minutes')->value('value');
                if ($settingVal !== null && is_numeric($settingVal) && (int) $settingVal > 0) {
                    $minutes = (int) $settingVal;
                }
            }
        } catch (\Throwable) {}

        return max(1, $minutes);
    }
};
?>

<div
    wire:ignore
    x-data="{
        lastActive: Date.now(),
        lastPing: Date.now(),
        warn: false,
        left: 60,
        timeoutMs: {{ (int) $idleTimeoutMinutes }} * 60 * 1000,
        warningMs: 60 * 1000,
        pingIntervalMs: 2 * 60 * 1000,

        onUserActivity() {
            this.lastActive = Date.now();
            if (this.warn) {
                this.warn = false;
                $wire.stay();
                this.lastPing = Date.now();
            } else if (Date.now() - this.lastPing > this.pingIntervalMs) {
                this.lastPing = Date.now();
                $wire.activeHeartbeat();
            }
        },

        tick() {
            const elapsed = Date.now() - this.lastActive;
            const remaining = this.timeoutMs - elapsed;

            if (remaining <= this.warningMs && remaining > 0) {
                this.warn = true;
                this.left = Math.max(1, Math.ceil(remaining / 1000));
            } else if (remaining <= 0) {
                this.warn = false;
                $wire.logoutNow();
            } else {
                this.warn = false;
            }
        },

        stay() {
            this.lastActive = Date.now();
            this.lastPing = Date.now();
            this.warn = false;
            $wire.stay();
        }
    }"
    x-init="
        setInterval(() => tick(), 1000);
        window.addEventListener('pagehide', () => {
            const data = new FormData();
            data.append('_token', '{{ csrf_token() }}');
            navigator.sendBeacon('{{ url('/api/session/tab-closed') }}', data);
        });
    "
    @mousemove.window.passive="onUserActivity()"
    @mousedown.window.passive="onUserActivity()"
    @keydown.window.passive="onUserActivity()"
    @touchstart.window.passive="onUserActivity()"
    @scroll.window.passive="onUserActivity()"
>
    <div class="inactivity-modal" x-show="warn" x-cloak style="z-index: 99999;">
        <div class="inactivity-overlay"></div>
        <div class="inactivity-box">
            <div class="inactivity-icon">
                <i class="fa-solid fa-clock-rotate-left"></i>
            </div>
            <h3>Are you still there?</h3>
            <p>You will be logged out in <span x-text="left">60</span> seconds due to inactivity.</p>
            <button type="button" class="btn-stay" @click="stay()">Stay Logged In</button>
        </div>
    </div>
</div>

<style>
    [x-cloak] { display: none !important; }
    .inactivity-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.65);
        backdrop-filter: blur(4px);
        z-index: 99998;
    }
    .inactivity-box {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: #ffffff;
        padding: 32px 40px;
        border-radius: 16px;
        text-align: center;
        z-index: 99999;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35);
        max-width: 420px;
        width: 90%;
        animation: inactivityPop 0.2s ease-out;
    }
    @keyframes inactivityPop {
        from { transform: translate(-50%, -46%) scale(0.95); opacity: 0; }
        to { transform: translate(-50%, -50%) scale(1); opacity: 1; }
    }
    .inactivity-icon {
        font-size: 2.5rem;
        color: #f59e0b;
        margin-bottom: 12px;
    }
    .inactivity-box h3 {
        margin: 0 0 8px;
        font-size: 1.35rem;
        font-weight: 700;
        color: #1e293b;
    }
    .inactivity-box p {
        margin: 0 0 24px;
        color: #64748b;
        font-size: 0.95rem;
        line-height: 1.5;
    }
    .inactivity-box span {
        font-weight: 700;
        color: #ef4444;
        font-size: 1.15rem;
    }
    .btn-stay {
        background: #2563eb;
        color: #ffffff;
        border: none;
        padding: 12px 28px;
        border-radius: 10px;
        font-weight: 600;
        font-size: 0.95rem;
        cursor: pointer;
        transition: all 0.2s ease;
        box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2);
    }
    .btn-stay:hover {
        background: #1d4ed8;
        transform: translateY(-1px);
        box-shadow: 0 6px 12px -2px rgba(37, 99, 235, 0.3);
    }
</style>
