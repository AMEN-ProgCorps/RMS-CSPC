<?php

namespace App\Http\Middleware;

use App\Helpers\RegisterPersistHelper;
use App\Helpers\RegisterQueryHelper;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Livewire\Features\SupportRedirects\Redirector as LivewireRedirector;
use Symfony\Component\HttpFoundation\Response;

class EnforceDcsIntakeAllowlist
{
    /** @var list<string> */
    private const ALLOWED_ROUTE_NAMES = [
        'dcs',
        'dcs.dashboard',
        'dcs.office.documents',
        'dcs.office.drf.index',
        'dcs.office.drf.create',
        'dcs.office.drf.show',
        'dcs.office.drf.edit',
        'dcs.office.drf.print',
        'dcs.office.drf.store',
        'dcs.office.drf.update',
        'dcs.office.dcn.index',
        'dcs.office.dcn.create',
        'dcs.office.dcn.show',
        'dcs.office.dcn.edit',
        'dcs.office.dcn.print',
        'dcs.office.dcn.store',
        'dcs.office.dcn.update',
        'dcs.api.office.revisable-documents',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! RegisterQueryHelper::isLimitedDcsUser()) {
            return $this->toResponse($next($request));
        }

        if ($this->isAllowed($request)) {
            return $this->toResponse($next($request));
        }

        RegisterPersistHelper::logDcsBlockedAccess($request, 'intake allowlist');

        abort(403, 'Office intake users may only access DRF/DCN forms, office documents, and originator document lookup.');
    }

    private function toResponse(mixed $response): Response
    {
        if ($response instanceof Response) {
            return $response;
        }

        // Livewire rebinds `redirect()` during full-page mounts; never leak Redirector.
        if ($response instanceof LivewireRedirector) {
            return new RedirectResponse(route('dcs', absolute: false));
        }

        abort(500, 'Unexpected middleware response type.');
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
                'dcs/api/office/revisable-documents',
                'dcs/api/offices',
            ], true);
        }

        return false;
    }
}
