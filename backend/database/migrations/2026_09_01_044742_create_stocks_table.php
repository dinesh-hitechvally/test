<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 20)->unique();
            $table->unsignedInteger('nepse_security_id')->nullable()->unique();
            $table->foreignId('sector_id')->nullable()->constrained()->nullOnDelete();
            $table->string('company_name')->nullable();
            $table->string('share_group', 10)->nullable();
            // What kind of security it is on NEPSE ("Equity", "Mutual Funds", "Non-Convertible Debentures"…), from
            // nepalstock.com's company list (instrumentType). Null until the stock list sync has seen it.
            $table->string('instrument_type', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
