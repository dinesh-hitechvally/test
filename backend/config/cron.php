<?php

use App\Tasks\Ai\GenerateAiOpinionsTask;
use App\Tasks\Analysis\BacktestNextCloseTask;
use App\Tasks\Analysis\BacktestSignalsTask;
use App\Tasks\Analysis\RecalculateMarketTask;
use App\Tasks\MachineLearning\TrainMlTask;
use App\Tasks\MarketData\FetchHistoriesTask;
use App\Tasks\MarketData\FetchStockHistoryTask;
use App\Tasks\MarketData\MarketSyncIndexTask;
use App\Tasks\MarketData\MarketSyncTask;
use App\Tasks\MarketData\SyncDividendsTask;
use App\Tasks\MarketData\SyncFundamentalsTask;
use App\Tasks\MarketData\SyncSectorsTask;
use App\Tasks\MarketData\SyncStockListTask;
use App\Tasks\MarketData\VerifyNepseTokenTask;

/*
|--------------------------------------------------------------------------
| URL-triggered tasks
|--------------------------------------------------------------------------
|
| This hosting has no server cron, so an external pinger (cron-job.org etc.)
| hits /cron/<path>?key=CRON_SECRET instead. Each entry maps a path to the
| App\Tasks class it runs — routes/web.php registers them, and the Data
| Source Settings page (GET /api/schedule) lists the scheduled ones. The
| tasks themselves know nothing about cron; they can be run from anywhere
| (see App\Tasks\Task).
|
| Paths are grouped by what they touch:
|   scrape/*   calls an external source (nepalstock.com / ShareSansar /
|              MeroLagani / Groq) and writes what comes back.
|   reports/*  never makes an external call — only recomputes from the DB.
|
| Timing is given two ways, for pingers with and without per-job timezones:
|   when/cron_npt  Asia/Kathmandu (UTC+5:45) — NEPSE's own trading hours.
|   cron_utc       the same instant in UTC. NPT is 5h45m ahead, so this is
|                  NOT "subtract 6 hours": the minute shifts too, and the
|                  Monday-03:xx NPT jobs land on *Sunday* in UTC.
| No timing = on-demand: ping it by hand, or as often as you like.
|
| Recalculation has no scheduled entry — it follows every price update
| automatically (StockPricesUpdated event); market-recalculate is only for
| manual full refreshes (?all=1).
|
*/

return [

    'tasks' => [
        // 06:00 — before the market opens, so brand-new or non-trading
        // listings already have a stocks row (market sync only discovers a
        // stock as a byproduct of it trading that day).
        'scrape/sync-stock-list' => ['task' => SyncStockListTask::class, 'when' => '06:00 NPT, daily', 'cron_npt' => '0 6 * * *', 'cron_utc' => '15 0 * * *'],

        // ~30 min after NEPSE's ~15:00 close, 2 minutes apart.
        'scrape/market-sync-stock' => ['task' => MarketSyncTask::class, 'when' => '15:30 NPT, Mon-Fri', 'cron_npt' => '30 15 * * 1-5', 'cron_utc' => '45 9 * * 1-5'],
        'scrape/market-sync-index' => ['task' => MarketSyncIndexTask::class, 'when' => '15:32 NPT, Mon-Fri', 'cron_npt' => '32 15 * * 1-5', 'cron_utc' => '47 9 * * 1-5'],

        // Per-stock, on-demand: each run works through every pending stock.
        'scrape/fetch-histories' => ['task' => FetchHistoriesTask::class],
        'scrape/fetch-history/{symbol}' => ['task' => FetchStockHistoryTask::class],
        'scrape/sync-sectors' => ['task' => SyncSectorsTask::class],
        'scrape/sync-dividends' => ['task' => SyncDividendsTask::class],
        'scrape/ai-opinions' => ['task' => GenerateAiOpinionsTask::class],
        'scrape/fundamentals' => ['task' => SyncFundamentalsTask::class],
        'scrape/verify-token' => ['task' => VerifyNepseTokenTask::class],

        'reports/market-recalculate' => ['task' => RecalculateMarketTask::class],

        // Weekly, early Monday NPT — quiet hours, reads only stored history.
        'reports/train-ml' => ['task' => TrainMlTask::class, 'when' => '03:30 NPT, Monday', 'cron_npt' => '30 3 * * 1', 'cron_utc' => '45 21 * * 0'],
        'reports/backtest-signals' => ['task' => BacktestSignalsTask::class, 'when' => '04:00 NPT, Monday', 'cron_npt' => '0 4 * * 1', 'cron_utc' => '15 22 * * 0'],
        'reports/backtest-next-close' => ['task' => BacktestNextCloseTask::class, 'when' => '04:15 NPT, Monday', 'cron_npt' => '15 4 * * 1', 'cron_utc' => '30 22 * * 0'],
    ],

];
