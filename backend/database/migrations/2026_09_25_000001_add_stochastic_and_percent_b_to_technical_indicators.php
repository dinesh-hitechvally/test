<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technical_indicators', function (Blueprint $table) {
            // Wider than rsi_14 on purpose: %B is unbounded outside the
            // bands, and a very narrow band can push it well past ±1.
            $table->decimal('bb_percent_b', 12, 4)->nullable()->after('bb_lower');
            $table->decimal('stoch_k', 8, 4)->nullable()->after('bb_percent_b');
            $table->decimal('stoch_d', 8, 4)->nullable()->after('stoch_k');
        });
    }

    public function down(): void
    {
        Schema::table('technical_indicators', function (Blueprint $table) {
            $table->dropColumn(['bb_percent_b', 'stoch_k', 'stoch_d']);
        });
    }
};
