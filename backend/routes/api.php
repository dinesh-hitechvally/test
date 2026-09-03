<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MarketController;
use App\Http\Controllers\Api\PortfolioController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ScrapeController;
use App\Http\Controllers\Api\SignalController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\WatchlistController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    Route::get('/stocks', [StockController::class, 'index']);
    Route::post('/stocks', [StockController::class, 'store']);
    Route::post('/stocks/import-csv', [StockController::class, 'importCsv']);
    Route::get('/stocks/{symbol}', [StockController::class, 'show']);
    Route::get('/stocks/{symbol}/prices', [StockController::class, 'prices']);
    Route::get('/stocks/{symbol}/indicators', [StockController::class, 'indicators']);
    Route::get('/stocks/{symbol}/signals', [StockController::class, 'signals']);
    Route::get('/stocks/{symbol}/forecast', [StockController::class, 'forecast']);
    Route::post('/stocks/{symbol}/fetch-full-history', [StockController::class, 'fetchFullHistory']);
    Route::post('/stocks/{symbol}/fetch-nepse-history', [StockController::class, 'fetchNepseHistory']);
    Route::get('/stocks/{symbol}/ml-prediction', [StockController::class, 'mlPrediction']);

    Route::get('/signals/today', [SignalController::class, 'today']);

    Route::get('/reports/dashboard', [ReportController::class, 'dashboard']);
    Route::get('/reports/market', [ReportController::class, 'market']);
    Route::get('/reports/sectors', [ReportController::class, 'sectors']);
    Route::get('/reports/sector', [ReportController::class, 'sector']);
    Route::get('/reports/stock/{symbol}', [ReportController::class, 'stock']);
    Route::get('/reports/rules', [ReportController::class, 'rules']);
    Route::get('/reports/rule-scan', [ReportController::class, 'ruleScan']);
    Route::get('/reports/technical/{symbol}', [ReportController::class, 'technical']);

    Route::get('/market/screener', [MarketController::class, 'screener']);
    Route::get('/market/52-week', [MarketController::class, 'fiftyTwoWeek']);

    Route::post('/scrape/run', [ScrapeController::class, 'run']);
    Route::post('/scrape/run-nepse', [ScrapeController::class, 'runNepse']);
    Route::get('/scrape/logs', [ScrapeController::class, 'logs']);

    Route::get('/watchlists', [WatchlistController::class, 'index']);
    Route::post('/watchlists', [WatchlistController::class, 'store']);
    Route::post('/watchlists/{watchlist}/items', [WatchlistController::class, 'addItem']);
    Route::delete('/watchlists/{watchlist}/items/{stock}', [WatchlistController::class, 'removeItem']);

    Route::get('/portfolios', [PortfolioController::class, 'index']);
    Route::post('/portfolios', [PortfolioController::class, 'store']);
    Route::get('/portfolios/{portfolio}', [PortfolioController::class, 'show']);
    Route::get('/portfolios/{portfolio}/transactions', [PortfolioController::class, 'transactions']);
    Route::post('/portfolios/{portfolio}/transactions', [PortfolioController::class, 'storeTransaction']);
    Route::delete('/portfolios/{portfolio}/transactions/{transaction}', [PortfolioController::class, 'destroyTransaction']);
    Route::get('/portfolios/{portfolio}/performance', [PortfolioController::class, 'performance']);
    Route::get('/portfolios/{portfolio}/export', [PortfolioController::class, 'exportCsv']);
});
