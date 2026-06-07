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

        return $next($request);
    }
}
