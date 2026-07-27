<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CanAccessDts
{
    public function handle(Request $request, Closure $next): Response
    {
        if (\App\Helpers\MobileHelper::isMobile($request) && ! $request->is('dts/scanner')) {
            return redirect()->route('portal');
        }

        $perms = auth()->user()?->permissions;


        if (! $perms || (! $perms->is_sadm && ! $perms->can_access_dts)) {
            return redirect()->route('portal');
        }

        // Check if Document Tracking System is active globally
        $isActive = \DB::table('subsystems')
            ->where('subsystem_name', 'Document Tracking System')
            ->value('is_active');

        if (! $isActive && ! $perms->is_sadm) {
            return redirect()->route('portal');
        }

        return $next($request);
    }
}
