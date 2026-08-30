<?php

namespace App\Http\Middleware;

use App\Helpers\RegisterPersistHelper;
use App\Helpers\RegisterQueryHelper;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceDcsIntakeAllowlist
{
    /** @var list<string> */
    private const ALLOWED_ROUTE_NAMES = [
        'dcs',
        'dcs.dashboard',
        'dcs.office.drf.index',
        'dcs.office.drf.create',
        'dcs.office.drf.show',
        'dcs.office.drf.print',
        'dcs.office.drf.store',
        'dcs.office.drf.update',
        'dcs.office.dcn.index',
        'dcs.office.dcn.create',
        'dcs.office.dcn.show',
        'dcs.office.dcn.print',
        'dcs.office.dcn.store',
        'dcs.office.dcn.update',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! RegisterQueryHelper::isLimitedDcsUser()) {
            return $next($request);
        }

        if ($this->isAllowed($request)) {
            return $next($request);
        }

        RegisterPersistHelper::logDcsBlockedAccess($request, 'intake allowlist');

        abort(403, 'Office intake users may only access DRF/DCN forms and originator document lookup.');
    }

    private function isAllowed(Request $request): bool
    {
        $routeName = $request->route()?->getName();
        if ($routeName !== null && in_array($routeName, self::ALLOWED_ROUTE_NAMES, true)) {
            return true;
        }

        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD') && ! $request->isMethod('POST')) {
            return false;
        }

        $path = ltrim($request->path(), '/');

        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return in_array($path, [
                'dcs/api/documents/search',
                'dcs/api/documents/revisions',
                'dcs/api/offices',
            ], true);
        }

        return false;
    }
}
