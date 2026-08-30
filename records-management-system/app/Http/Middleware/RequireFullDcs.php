<?php

namespace App\Http\Middleware;

use App\Helpers\RegisterPersistHelper;
use App\Helpers\RegisterQueryHelper;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireFullDcs
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! RegisterQueryHelper::isLimitedDcsUser()) {
            return $next($request);
        }

        RegisterPersistHelper::logDcsBlockedAccess($request, 'full DCS required');

        if ($this->expectsJsonResponse($request)) {
            abort(403, 'Full Document Control System access is required.');
        }

        return redirect()
            ->route('dcs.office.drf.index')
            ->with('error', 'Full Document Control System access is required for that page.');
    }

    private function expectsJsonResponse(Request $request): bool
    {
        if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
            return true;
        }

        if ($request->header('X-Requested-With') === 'XMLHttpRequest') {
            return true;
        }

        $path = ltrim($request->path(), '/');

        return str_starts_with($path, 'dcs/api/');
    }
}
