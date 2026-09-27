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

        // The per-stock jobs below each work through EVERY pending stock in
        // one run (no batches, no ?limit=). Each stock is saved as it
        // finishes, so if the host cuts a long request short, just ping
        // again — it resumes with whatever is still pending. First runs can
        // take a long time; later runs only see the few new/stale stocks.

        // Full history for every stock that doesn't have any yet
        // (stock_scrape_statuses.history_fetched_at IS NULL). A stock whose
        // last attempt failed is skipped instead of retried forever; see
        // fetch-history/{symbol} to retry one by hand.
        Route::get('/fetch-histories', [CronController::class, 'fetchHistories']);

        // On-demand, one stock — e.g. /cron/scrape/fetch-history/NABIL?key=...
        // Same ShareSansar full-history fetch as the "Fetch Full History"
        // button, just reachable by plain URL.
        Route::get('/fetch-history/{symbol}', [CronController::class, 'fetchHistory']);

        // Sector for every stock still missing one.
        Route::get('/sync-sectors', [CronController::class, 'syncSectors']);

        // Dividend/bonus data (nepalstock.com is the only source) for every
        // stock without a successful fetch yet — genuinely having zero
        // dividends counts as fetched, not pending.
        Route::get('/sync-dividends', [CronController::class, 'syncDividends']);

        // The only place that ever calls Groq for the "AI Opinion" lens
        // (pages just read what's stored). Every stock with a signal whose
        // opinion is missing or a day+ old. Groq's free tier fits ~2 stocks
        // a minute, so a run waits out rate limits and takes roughly
        // (pending stocks ÷ 2) minutes. No-op if GROQ_API_KEY isn't set.
        Route::get('/ai-opinions', [CronController::class, 'generateAiOpinions']);

        // EPS/P/E/Book Value from merolagani.com for every stock missing it
        // or 7+ days stale. Slow-moving data — daily or weekly is plenty.
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
