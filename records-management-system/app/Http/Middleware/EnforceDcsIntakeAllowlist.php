<?php

/**
 * Limited (office) DCS users: allowlist of routes they may open.
 *
 * Full DCS / Admin DCS users skip this. Office Intake users are restricted to
 * ALLOWED_ROUTE_NAMES (+ named office APIs). New office endpoints MUST be listed
 * here or limited users get 403.
 *
 * DEBUG: office user 403 on a new API → add the route name to ALLOWED_ROUTE_NAMES.
 *        Office Intake disabled for role → canAccessOfficeIntake() fails before allowlist.
 */

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
    /**
     * Named routes a limited (office) DCS user may hit.
     * Add every new office page/API here or office users get 403.
     *
     * @var list<string>
     */
    private const ALLOWED_ROUTE_NAMES = [
        'dcs',
        'dcs.dashboard',
        'dcs.office.incoming',
        'dcs.office.documents',
        'dcs.office.drf.index',
        'dcs.office.drf.create',
        'dcs.office.drf.show',
        'dcs.office.drf.edit',
        'dcs.office.drf.print',
        'dcs.office.drf.preview',
        'dcs.office.drf.store',
        'dcs.office.drf.update',
        'dcs.office.dcn.index',
        'dcs.office.dcn.create',
        'dcs.office.dcn.show',
        'dcs.office.dcn.edit',
        'dcs.office.dcn.print',
        'dcs.office.dcn.preview',
        'dcs.office.dcn.store',
        'dcs.office.dcn.update',
        'dcs.office.random-checks',
        'dcs.office.random-checks.show',
        'dcs.api.office.revisable-documents',
        'dcs.api.office-intake.print-ready',
        'dcs.api.office-intake.printed',
        'dcs.api.office-intake.attention',
    ];

    /**
     * For office-only users, allows only the Office Intake allowlisted routes; full DCS users pass through.
     *
     * DEBUG: office 403 on a brand-new endpoint → missing from ALLOWED_ROUTE_NAMES / isAllowed().
     */
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

    /**
     * Blocks a limited office user, logs the deny, and redirects or aborts.
     *
     * DEBUG: blocked hits in admin_logs with "intake allowlist" → this method.
     */
    private function denyLimited(Request $request, string $message, string $route): Response
    {
        RegisterPersistHelper::logDcsBlockedAccess($request, 'intake allowlist');

        if ($this->expectsJsonResponse($request) || $this->isEmbeddedDocumentRequest($request)) {
            abort(403, $message);
        }

        return redirect()->route($route)->with('error', $message);
    }

    /**
     * Checks whether the current request is an office-intake page or API.
     *
     * DEBUG: office path treated as Admin DCS → path/name prefix checks here.
     */
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

    /**
     * Tells whether the client wants a JSON error instead of an HTML redirect.
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

    /**
     * Checks whether the request is a PDF iframe / signed scan URL (must abort, not redirect).
     *
     * DEBUG: blank PDF frame for office user → should hit abort via this check.
     */
    private function isEmbeddedDocumentRequest(Request $request): bool
    {
        $path = ltrim($request->path(), '/');

        return $request->is('dcs/view-document', 'dcs/view-document/*')
            || str_starts_with($path, 'dcs/api/signed-scan-url');
    }

    /**
     * Converts a Livewire redirector into a normal HTTP response.
     *
     * DEBUG: "Unexpected middleware response type" 500 → $next returned something unexpected.
     */
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

    /**
     * Checks whether a limited office user is allowed to open this route.
     *
     * DEBUG: new GET API 403 for office → add path under the GET allow list below,
     */
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
