<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->date('trade_date');
            $table->decimal('open_price', 18, 4)->nullable();
            $table->decimal('high_price', 18, 4)->nullable();
            $table->decimal('low_price', 18, 4)->nullable();
            $table->decimal('close_price', 18, 4)->nullable();
            $table->unsignedBigInteger('volume')->nullable();
            $table->decimal('turnover', 20, 2)->nullable();
            $table->timestamps();

            $table->unique(['stock_id', 'trade_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_prices');
    }
};
