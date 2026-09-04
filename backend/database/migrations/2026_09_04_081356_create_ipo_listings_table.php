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
        Schema::create('ipo_listings', function (Blueprint $table) {
            $table->id();
            $table->string('stage', 20); // 'open' (existing/open issues table) or 'upcoming'
            $table->string('symbol', 30)->nullable();
            $table->string('company_name', 255)->nullable();
            $table->decimal('units', 18, 2)->nullable();
            $table->decimal('price', 18, 4)->nullable();
            $table->string('sector', 100)->nullable();
            $table->string('remark', 255)->nullable(); // issue manager, for upcoming rows
            $table->date('opening_date')->nullable();
            $table->date('closing_date')->nullable();
            $table->string('status', 30)->nullable();
            $table->string('detail_url', 500)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ipo_listings');
    }
};
