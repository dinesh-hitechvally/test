<?php

use App\Http\Controllers\CronController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

// URL-triggered pipeline — replaces the Laravel scheduler/server cron (see
// CronController's docblock). Every one of these requires ?key=<CRON_SECRET>
// (VerifyCronSecret); with no secret set in .env, all of them 403.
//
// Split into two groups by what the route actually does, not just naming:
//   - cron/scrape/*   hits an external source (nepalstock.com/ShareSansar)
//     and writes what it gets back — nothing here reads anything this app
//     already has.
//   - cron/reports/*  never makes an external call — it only recomputes
//     indicators/signals from data already sitting in this app's own
//     database (ML predictions are computed on-demand per request, not
//     part of this pipeline).
//
// Timing below is given two ways:
//   - NPT  = Asia/Kathmandu (UTC+5:45), what actually matters (NEPSE's own
//     trading hours) — use this if your external pinger lets you set a
//     per-job timezone (cron-job.org does).
//   - UTC  = the same instant, as a plain 5-field cron expression, for
//     pingers that only run in UTC. NPT is 5h45m *ahead* of UTC, so this is
//     NOT just "subtract 6 hours" — the odd :45 offset shifts the minute
//     too, and the Monday-03:xx NPT jobs land on *Sunday* in UTC.
// The exact same list, already converted, is also served live (with the
// secret filled in) at GET /api/schedule — see ScheduleController.
Route::middleware('cron.secret')->prefix('cron')->group(function () {

    Route::prefix('scrape')->group(function () {

        // Ahead of market-sync-stock — creates a row for EVERY listed
        // security, not just ones that trade today (market-sync-stock only
        // ever discovers a stock as a byproduct of seeing it trade). Catches
        // illiquid/suspended/brand-new listings that would otherwise never
        // get created at all.
        // 06:00 NPT, daily  |  UTC: 15 0 * * *  (00:15 UTC, daily)
        Route::get('/sync-stock-list', [CronController::class, 'syncStockList']);

        // Fetches full history directly for stocks that don't have any yet
        // (stock_scrape_statuses.history_fetched_at IS NULL), a few at a
        // time (?limit=, default 5) so one ping can't run long enough to hit
        // a web server timeout. Safe to ping repeatedly (e.g. every 15 min)
        // until "0 stock(s) still missing history" — a stock whose last
        // attempt failed is skipped automatically instead of being retried
        // forever; see fetch-history/{symbol} to retry one by hand.
        Route::get('/fetch-histories', [CronController::class, 'fetchHistories']);

        // On-demand, one stock at a time — no fixed timing. e.g.
        // /cron/scrape/fetch-history/NABIL?key=... Same ShareSansar
        // full-history fetch as the "Fetch Full History" button, just
        // reachable by plain URL.
        Route::get('/fetch-history/{symbol}', [CronController::class, 'fetchHistory']);

        // Same batched, timeout-proof shape as fetch-histories (?limit=,
        // default 5) — fills in stocks.sector for whichever stocks are
        // still missing one. Safe to ping repeatedly until "0 stock(s)
        // still missing a sector".
        Route::get('/sync-sectors', [CronController::class, 'syncSectors']);

        // Same batched, timeout-proof shape as fetch-histories (?limit=,
        // default 5) — fetches dividend/bonus data (nepalstock.com's only
        // source) for stocks that haven't had a successful fetch yet
        // (stock_scrape_statuses.dividend_fetched_at IS NULL — genuinely
        // having zero dividends counts as fetched, not pending). Safe to
        // ping repeatedly until "0 stock(s) still missing dividend data".
        Route::get('/sync-dividends', [CronController::class, 'syncDividends']);

        // Same batched shape (?limit=, default 5) — the only place that ever
        // calls Groq for the "AI Opinion" lens (Stock Detail / Analyst
        // Report pages just read whatever's stored, no external call on a
        // page load). Targets stocks with a signal whose stored opinion is
        // missing or a day+ old; skipped no-op with a clear message if
        // GROQ_API_KEY isn't set. Free tier, but has real per-minute rate
        // limits — keep ?limit= modest if pinging often.
        Route::get('/ai-opinions', [CronController::class, 'generateAiOpinions']);

        // EPS/P/E/Book Value from merolagani.com — batched, missing or 7+
        // days stale, ?limit= (default 20) per ping. Slow-moving data, no
        // strict schedule needed; safe to ping as often as convenient.
        Route::get('/fundamentals', [CronController::class, 'syncFundamentals']);

        // Daily prices and the index snapshot — ~30min after NEPSE's ~15:00
        // NPT close, 2 minutes apart so one finishes before the next fires.

        // 15:30 NPT, Mon-Fri  |  UTC: 45 9 * * 1-5  (09:45 UTC, Mon-Fri)
        Route::get('/market-sync-stock', [CronController::class, 'marketSyncStock']);

        // 15:32 NPT, Mon-Fri  |  UTC: 47 9 * * 1-5  (09:47 UTC, Mon-Fri)
        Route::get('/market-sync-index', [CronController::class, 'marketSyncIndex']);

        // On-demand diagnostic, no schedule — mints a nepalstock.com token
        // and proves it against the real API. First thing to check when
        // every nepalstock.com fetch starts failing at once.
        Route::get('/verify-token', [CronController::class, 'verifyNepseToken']);

    });

    Route::prefix('reports')->group(function () {

        // On-demand, no schedule — recalculation already follows every
        // price update automatically (StockPricesUpdated event). Re-runs
        // stocks priced today, or every stock with ?all=1 (after changing
        // indicator/signal rules).
        Route::get('/market-recalculate', [CronController::class, 'marketRecalculate']);

        // Weekly, ~3am NPT (quiet hours, no one's looking at the dashboard)
        // — ML retrain, then its accuracy backtest, both reading only
        // stored history, no external calls.

        // 03:30 NPT, Monday  |  UTC: 45 21 * * 0  (21:45 UTC, *Sunday*)
        Route::get('/train-ml', [CronController::class, 'trainMl']);

        // 04:00 NPT, Monday  |  UTC: 15 22 * * 0  (22:15 UTC, *Sunday*)
        Route::get('/backtest-signals', [CronController::class, 'backtestSignals']);

        // 04:15 NPT, Monday  |  UTC: 30 22 * * 0  (22:30 UTC, *Sunday*)
        Route::get('/backtest-next-close', [CronController::class, 'backtestNextClose']);

    });
    
});


