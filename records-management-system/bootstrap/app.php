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
        //
    })->create();
