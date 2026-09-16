<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the URL-triggered cron routes (routes/web.php) — these replace the
 * Laravel scheduler, so they run in response to a plain unauthenticated GET
 * from an external pinger (cron-job.org, UptimeRobot, etc.), not a logged-in
 * user. Without this, anyone who finds the URL could trigger a scrape/
 * recalculation/retrain on demand. Compares against CRON_SECRET in .env —
 * if that's unset, every request is refused rather than silently open.
 *
 * Skipped entirely on APP_ENV=local — no external pinger reaches a local
 * dev box anyway, so there's nothing to protect against there, and it's one
 * less thing to keep in sync while testing these routes by hand. Every
 * other environment (production, staging, whatever) still enforces it.
 */
class VerifyCronSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('local')) {
            return $next($request);
        }

        $expected = config('services.cron.secret');

        if (! $expected || ! hash_equals($expected, (string) $request->query('key'))) {
            abort(403, 'Missing or invalid cron key.');
        }

        return $next($request);
    }
}
