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
        Schema::create('next_close_accuracy_stats', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sample_size');
            $table->unsignedInteger('stocks_used');
            $table->decimal('mape', 8, 4);
            $table->decimal('naive_mape', 8, 4);
            $table->decimal('direction_accuracy', 8, 4);
            $table->timestamp('computed_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('next_close_accuracy_stats');
    }
};
