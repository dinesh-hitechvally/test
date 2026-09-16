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
            // Set when any per-stock external fetch fails (full history,
            // sector, dividends...), cleared the next time that same kind of
            // fetch succeeds — not a retry, just a visible "this stock's data
            // may be stale/incomplete" flag instead of a failure sitting
            // silently in a log file.
            $table->text('scrape_error')->nullable()->after('history_fetched_at');
            $table->string('scrape_error_source', 50)->nullable()->after('scrape_error');
            $table->timestamp('scrape_error_at')->nullable()->after('scrape_error_source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropColumn(['scrape_error', 'scrape_error_source', 'scrape_error_at']);
        });
    }
};
