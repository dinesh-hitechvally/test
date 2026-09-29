<?php

use App\Http\Controllers\CronController;
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
use Illuminate\Support\Facades\Route;

// Task URLs, scheduled in cPanel cron (e.g. `curl -s "https://api.bizrms.com/cron/scrape/market-sync-stock?key=..."`).
// Every URL needs ?key=<CRON_SECRET> (VerifyCronSecret). Each route runs the
// task class set as its 'task' default.
Route::middleware('cron.secret')->prefix('cron')->group(function () {

    // Calls an external source (nepalstock.com / ShareSansar / MeroLagani / Groq).
    Route::prefix('scrape')->group(function () {
        Route::get('sync-stock-list', CronController::class)->defaults('task', SyncStockListTask::class);
        Route::get('market-sync-stock', CronController::class)->defaults('task', MarketSyncTask::class);
        Route::get('market-sync-index', CronController::class)->defaults('task', MarketSyncIndexTask::class);
        Route::get('fetch-histories', CronController::class)->defaults('task', FetchHistoriesTask::class);
        Route::get('fetch-history/{symbol}', CronController::class)->defaults('task', FetchStockHistoryTask::class);
        Route::get('sync-sectors', CronController::class)->defaults('task', SyncSectorsTask::class);
        Route::get('sync-dividends', CronController::class)->defaults('task', SyncDividendsTask::class);
        Route::get('ai-opinions', CronController::class)->defaults('task', GenerateAiOpinionsTask::class);
        Route::get('fundamentals', CronController::class)->defaults('task', SyncFundamentalsTask::class);
        Route::get('verify-token', CronController::class)->defaults('task', VerifyNepseTokenTask::class);
    });

    // Recomputes from the database only — no external calls.
    Route::prefix('reports')->group(function () {
        Route::get('market-recalculate', CronController::class)->defaults('task', RecalculateMarketTask::class);
        Route::get('train-ml', CronController::class)->defaults('task', TrainMlTask::class);
        Route::get('backtest-signals', CronController::class)->defaults('task', BacktestSignalsTask::class);
        Route::get('backtest-next-close', CronController::class)->defaults('task', BacktestNextCloseTask::class);
    });
});
