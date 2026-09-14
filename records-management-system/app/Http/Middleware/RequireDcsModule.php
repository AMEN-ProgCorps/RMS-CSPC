<?php

namespace App\Http\Middleware;

use App\Helpers\RegisterPersistHelper;
use App\Helpers\RegisterQueryHelper;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireDcsModule
{
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
