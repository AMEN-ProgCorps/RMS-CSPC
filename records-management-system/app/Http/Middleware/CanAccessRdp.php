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
            abort(403);
        }

        // Check if Records Disposition Program is active globally
        $isActive = \DB::table('subsystems')
            ->where('subsystem_name', 'Records Disposition Program')
            ->value('is_active');

        if (! $isActive) {
            abort(403, 'The Records Disposition Program is currently deactivated.');
        }

        return $next($request);
    }
}
