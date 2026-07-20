<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CanAccessRdp
{
    public function handle(Request $request, Closure $next): Response
    {
        $perms = auth()->user()?->permissions;

        if (! $perms || (! $perms->is_sadm && ! $perms->can_access_rdp)) {
            return redirect()->route('portal');
        }

        // Check if Records Disposition Program is active globally
        $isActive = \DB::table('subsystems')
            ->where('subsystem_name', 'Records Disposition Program')
            ->value('is_active');

        if (! $isActive && ! $perms->is_sadm) {
            return redirect()->route('portal');
        }

        return $next($request);
    }
}
