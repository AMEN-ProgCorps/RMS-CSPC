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
        // 1. Determine configured session inactivity / tab-close timeout (Default: 15 minutes)
        $timeoutMinutes = 15;
        try {
            $settingVal = DB::table('system_settings')->where('key', 'tab_close_idle_timeout_minutes')->value('value');
            if ($settingVal !== null && is_numeric($settingVal) && (int) $settingVal > 0) {
                $timeoutMinutes = (int) $settingVal;
            }
        } catch (\Throwable) {}

        if ($user = Auth::user()) {
            $details = DB::table('account_details')->where('account_id', $user->id)->first();
            $now = now();

            // A. Check if Admin modified account (Forced Logout)
            if ($details && $details->force_logout_at !== null) {
                DB::table('account_details')
                    ->where('account_id', $user->id)
                    ->update([
                        'force_logout_at' => null,
                        'is_currently_online' => false,
                    ]);

                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->with('error', 'Your account details were updated by an Administrator. Please sign in again.');
            }

            // B. Check if inactive for longer than configured timeout
            if ($details && $details->last_online_time !== null) {
                $lastOnline = \Carbon\Carbon::parse($details->last_online_time);
                if ($lastOnline->diffInMinutes($now) >= $timeoutMinutes) {
                    DB::table('account_details')
                        ->where('account_id', $user->id)
                        ->update(['is_currently_online' => false]);

                    Auth::logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();

                    return redirect()->route('login')->with('error', "Session expired due to {$timeoutMinutes} minutes of inactivity or tab closure.");
                }
            }

            // C. Update current user activity
            DB::table('account_details')
                ->where('account_id', $user->id)
                ->update([
                    'is_currently_online' => true,
                    'last_online_time' => $now,
                ]);
        }

        // 2. Mark users who haven't made a request in $timeoutMinutes as offline
        DB::table('account_details')
            ->where('is_currently_online', true)
            ->where('last_online_time', '<', now()->subMinutes($timeoutMinutes))
            ->update(['is_currently_online' => false]);

        return $next($request);
    }
}
