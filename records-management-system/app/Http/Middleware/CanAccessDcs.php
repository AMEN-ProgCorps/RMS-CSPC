<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CanAccessDcs
{
    public function handle(Request $request, Closure $next): Response
    {
        if (\App\Helpers\MobileHelper::isMobile($request)) {
            return redirect()->route('portal');
        }

        $perms = auth()->user()?->permissions;

        if (! $perms || (! $perms->is_sadm && ! $perms->can_access_dcs)) {
            return redirect()->route('portal');
        }

        $subsystemsTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems';
        $isActive = \DB::table($subsystemsTbl)
            ->where('subsystem_name', 'Document Control System')
            ->value('is_active');

        if (! $isActive && ! $perms->is_sadm) {
            return redirect()->route('portal');
        }

        \App\Helpers\RegisterPersistHelper::logDcsAccess($request);

        return $next($request);
    }
}
