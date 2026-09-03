<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->date('generated_date');
            $table->date('target_date');
            $table->decimal('predicted_close', 18, 4);
            $table->string('method', 50)->default('linear_regression');
            $table->timestamps();

            $table->unique(['stock_id', 'generated_date', 'target_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecasts');
    }
};
