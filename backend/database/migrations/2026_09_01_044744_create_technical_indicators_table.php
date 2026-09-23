<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technical_indicators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->date('trade_date');
            $table->decimal('sma_20', 18, 4)->nullable();
            $table->decimal('sma_50', 18, 4)->nullable();
            $table->decimal('sma_100', 18, 4)->nullable();
            $table->decimal('sma_200', 18, 4)->nullable();
            $table->decimal('ema_12', 18, 4)->nullable();
            $table->decimal('ema_26', 18, 4)->nullable();
            $table->decimal('rsi_14', 8, 4)->nullable();
            $table->decimal('macd', 12, 4)->nullable();
            $table->decimal('macd_signal', 12, 4)->nullable();
            $table->decimal('macd_histogram', 12, 4)->nullable();
            $table->decimal('bb_upper', 18, 4)->nullable();
            $table->decimal('bb_middle', 18, 4)->nullable();
            $table->decimal('bb_lower', 18, 4)->nullable();
            $table->decimal('atr_14', 12, 4)->nullable();
            $table->timestamps();

            $table->unique(['stock_id', 'trade_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_indicators');
    }
};
