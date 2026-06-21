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
        // 1. If user is authenticated, update their activity
        if ($user = Auth::user()) {
            DB::table('account_details')
                ->where('account_id', $user->id)
                ->update([
                    'is_currently_online' => true,
                    'last_online_time' => now(),
                ]);
        }

        // 2. Mark users who haven't made a request in 5 minutes as offline
        DB::table('account_details')
            ->where('is_currently_online', true)
            ->where('last_online_time', '<', now()->subMinutes(5))
            ->update(['is_currently_online' => false]);

        return $next($request);
    }
}
