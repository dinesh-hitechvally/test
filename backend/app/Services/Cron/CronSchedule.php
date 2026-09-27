<?php

namespace App\Services\Cron;

use App\Services\Cron\Tasks\Reports\BacktestNextCloseTask;
use App\Services\Cron\Tasks\Reports\BacktestSignalsTask;
use App\Services\Cron\Tasks\Reports\RecalculateMarketTask;
use App\Services\Cron\Tasks\Reports\TrainMlTask;
use App\Services\Cron\Tasks\Scrape\FetchHistoriesTask;
use App\Services\Cron\Tasks\Scrape\FetchStockHistoryTask;
use App\Services\Cron\Tasks\Scrape\GenerateAiOpinionsTask;
use App\Services\Cron\Tasks\Scrape\MarketSyncIndexTask;
use App\Services\Cron\Tasks\Scrape\MarketSyncTask;
use App\Services\Cron\Tasks\Scrape\SyncDividendsTask;
use App\Services\Cron\Tasks\Scrape\SyncFundamentalsTask;
use App\Services\Cron\Tasks\Scrape\SyncSectorsTask;
use App\Services\Cron\Tasks\Scrape\SyncStockListTask;
use App\Services\Cron\Tasks\Scrape\VerifyNepseTokenTask;

/**
 * THE list of URL-triggered cron tasks — the single source of truth.
 * routes/web.php registers one /cron/<path> route per entry, and the Data
 * Source Settings page (GET /api/schedule) lists the scheduled ones. Adding
 * a task = write the CronTask class + add one line here.
 *
 * Every URL needs ?key=CRON_SECRET (VerifyCronSecret). Paths are grouped:
 *   scrape/*   calls an external source (nepalstock.com / ShareSansar /
 *              MeroLagani / Groq) and writes what comes back.
 *   reports/*  never makes an external call — only recomputes from data
 *              already in this app's database.
 *
 * Timing is given two ways, for pingers with and without per-job timezones:
 *   when/cron_npt  Asia/Kathmandu (UTC+5:45) — NEPSE's own trading hours.
 *   cron_utc       the same instant in UTC. NPT is 5h45m ahead, so this is
 *                  NOT "subtract 6 hours": the minute shifts too, and the
 *                  Monday-03:xx NPT jobs land on *Sunday* in UTC.
 * No timing = on-demand: ping it by hand, or as often as you like.
 *
 * Recalculation has no entry of its own in the schedule — it follows every
 * price update automatically (StockPricesUpdated event); market-recalculate
 * is only for manual full refreshes (?all=1).
 */
final class CronSchedule
{
    /** @var array<string, array{task: class-string<CronTask>, when?: string, cron_npt?: string, cron_utc?: string}> */
    public const TASKS = [
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
    ];

    /**
     * The scheduled tasks, ready to paste into a pinger — shape the Data
     * Source Settings page reads ('command' is the task's name).
     *
     * @return list<array<string, ?string>>
     */
    public function scheduled(): array
    {
        $secret = config('services.cron.secret');

        return collect(self::TASKS)
            ->filter(fn ($entry) => isset($entry['when']))
            ->map(function ($entry, $path) use ($secret) {
                $task = app($entry['task']);

                return [
                    'command' => $task->name(),
                    'group' => strtok($path, '/'),
                    'url' => url('/cron/'.$path).($secret ? '?key='.$secret : ''),
                    'when' => $entry['when'],
                    'cron_npt' => $entry['cron_npt'],
                    'cron_utc' => $entry['cron_utc'],
                    'description' => $task->description(),
                ];
            })
            ->values()
            ->all();
    }
}
