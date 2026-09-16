<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use Illuminate\Support\Facades\Artisan;

/**
 * The pipeline moved from the Laravel scheduler/server cron to URL-triggered
 * routes (routes/web.php's `cron/*` group, CronController) — for hosting
 * without real cron/SSH access, an external pinger (cron-job.org,
 * UptimeRobot, etc.) just needs a URL to hit on a timer instead.
 *
 * This lists those URLs, with the CRON_SECRET already filled in, ready to
 * paste into whatever's doing the pinging. Safe to include the real secret
 * here specifically because this endpoint itself sits behind auth:sanctum
 * (routes/api.php) — only a logged-in user of this app ever sees it.
 */
class ScheduleController extends Controller
{
    /**
     * Timing mirrors routes/web.php's comments exactly (see that file for
     * the full explanation of the NPT/UTC split) — cron_npt is what to use
     * if the external pinger supports a per-job timezone (cron-job.org
     * does); cron_utc is the same instant as a plain 5-field expression for
     * pingers that only run in UTC. NPT is UTC+5:45, not a whole number of
     * hours, so the UTC expressions shift the minute too, and the weekly
     * Monday-NPT jobs land on Sunday in UTC.
     */
    private const JOBS = [
        // group: 'scrape' — hits nepalstock.com/ShareSansar, writes what comes back. Path mirrors routes/web.php's cron/scrape/* group.
        ['command' => 'stocks:sync-list', 'group' => 'scrape', 'path' => 'scrape/sync-stock-list', 'when' => '06:00 NPT, daily', 'cron_npt' => '0 6 * * *', 'cron_utc' => '15 0 * * *'],
        ['command' => 'market:sync', 'group' => 'scrape', 'path' => 'scrape/market-sync-stock', 'when' => '15:30 NPT, Mon-Fri', 'cron_npt' => '30 15 * * 1-5', 'cron_utc' => '45 9 * * 1-5'],
        ['command' => 'market:sync-index', 'group' => 'scrape', 'path' => 'scrape/market-sync-index', 'when' => '15:32 NPT, Mon-Fri', 'cron_npt' => '32 15 * * 1-5', 'cron_utc' => '47 9 * * 1-5'],

        // group: 'reports' — no external call, only recomputes from data already in the DB. Path mirrors routes/web.php's cron/reports/* group.
        ['command' => 'market:recalculate', 'group' => 'reports', 'path' => 'reports/market-recalculate', 'when' => '15:40 NPT, Mon-Fri', 'cron_npt' => '40 15 * * 1-5', 'cron_utc' => '55 9 * * 1-5'],
        ['command' => 'ml:train-predictor', 'group' => 'reports', 'path' => 'reports/train-ml', 'when' => '03:30 NPT, Monday', 'cron_npt' => '30 3 * * 1', 'cron_utc' => '45 21 * * 0'],
        ['command' => 'signals:backtest-accuracy', 'group' => 'reports', 'path' => 'reports/backtest-signals', 'when' => '04:00 NPT, Monday', 'cron_npt' => '0 4 * * 1', 'cron_utc' => '15 22 * * 0'],
    ];

    public function index()
    {
        $secret = config('services.cron.secret');
        $registered = Artisan::all();

        $jobs = collect(self::JOBS)->map(function ($job) use ($secret, $registered) {
            $commandName = strtok($job['command'], ' ');

            return [
                'command' => $job['command'],
                'group' => $job['group'],
                'url' => url('/cron/'.$job['path']).($secret ? '?key='.$secret : ''),
                'when' => $job['when'],
                'cron_npt' => $job['cron_npt'],
                'cron_utc' => $job['cron_utc'],
                'description' => isset($registered[$commandName]) ? $registered[$commandName]->getDescription() : null,
            ];
        })->values();

        // Stocks currently flagged with a failed per-stock fetch (history or
        // sector — see Stock::flagScrapeError()) — not a retry mechanism,
        // just visibility instead of a failure sitting silently in a log
        // file. Cleared automatically the next time that fetch succeeds.
        $flaggedStocks = Stock::whereNotNull('scrape_error')
            ->orderByDesc('scrape_error_at')
            ->get(['id', 'symbol', 'company_name', 'scrape_error', 'scrape_error_source', 'scrape_error_at']);

        return response()->json([
            'secret_configured' => $secret !== null && $secret !== '',
            'jobs' => $jobs,
            'flagged_stocks' => $flaggedStocks,
        ]);
    }
}
