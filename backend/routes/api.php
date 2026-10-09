<?php

use App\Http\Controllers\Api\AvatarController;
use App\Http\Controllers\Api\PortfolioExportController;
use App\Http\Controllers\Api\PriceImportController;
use Illuminate\Support\Facades\Route;

// The SPA reads and writes everything through /graphql (graphql/schema.graphql).
// Only file transfers stay here: the price CSV upload and the portfolio downloads.

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/stocks/import-csv', PriceImportController::class);

    Route::post('/profile/avatar', [AvatarController::class, 'store']);
    Route::delete('/profile/avatar', [AvatarController::class, 'destroy']);

    Route::get('/portfolios/{portfolio}/export', [PortfolioExportController::class, 'csv']);
    Route::get('/portfolios/{portfolio}/export-pdf', [PortfolioExportController::class, 'pdf']);
    Route::get('/portfolios/{portfolio}/export-excel', [PortfolioExportController::class, 'excel']);
});
