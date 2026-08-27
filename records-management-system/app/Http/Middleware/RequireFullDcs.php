<?php

namespace App\Http\Middleware;

use App\Helpers\RegisterQueryHelper;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireFullDcs
{
    public function handle(Request $request, Closure $next): Response
    {
        if (RegisterQueryHelper::isLimitedDcsUser()) {
            return redirect()
                ->route('dcs.office.drf.index')
                ->with('error', 'Full Document Control System access is required for that page.');
        }

        return $next($request);
    }
}
