<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portfolio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['buy', 'sell']);
            $table->unsignedInteger('quantity');
            $table->decimal('price', 18, 4);
            $table->decimal('fees', 12, 4)->default(0);
            $table->date('transaction_date');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['portfolio_id', 'stock_id', 'transaction_date'], 'portfolio_tx_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_transactions');
    }
};
