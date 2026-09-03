<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->date('trade_date');
            $table->enum('signal', ['strong_buy', 'buy', 'hold', 'sell', 'strong_sell']);
            $table->decimal('score', 5, 4);
            $table->json('reasons')->nullable();
            $table->decimal('price_at_signal', 18, 4)->nullable();
            $table->timestamps();

            $table->unique(['stock_id', 'trade_date']);
            $table->index(['trade_date', 'signal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signals');
    }
};
