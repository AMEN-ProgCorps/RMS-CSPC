<?php

/**
 * Per-module DCS gate (route middleware parameter = module key, e.g. register, update).
 *
 * Uses RegisterQueryHelper::canAccessDcsModule($module). Failures are logged with
 * RegisterPersistHelper::logDcsBlockedAccess() then 403 / redirect to dcs dashboard.
 *
 * DEBUG: user reaches DCS home but not a subpage → check role module flags + this middleware param.
 */

namespace App\Http\Middleware;

use App\Helpers\RegisterPersistHelper;
use App\Helpers\RegisterQueryHelper;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireDcsModule
{
    /**
     * Allows the request only when the user’s role has the required DCS module permission (e.g. register, review).
     */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        if (RegisterQueryHelper::canAccessDcsModule($module)) {
            return $next($request);
        }

        RegisterPersistHelper::logDcsBlockedAccess($request, 'DCS module required: ' . $module);

        if ($this->expectsJsonResponse($request)) {
            abort(403, 'You do not have clearance for this Document Control System module.');
        }

        return redirect()
            ->route('dcs')
            ->with('error', 'You do not have clearance for that Document Control System page.');
    }

    /**
     * Tells whether the client wants a JSON error instead of an HTML redirect.
     *
     * DEBUG: browser got a redirect JSON error page → this returned false; API got HTML → true path wrong.
     */
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
