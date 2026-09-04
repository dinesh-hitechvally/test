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
        Schema::create('index_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('index_name', 100);
            $table->date('trade_date');
            $table->decimal('close', 12, 4);
            $table->decimal('high', 12, 4)->nullable();
            $table->decimal('low', 12, 4)->nullable();
            $table->decimal('previous_close', 12, 4)->nullable();
            $table->decimal('change', 12, 4)->nullable();
            $table->decimal('change_pct', 8, 4)->nullable();
            $table->decimal('fifty_two_week_high', 12, 4)->nullable();
            $table->decimal('fifty_two_week_low', 12, 4)->nullable();
            $table->timestamps();

            $table->unique(['index_name', 'trade_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('index_snapshots');
    }
};
