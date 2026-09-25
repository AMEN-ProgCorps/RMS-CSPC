<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UpdateUserOnlineStatus
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected static ?array $tableNames = null;

    /**
     * Resolve and statically memoize table names across requests in worker process.
     */
    protected function getTableNames(): array
    {
        if (static::$tableNames !== null) {
            return static::$tableNames;
        }

        return static::$tableNames = [
            'sys_system_settings' => \Illuminate\Support\Facades\Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings',
            'sys_account_details' => \Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details',
            'sys_security_logs'   => \Illuminate\Support\Facades\Schema::hasTable('sys_security_logs') ? 'sys_security_logs' : 'security_logs',
            'sys_security_status' => \Illuminate\Support\Facades\Schema::hasTable('sys_security_status') ? 'sys_security_status' : 'security_status',
        ];
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if (! $user) {
            return $next($request);
        }

        $tables = $this->getTableNames();
        $sysSettingsTbl = $tables['sys_system_settings'];
        $accDetailsTbl  = $tables['sys_account_details'];
        $secLogsTbl     = $tables['sys_security_logs'];
        $secStatusTbl   = $tables['sys_security_status'];

        // 1. Determine configured session inactivity / tab-close timeout (Default: 15 minutes, cached 5 min)
        $timeoutMinutes = \Illuminate\Support\Facades\Cache::remember('tab_close_idle_timeout_minutes', 300, function () use ($sysSettingsTbl) {
            try {
                $settingVal = DB::table($sysSettingsTbl)->where('key', 'tab_close_idle_timeout_minutes')->value('value');
                if ($settingVal !== null && is_numeric($settingVal) && (int) $settingVal > 0) {
                    return (int) $settingVal;
                }
            } catch (\Throwable) {}
            return 15;
        });

        $isBackgroundPoll = $this->isAutomatedBackgroundPoll($request);

        if ($user) {
            $details = DB::table($accDetailsTbl)->where('account_id', $user->id)->first();
            $now = now();

            // Helper to build appropriate logout response depending on request type (AJAX vs Chatify iframe vs Main page)
            $buildLogoutResponse = function (string $reasonMessage) use ($request) {
                if ($request->ajax() || $request->wantsJson() || $request->header('X-Livewire') || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                    $response = response()->json([
                        'error' => 'Unauthenticated',
                        'message' => $reasonMessage,
                        'redirect' => route('login'),
                    ], 401);

                    if ($request->header('X-Livewire')) {
                        $response->header('X-Livewire-Redirect', route('login'));
                    }

                    return $response;
                }

                if ($request->is('chat/unread-count')) {
                    return response()->json([
                        'error' => 'Unauthenticated',
                        'message' => $reasonMessage,
                        'redirect' => route('login'),
                        'unread' => 0,
                    ], 401);
                }

                if ($request->is('open-chat', 'chatify*')) {
                    return response('<!DOCTYPE html><html><head><script>if(window.top){window.top.location.href="' . route('login') . '";}</script></head><body></body></html>', 401)
                        ->header('Content-Type', 'text/html');
                }

                return redirect()->route('login')->with('error', $reasonMessage);
            };

            // A. Check if Admin modified account (Forced Logout)
            // Admin-forced logout is always enforced, even on background polls,
            // because it is an explicit administrative action, not a passive timeout.
            if ($details && $details->force_logout_at !== null) {
                try {
                    DB::table($secLogsTbl)->insert([
                        'status' => 3, // Logout
                        'account' => $user->id,
                        'user_ipaddr' => \App\Helpers\NetworkHelper::getClientIp(),
                        'time' => $now,
                    ]);
                } catch (\Throwable) {}

                DB::table($accDetailsTbl)
                    ->where('account_id', $user->id)
                    ->update([
                        'force_logout_at' => null,
                        'is_currently_online' => false,
                    ]);

                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return $buildLogoutResponse('Your account details were updated by an Administrator. Please sign in again.');
            }

            // B. Check if inactive for longer than configured timeout
            // IMPORTANT: Skip this check for automated background polls (e.g. chat/unread-count,
            // wire:poll). Chatify's iframe requests go directly to standalone PHP files and bypass
            // this Laravel middleware entirely, so they never refresh last_online_time here.
            // Without this guard, a passive poll would see the stale timestamp and force a logout
            // even though the user is actively chatting — the poll should observe, not enforce.
            if (! $isBackgroundPoll && $details && $details->last_online_time !== null) {
                $lastOnline = \Carbon\Carbon::parse($details->last_online_time);
                if ($lastOnline->diffInMinutes($now) >= $timeoutMinutes) {
                    $statusId = 3;
                    try {
                        if (DB::table($secStatusTbl)->where('status_id', 8)->exists()) {
                            $statusId = 8;
                        }
                    } catch (\Throwable) {}

                    try {
                        DB::table($secLogsTbl)->insert([
                            'status' => $statusId, // Session Timeout (8) or Logout (3)
                            'account' => $user->id,
                            'user_ipaddr' => \App\Helpers\NetworkHelper::getClientIp(),
                            'time' => $now,
                        ]);
                    } catch (\Throwable) {}

                    DB::table($accDetailsTbl)
                        ->where('account_id', $user->id)
                        ->update(['is_currently_online' => false]);

                    Auth::logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();

                    return $buildLogoutResponse("Session expired due to {$timeoutMinutes} minutes of inactivity or tab closure.");
                }
            }

            // C. Update current user activity ONLY if this was NOT an automated background poll
            if (! $isBackgroundPoll) {
                DB::table($accDetailsTbl)
                    ->where('account_id', $user->id)
                    ->update([
                        'is_currently_online' => true,
                        'last_online_time' => $now,
                    ]);
            }
        }

        // 2. Mark users who haven't made a request in $timeoutMinutes as offline (throttled to once per minute)
        if (\Illuminate\Support\Facades\Cache::add('rms_offline_cleanup_lock', true, 60)) {
            try {
                DB::table($accDetailsTbl)
                    ->where('is_currently_online', true)
                    ->where('last_online_time', '<', now()->subMinutes($timeoutMinutes))
                    ->update(['is_currently_online' => false]);
            } catch (\Throwable) {}
        }

        return $next($request);
    }

    /**
     * Determine if the incoming request is an automated background poll
     * (e.g. wire:poll without user action, chat unread count polling, etc.)
     */
    protected function isAutomatedBackgroundPoll(Request $request): bool
    {
        // 1. Chat widget unread count badge polling
        if ($request->is('chat/unread-count')) {
            return true;
        }

        // 2. Livewire requests
        if ($request->header('X-Livewire')) {
            $components = $request->input('components');
            if (is_array($components)) {
                $hasUserAction = false;

                foreach ($components as $component) {
                    // Check for property updates (e.g. typing into inputs, wire:model)
                    $updates = $component['updates'] ?? [];
                    if (! empty($updates)) {
                        $hasUserAction = true;
                        break;
                    }

                    // Check for method calls (e.g. wire:click)
                    $calls = $component['calls'] ?? [];
                    if (! empty($calls)) {
                        foreach ($calls as $call) {
                            $method = $call['method'] ?? '';
                            // Methods used purely by background polls
                            $backgroundPollMethods = ['checkRoleUpdate', 'refresh', 'ping', 'render'];
                            if (! in_array($method, $backgroundPollMethods, true)) {
                                $hasUserAction = true;
                                break 2;
                            }
                        }
                    }
                }

                // If no property updates and no user action calls were found, it's an automated poll
                if (! $hasUserAction) {
                    return true;
                }
            }
        }

        return false;
    }
}
