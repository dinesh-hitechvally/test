<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stock_scrape_statuses', function (Blueprint $table) {
            
            $table->id();
            $table->foreignId('stock_id')->unique()->constrained()->cascadeOnDelete();

            // Full-history fetch (SharesansarHistoryService / NepalStockHistoryService).
            $table->timestamp('history_fetched_at')->nullable();
            $table->text('history_error')->nullable();
            $table->timestamp('history_error_at')->nullable();

            // Sector fetch (NepalStockSecurityResolver::fetchSector) — success is
            // stocks.sector itself being set, so there's no history_fetched_at
            // equivalent needed here, only the error side.
            $table->text('sector_error')->nullable();
            $table->timestamp('sector_error_at')->nullable();

            // Dividend fetch (NepalStockCorporateActionsService::fetchDividends).
            // Its own fetched_at, deliberately distinct from "has any dividend
            // rows" — a stock can genuinely have zero dividends ever declared,
            // which is a successful fetch, not a pending one.
            $table->timestamp('dividend_fetched_at')->nullable();
            $table->text('dividend_error')->nullable();
            $table->timestamp('dividend_error_at')->nullable();

            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_scrape_statuses');
    }
};
