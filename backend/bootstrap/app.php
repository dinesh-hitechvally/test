<?php

use App\Http\Middleware\VerifyCronSecret;
use App\Services\Mail\EmailLogService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Listeners are registered explicitly in AppServiceProvider::LISTENERS —
    // discovery off so they aren't registered twice, and so a stale event
    // cache can't hide them.
    ->withEvents(discover: false)
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->alias([
            'cron.secret' => VerifyCronSecret::class,
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

        // Laravel has no "email failed" event — a failed send is just a
        // transport exception. Record it against the email log; the normal
        // error logging still happens too.
        $exceptions->report(function (TransportExceptionInterface $e) {
            app(EmailLogService::class)->markPendingFailed($e);
        });
    })->create();
