<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Livewire\Features\SupportRedirects\Redirector as LivewireRedirector;
use Symfony\Component\HttpFoundation\Response;

class CanAccessDcs
{
    public function handle(Request $request, Closure $next): Response
    {
        if (\App\Helpers\MobileHelper::isMobile($request)) {
            return $this->deny($request, 'mobile_blocked', 'Document Control System is not available on mobile. Use a desktop browser.');
        }

        $user = auth()->user();
        $perms = $user?->permissions;

        if (! $user) {
            return $this->deny($request, 'unauthenticated', 'You must be signed in to use Document Control System.');
        }

        if (! $perms) {
            return $this->deny($request, 'missing_permissions', 'Your account role permissions could not be loaded.');
        }

        if (! $perms->is_sadm && ! $perms->can_access_dcs) {
            return $this->deny($request, 'no_dcs_access', 'Your role does not have Access DCS.');
        }

        $subsystemsTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems';
        $isActive = \DB::table($subsystemsTbl)
            ->where('subsystem_name', 'Document Control System')
            ->value('is_active');

        if (! $isActive && ! $perms->is_sadm) {
            return $this->deny($request, 'dcs_inactive', 'Document Control System is currently disabled.');
        }

        \App\Helpers\RegisterPersistHelper::logDcsAccess($request);

        return $this->toResponse($next($request));
    }

    private function deny(Request $request, string $reason, string $message): Response
    {
        if ($this->expectsJsonResponse($request)) {
            return response()->json([
                'ok' => false,
                'reason' => $reason,
                'message' => $message,
            ], $reason === 'unauthenticated' ? 401 : 403);
        }

        // Iframe PDF preview — never bounce to the portal (looks like a blank/wrong page).
        if ($request->is('dcs/view-document', 'dts/view-document')
            || str_starts_with(ltrim($request->path(), '/'), 'dcs/api/signed-scan-url')) {
            abort($reason === 'unauthenticated' ? 401 : 403, $message);
        }

        return new RedirectResponse(route('portal', absolute: false));
    }

    private function toResponse(mixed $response): Response
    {
        if ($response instanceof Response) {
            return $response;
        }

        if ($response instanceof LivewireRedirector) {
            return new RedirectResponse(route('dcs', absolute: false));
        }

        abort(500, 'Unexpected middleware response type.');
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
