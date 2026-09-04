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
        Schema::table('watchlist_items', function (Blueprint $table) {
            $table->decimal('alert_price', 18, 4)->nullable();
            $table->enum('alert_direction', ['above', 'below'])->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('watchlist_items', function (Blueprint $table) {
            $table->dropColumn(['alert_price', 'alert_direction']);
        });
    }
};
