<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CanAccessDts
{
    public function handle(Request $request, Closure $next): Response
    {
        $perms = auth()->user()?->permissions;

        if (! $perms || (! $perms->is_sadm && ! $perms->can_access_dts)) {
            abort(403);
        }

        // Check if Document Tracking System is active globally
        $isActive = \DB::table('subsystems')
            ->where('subsystem_name', 'Document Tracking System')
            ->value('is_active');

        if (! $isActive) {
            abort(403, 'The Document Tracking System is currently deactivated.');
        }

        return $next($request);
    }
}
