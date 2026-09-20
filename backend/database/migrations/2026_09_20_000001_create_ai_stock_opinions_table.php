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
        Schema::create('ai_stock_opinions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->unique()->constrained()->cascadeOnDelete();

            // Last successful generation — null until the cron has run for
            // this stock at least once.
            $table->string('verdict')->nullable();
            $table->string('confidence')->nullable();
            $table->text('reasoning')->nullable();
            $table->timestamp('generated_at')->nullable();

            // Last failed attempt (e.g. Gemini's free tier under load) —
            // kept separate from the fields above so a failure never
            // clobbers the last good opinion still worth showing.
            $table->text('error')->nullable();
            $table->timestamp('error_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_stock_opinions');
    }
};
