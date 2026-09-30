<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_quality_flags', function (Blueprint $table) {
            $table->id();
            // Null for a market-wide check (missing trading dates) that isn't about one stock.
            $table->foreignId('stock_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('trade_date')->nullable();
            $table->string('check_type', 40);
            $table->enum('severity', ['info', 'warning', 'critical']);
            $table->text('message');
            $table->timestamp('detected_at')->useCurrent();
            // Set once a human has reviewed it — dismissed as expected (a real circuit-limit
            // move) or acted on (a corporate action entered). A still-bad row re-flags itself
            // the next time its check runs; see DataQualityService::flag().
            $table->timestamp('resolved_at')->nullable();

            $table->index(['stock_id', 'trade_date']);
            $table->index(['check_type', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_quality_flags');
    }
};
