<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_fundamentals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('eps', 12, 4)->nullable();
            $table->string('eps_fiscal_year', 40)->nullable();
            $table->decimal('pe_ratio', 12, 4)->nullable();
            $table->decimal('book_value', 12, 4)->nullable();
            $table->decimal('pbv', 12, 4)->nullable();
            $table->decimal('market_cap', 20, 2)->nullable();
            $table->decimal('shares_outstanding', 20, 2)->nullable();
            $table->decimal('one_year_yield_pct', 8, 4)->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_fundamentals');
    }
};
