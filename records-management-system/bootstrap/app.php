<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

// Ensure .env variables take precedence over static Docker container environment variables
if (file_exists(dirname(__DIR__) . '/.env')) {
    \Dotenv\Dotenv::createMutable(dirname(__DIR__))->safeLoad();
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->validateCsrfTokens(except: [
            'api/session/*',
            'api/cluster/*',
            'rdp/api/*',
            'rdp/intake/*',
        ]);
        $middleware->web(append: [
            \App\Http\Middleware\UpdateUserOnlineStatus::class,
        ]);
        $middleware->alias([
            'can.access.admin' => \App\Http\Middleware\CanAccessAdmin::class,
            'can.access.dts'   => \App\Http\Middleware\CanAccessDts::class,
            'can.access.rdp'   => \App\Http\Middleware\CanAccessRdp::class,
            'can.access.dcs'   => \App\Http\Middleware\CanAccessDcs::class,
            'dcs.intake.allowlist' => \App\Http\Middleware\EnforceDcsIntakeAllowlist::class,
            'dcs.full'         => \App\Http\Middleware\RequireFullDcs::class,
            'dcs.module'       => \App\Http\Middleware\RequireDcsModule::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            if ($request->ajax() || $request->wantsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest' || $request->is('rdp/api/*') || \App\Support\ErrorDiagnosis::wantsJson($request)) {
                $diagnosed = \App\Support\ErrorDiagnosis::forStatus(401);

                return response()->json(array_merge($diagnosed->toArray(), [
                    'error' => 'Unauthenticated',
                    'redirect' => route('login'),
                ]), 401);
            }

            if ($request->is('chat/unread-count')) {
                return response()->json([
                    'error' => 'Unauthenticated',
                    'unread' => 0,
                    'chat_unread' => 0,
                    'system_unread' => 0,
                    'total_unread' => 0,
                ], 401);
            }

            if ($request->is('open-chat', 'chatify*')) {
                return response('<!DOCTYPE html><html><head><script>if(window.top){window.top.location.href="' . route('login') . '";}</script></head><body></body></html>', 401)
                    ->header('Content-Type', 'text/html');
            }

            return null;
        });

        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if (app()->runningInConsole() || app()->environment('testing') || app()->runningUnitTests()) {
                return null;
            }
            if ($e instanceof \Illuminate\Validation\ValidationException
                || $e instanceof \Illuminate\Auth\AuthenticationException) {
                return null;
            }

            $diagnosed = \App\Support\ErrorDiagnosis::from($e);

            if (\App\Support\ErrorDiagnosis::isFileResponse($request) && ! \App\Support\ErrorDiagnosis::wantsJson($request)) {
                return response($diagnosed->message, $diagnosed->status)
                    ->header('Content-Type', 'text/plain; charset=UTF-8')
                    ->header('X-Content-Type-Options', 'nosniff');
            }

            return $diagnosed->toResponse($request);
        });
    })->create();