/*
|--------------------------------------------------------------------------
| Clear All Cache
|--------------------------------------------------------------------------
*/
Route::get('/clear-cache', function () {
    $results = [];

    try {
        $results['config_clear'] = Artisan::call('config:clear');
        $results['route_clear'] = Artisan::call('route:clear');
        $results['cache_clear'] = Artisan::call('cache:clear');
        $results['view_clear'] = Artisan::call('view:clear');
        $results['optimize_clear'] = Artisan::call('optimize:clear');
    } catch (\Exception $e) {
        $results['artisan_error'] = $e->getMessage();
    }

    $cacheFiles = [
        base_path('bootstrap/cache/config.php'),
        base_path('bootstrap/cache/routes-v7.php'),
        base_path('bootstrap/cache/services.php'),
        base_path('bootstrap/cache/packages.php'),
        base_path('bootstrap/cache/events.php'),
    ];

    $unlinked = [];
    foreach ($cacheFiles as $file) {
        if (file_exists($file)) {
            if (@unlink($file)) {
                $unlinked[] = basename($file) . ' (deleted)';
            } else {
                $unlinked[] = basename($file) . ' (failed to delete)';
            }
        }
    }
    $results['manually_deleted'] = $unlinked;

    return response()->json([
        'success' => true,
        'message' => 'All caches cleared successfully',
        'details' => $results
    ]);
});

Route::get('/check-version', function () {
    $apiPath = base_path('routes/api.php');
    $apiContent = file_exists($apiPath) ? file_get_contents($apiPath) : 'File not found';
    
    $hasReturns = strpos($apiContent, 'returns') !== false;

    $gitLog = 'git command not run';
    try {
        $gitLog = shell_exec('git log -n 1 --oneline 2>&1');
    } catch (\Exception $e) {
        $gitLog = $e->getMessage();
    }

    return response()->json([
        'has_returns_in_api_php' => $hasReturns,
        'git_log' => trim($gitLog),
        'sales_group_snippet' => strpos($apiContent, 'sales') !== false ? substr($apiContent, strpos($apiContent, 'sales'), 500) : 'sales not found',
    ]);
});



/*
|--------------------------------------------------------------------------
| Wipe Database + Fresh Migration + Seed
|--------------------------------------------------------------------------
*/
Route::get('/reset-database', function () {

    Artisan::call('db:wipe', [
        '--force' => true
    ]);

    Artisan::call('migrate', [
        '--force' => true
    ]);

    Artisan::call('db:seed', [
        '--force' => true
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Database wiped, migrated, and seeded successfully'
    ]);
});