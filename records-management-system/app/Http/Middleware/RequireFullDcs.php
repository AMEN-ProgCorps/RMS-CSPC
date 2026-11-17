<?php

/**
 * Full-DCS gate: blocks limited (office-intake) accounts from Admin DCS screens.
 *
 * Limited users are redirected to office DRF index (or 403 JSON for APIs).
 * Pair with EnforceDcsIntakeAllowlist for the routes they *are* allowed to use.
 *
 * DEBUG: office user reaches Register/Update URL → this middleware + isLimitedDcsUser().
 */

namespace App\Http\Middleware;

use App\Helpers\RegisterPersistHelper;
use App\Helpers\RegisterQueryHelper;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireFullDcs
{
    /**
     * Blocks office-only users from Admin DCS pages; full DCS users continue.
     *
     * DEBUG: office account opens Register/Update/Settings → lands here.
     */
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

    /**
     * Tells whether the client wants a JSON error instead of an HTML redirect.
     *
     * DEBUG: API got an HTML redirect → expectsJsonResponse was false for that request.
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
