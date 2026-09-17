<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            // Relocated to stock_scrape_statuses (see the previous migration,
            // which already backfilled this data) — a stock's own row
            // shouldn't carry scraping-pipeline bookkeeping.
            $table->dropColumn(['history_fetched_at', 'scrape_error', 'scrape_error_source', 'scrape_error_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->timestamp('history_fetched_at')->nullable();
            $table->text('scrape_error')->nullable()->after('history_fetched_at');
            $table->string('scrape_error_source', 50)->nullable()->after('scrape_error');
            $table->timestamp('scrape_error_at')->nullable()->after('scrape_error_source');
        });
    }
};
