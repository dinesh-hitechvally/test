<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ml_models', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->default('direction_predictor');
            $table->unsignedInteger('horizon_days');
            $table->unsignedInteger('train_samples');
            $table->unsignedInteger('test_samples');
            $table->decimal('accuracy', 6, 4);
            $table->decimal('precision', 6, 4);
            $table->decimal('recall', 6, 4);
            $table->decimal('f1', 6, 4);
            $table->unsignedInteger('stocks_used');
            $table->string('model_path');
            $table->timestamp('trained_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_models');
    }
};
