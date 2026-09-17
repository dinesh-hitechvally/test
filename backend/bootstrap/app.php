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
        $middleware->statefulApi();
        $middleware->alias([
            'cron.secret' => \App\Http\Middleware\VerifyCronSecret::class,
        ]);

        // This is an API-only backend — no 'login' route exists (the SPA
        // owns that). Left at the framework default, every unauthenticated
        // request to an auth:sanctum route throws RouteNotFoundException
        // while building the redirect (route('login') doesn't exist),
        // which happens *during* AuthenticationException's own
        // construction — an exception thrown while building another
        // exception, which PHP's built-in dev server doesn't recover from
        // cleanly (the connection just hangs instead of a clean 401 JSON
        // response). No redirect target needed anyway since every API
        // request already renders JSON via shouldRenderJsonWhen() below.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
