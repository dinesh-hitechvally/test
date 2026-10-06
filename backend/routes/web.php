<?php

use App\Http\Controllers\ApiConsoleController;
use App\Http\Controllers\CronController;
use App\Http\Controllers\DocsController;
use App\Tasks\Ai\GenerateAiOpinionsTask;
use App\Tasks\Analysis\BacktestNextCloseTask;
use App\Tasks\Analysis\BacktestSignalsTask;
use App\Tasks\Analysis\RecalculateMarketTask;
use App\Tasks\DataQuality\ScanDataQualityTask;
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
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// Human-readable documentation: API reference, trading rules, cron jobs. See config/docs.php.
Route::withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])->group(function () {
    Route::get('docs', DocsController::class);
    // Try every API operation and cron URL straight from the browser.
    Route::get('console', ApiConsoleController::class);
});

// Task URLs, scheduled in cPanel cron (e.g. `curl -s "https://api.bizrms.com/cron/fetch/prices?key=..."`).
// Every URL needs ?key=<CRON_SECRET> (VerifyCronSecret). Each route runs the
// task class set as its 'task' default.
//
// URL pattern: /cron/<kind>/<what>
//   fetch/    pull data from an external source and save it as-is. Never computes anything.
//   generate/ compute derived data from what is already in the database.
//   check/    verify health: the NEPSE token, data quality.
Route::middleware('cron.secret')->prefix('cron')->group(function () {

    // External sources (nepalstock.com / ShareSansar / MeroLagani). Raw data in, nothing derived.
    Route::prefix('fetch')->group(function () {
        Route::get('stock-list', CronController::class)->defaults('task', SyncStockListTask::class);
        Route::get('prices', CronController::class)->defaults('task', MarketSyncTask::class);
        Route::get('index', CronController::class)->defaults('task', MarketSyncIndexTask::class);
        Route::get('histories', CronController::class)->defaults('task', FetchHistoriesTask::class);
        Route::get('history/{symbol}', CronController::class)->defaults('task', FetchStockHistoryTask::class);
        Route::get('sectors', CronController::class)->defaults('task', SyncSectorsTask::class);
        Route::get('dividends', CronController::class)->defaults('task', SyncDividendsTask::class);
        Route::get('fundamentals', CronController::class)->defaults('task', SyncFundamentalsTask::class);
    });

    // Derived from stored data. Run after the fetches above.
    Route::prefix('generate')->group(function () {
        Route::get('indicators', CronController::class)->defaults('task', RecalculateMarketTask::class); // + signals + next-close; ?all=1 = every stock
        Route::get('ai-opinions', CronController::class)->defaults('task', GenerateAiOpinionsTask::class); // calls Groq
        Route::get('ml-model', CronController::class)->defaults('task', TrainMlTask::class);
        Route::get('backtest-signals', CronController::class)->defaults('task', BacktestSignalsTask::class);
        Route::get('backtest-next-close', CronController::class)->defaults('task', BacktestNextCloseTask::class);
    });

    Route::prefix('check')->group(function () {
        Route::get('nepse-token', CronController::class)->defaults('task', VerifyNepseTokenTask::class);
        Route::get('data-quality', CronController::class)->defaults('task', ScanDataQualityTask::class);
    });
});
