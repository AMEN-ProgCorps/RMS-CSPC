<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->web(append: [
            \App\Http\Middleware\UpdateUserOnlineStatus::class,
        ]);
        $middleware->alias([
            'can.access.admin' => \App\Http\Middleware\CanAccessAdmin::class,
            'can.access.dts'   => \App\Http\Middleware\CanAccessDts::class,
            'can.access.rdp'   => \App\Http\Middleware\CanAccessRdp::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            if ($request->ajax() || $request->wantsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json([
                    'error' => 'Unauthenticated',
                    'redirect' => route('login'),
                ], 401);
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
            if ($request->is('api/*')) {
                return null;
            }

            if ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException ||
                $e instanceof \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException ||
                $e instanceof \Illuminate\Auth\Access\AuthorizationException ||
                ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException && in_array($e->getStatusCode(), [403, 404]))) {
                return redirect()->route('portal');
            }

            return null;
        });
    })->create();
