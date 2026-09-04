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
        Schema::create('right_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->string('ratio', 30)->nullable(); // e.g. "1:1" — not numeric
            $table->decimal('total_units', 18, 2)->nullable();
            $table->decimal('issue_price', 18, 4)->nullable();
            $table->date('opening_date')->nullable();
            $table->date('closing_date')->nullable();
            $table->string('book_closure_date', 50)->nullable();
            $table->date('listing_date')->nullable();
            $table->string('issue_manager', 255)->nullable();
            $table->string('status', 30)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('right_shares');
    }
};
