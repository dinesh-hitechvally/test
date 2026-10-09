<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_settings', function (Blueprint $table) {
            // One row per user: how THEY want BUY / SELL / HOLD decided. No row = the system defaults (config/signals.php).
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weight_technical');
            $table->unsignedTinyInteger('weight_fundamental');
            $table->unsignedTinyInteger('weight_trend');
            $table->unsignedTinyInteger('weight_momentum');
            $table->unsignedTinyInteger('weight_volume');
            $table->unsignedTinyInteger('weight_risk');
            $table->unsignedTinyInteger('weight_valuation');
            $table->unsignedTinyInteger('min_pct')->comment('A side needs at least this final % to win; otherwise HOLD.');
            $table->unsignedTinyInteger('margin')->comment('...and must lead the other side by this many points.');
            $table->boolean('guard_extremes')->comment('Hold instead of selling an oversold price / buying an overbought one.');
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_settings');
    }
};
