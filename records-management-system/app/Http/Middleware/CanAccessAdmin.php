<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CanAccessAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (\App\Helpers\MobileHelper::isMobile($request)) {
            return redirect()->route('portal');
        }

        $user = auth()->user();
        if (! $user) {
            return redirect()->route('login');
        }


        $perms = $user->permissions;

        if (! $perms) {
            return redirect()->route('portal');
        }

        // Super Admin bypasses all checks
        if ($perms->is_sadm) {
            return $next($request);
        }

        // Must have administrator status or at least one administrative section clearance
        $hasAdminClearance = $perms->is_admin
            || $perms->can_access_dts_admin
            || $perms->can_access_rdp_admin
            || $perms->can_access_subsystems
            || $perms->can_access_activity_logs
            || $perms->can_access_settings
            || $perms->can_access_recycle_bin
            || $perms->can_sadm_modify_accountlist
            || $perms->can_sadm_modify_account;

        if (! $hasAdminClearance) {
            return redirect()->route('portal');
        }

        return $next($request);
    }
}
