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

        $canOfficeIntake = RegisterQueryHelper::canAccessOfficeIntake();
        $isOfficeRoute = $this->isOfficeIntakeRequest($request);

        if ($isOfficeRoute && ! $canOfficeIntake) {
            return $this->denyLimited($request, 'Office Intake is not enabled for this role.', 'dcs');
        }

        if ($this->isAllowed($request)) {
            return $this->toResponse($next($request));
        }

        $fallback = $canOfficeIntake ? 'dcs.office.drf.index' : 'dcs';
        $message = $canOfficeIntake
            ? 'Your role can only use office DRF/DCN intake.'
            : 'Your role does not have Office Intake or DCS Admin.';

        return $this->denyLimited($request, $message, $fallback);
    }

    private function denyLimited(Request $request, string $message, string $route): Response
    {
        RegisterPersistHelper::logDcsBlockedAccess($request, 'intake allowlist');

        if ($this->expectsJsonResponse($request) || $this->isEmbeddedDocumentRequest($request)) {
            abort(403, $message);
        }

        return redirect()->route($route)->with('error', $message);
    }

    private function isOfficeIntakeRequest(Request $request): bool
    {
        $routeName = $request->route()?->getName();
        if ($routeName !== null && str_starts_with($routeName, 'dcs.office.')) {
            return true;
        }

        $path = ltrim($request->path(), '/');

        return str_starts_with($path, 'dcs/office/')
            || str_starts_with($path, 'dcs/api/office/');
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

    private function isEmbeddedDocumentRequest(Request $request): bool
    {
        $path = ltrim($request->path(), '/');

        return $request->is('dcs/view-document', 'dcs/view-document/*')
            || str_starts_with($path, 'dcs/api/signed-scan-url');
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
