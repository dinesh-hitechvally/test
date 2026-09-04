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
        Schema::create('dividends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->string('fiscal_year', 20); // Nepali B.S. format, e.g. "2081/2082" — not a real date
            $table->decimal('bonus_share_pct', 8, 4)->nullable();
            $table->decimal('cash_dividend_pct', 8, 4)->nullable();
            $table->decimal('total_dividend_pct', 8, 4)->nullable();
            $table->date('announcement_date')->nullable();
            $table->date('distribution_date')->nullable();
            // Kept as free text, not a date column — the source appends a
            // status annotation like "2025-12-31 [Closed]" to this field.
            $table->string('book_closure_date', 50)->nullable();
            $table->date('bonus_listing_date')->nullable();
            $table->timestamps();

            $table->unique(['stock_id', 'fiscal_year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dividends');
    }
};
