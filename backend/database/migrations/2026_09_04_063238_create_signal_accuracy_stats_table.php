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
        Schema::create('signal_accuracy_stats', function (Blueprint $table) {
            $table->id();
            $table->string('signal_type', 20); // strong_buy | buy | hold | sell | strong_sell
            $table->unsignedSmallInteger('horizon_days');
            $table->unsignedInteger('sample_size');
            $table->decimal('win_rate', 6, 2)->nullable(); // % of occurrences where price moved the "right" way
            $table->decimal('avg_forward_return_pct', 8, 4)->nullable();
            $table->decimal('baseline_win_rate', 6, 2)->nullable(); // same stat for a random/hold day, for comparison
            $table->timestamp('computed_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('signal_accuracy_stats');
    }
};
