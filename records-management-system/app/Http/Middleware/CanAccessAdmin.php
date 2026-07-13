<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CanAccessAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $perms = auth()->user()?->permissions;

        if (! $perms || (! $perms->is_sadm && ! $perms->is_admin)) {
            abort(403);
        }

        return $next($request);
    }
}
