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
    public function handle(Request $request, Closure $next): Response
    {
        $sysSettingsTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings';
        $accDetailsTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';

        // 1. Determine configured session inactivity / tab-close timeout (Default: 15 minutes)
        $timeoutMinutes = 15;
        try {
            $settingVal = DB::table($sysSettingsTbl)->where('key', 'tab_close_idle_timeout_minutes')->value('value');
            if ($settingVal !== null && is_numeric($settingVal) && (int) $settingVal > 0) {
                $timeoutMinutes = (int) $settingVal;
            }
        } catch (\Throwable) {}

        if ($user = Auth::user()) {
            $details = DB::table($accDetailsTbl)->where('account_id', $user->id)->first();
            $now = now();

            // Helper to build appropriate logout response depending on request type (AJAX vs Chatify iframe vs Main page)
            $buildLogoutResponse = function (string $reasonMessage) use ($request) {
                if ($request->ajax() || $request->wantsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                    return response()->json([
                        'error' => 'Unauthenticated',
                        'message' => $reasonMessage,
                        'redirect' => route('login'),
                    ], 401);
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
            if ($details && $details->force_logout_at !== null) {
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
            if ($details && $details->last_online_time !== null) {
                $lastOnline = \Carbon\Carbon::parse($details->last_online_time);
                if ($lastOnline->diffInMinutes($now) >= $timeoutMinutes) {
                    DB::table($accDetailsTbl)
                        ->where('account_id', $user->id)
                        ->update(['is_currently_online' => false]);

                    Auth::logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();

                    return $buildLogoutResponse("Session expired due to {$timeoutMinutes} minutes of inactivity or tab closure.");
                }
            }

            // C. Update current user activity
            DB::table($accDetailsTbl)
                ->where('account_id', $user->id)
                ->update([
                    'is_currently_online' => true,
                    'last_online_time' => $now,
                ]);
        }

        // 2. Mark users who haven't made a request in $timeoutMinutes as offline
        try {
            DB::table($accDetailsTbl)
                ->where('is_currently_online', true)
                ->where('last_online_time', '<', now()->subMinutes($timeoutMinutes))
                ->update(['is_currently_online' => false]);
        } catch (\Throwable) {}

        return $next($request);
    }
}
