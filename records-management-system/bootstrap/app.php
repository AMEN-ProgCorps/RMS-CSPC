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
