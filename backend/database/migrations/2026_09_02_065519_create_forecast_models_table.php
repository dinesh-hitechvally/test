<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_models', function (Blueprint $table) {
            $table->id();
            $table->string('method', 50)->default('holt_linear_trend');
            $table->unsignedInteger('horizon_days');
            $table->unsignedInteger('test_points');
            $table->decimal('mape', 8, 4);
            $table->decimal('directional_accuracy', 6, 4);
            $table->unsignedInteger('stocks_used');
            $table->timestamp('computed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_models');
    }
};
