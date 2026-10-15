<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The unlock cookie is set by API routes, which don't encrypt cookies
        $middleware->encryptCookies(except: [App\Support\AppLock::COOKIE]);

        // Check the password before route model binding, so a locked app reveals nothing
        $middleware->prependToPriorityList(
            before: Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: App\Http\Middleware\RequireAppPassword::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
