<?php

use App\Helpers\NetworkHelper;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;

new class extends Component {
    public function ping(): void
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }

        DB::table('account_details')
            ->where('account_id', $user->id)
            ->update([
                'is_currently_online' => true,
                'last_online_time' => now(),
            ]);
    }

    public function stay(): void
    {
        $this->ping();
    }

    public function logoutNow()
    {
        $user = Auth::user();
        if ($user) {
            DB::table('security_logs')->insert([
                'status' => 3,
                'account' => $user->id,
                'user_ipaddr' => NetworkHelper::getClientIp(),
                'time' => now(),
            ]);

            DB::table('account_details')
                ->where('account_id', $user->id)
                ->update([
                    'is_currently_online' => false,
                    'last_online_time' => now(),
                ]);
        }

        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        $this->redirect(route('login'));
    }
};
?>

<div
    wire:poll.30s="ping"
    x-data="{
        last: Date.now(),
        warn: false,
        left: 60,
        timeout: 15 * 60 * 1000,
        warning: 60 * 1000,
        tick() {
            const elapsed = Date.now() - this.last;
            this.warn = elapsed >= this.timeout - this.warning;
            this.left = Math.max(0, Math.ceil((this.timeout - elapsed) / 1000));
            if (elapsed >= this.timeout) {
                $wire.logoutNow();
            }
        },
        reset() {
            this.last = Date.now();
            this.warn = false;
        }
    }"
    x-init="setInterval(() => tick(), 2000)"
    @mousedown.window="reset()"
    @keydown.window="reset()"
    @touchstart.window="reset()"
>
    <div class="inactivity-modal" x-show="warn" x-cloak>
        <div class="inactivity-overlay"></div>
        <div class="inactivity-box">
            <h3>Are you still there?</h3>
            <p>You will be logged out in <span x-text="left">60</span> seconds due to inactivity.</p>
            <button type="button" class="btn-stay" wire:click="stay" @click="reset()">Stay Logged In</button>
        </div>
    </div>
</div>

<style>
    [x-cloak] { display: none !important; }
    .inactivity-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 9998; }
    .inactivity-box { position: fixed; top: 50%; left: 50%; transform: translate(-50%,-50%); background: white; padding: 30px 40px; border-radius: 12px; text-align: center; z-index: 9999; box-shadow: 0 20px 60px rgba(0,0,0,0.4); }
    .inactivity-box h3 { margin-bottom: 10px; font-size: 1.3rem; color: #1a1a1a; }
    .inactivity-box p { margin-bottom: 20px; color: #555; font-size: 0.95rem; }
    .inactivity-box span { font-weight: bold; color: #e74c3c; font-size: 1.1rem; }
    .btn-stay { background: #FFB800; color: #0d2a7a; border: none; padding: 10px 30px; border-radius: 8px; font-weight: bold; font-size: 1rem; cursor: pointer; }
    .btn-stay:hover { background: #e6a600; }
</style>
