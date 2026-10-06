<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            // Cash available to buy with. Set by the user; buys don't adjust it.
            $table->decimal('cash_balance', 18, 4)->default(0)->after('name');
        });

        Schema::create('buy_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portfolio_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signal_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['pending', 'executed', 'cancelled', 'expired'])->default('pending');
            $table->date('trade_date');
            $table->decimal('signal_score', 5, 4);
            $table->unsignedInteger('quantity');
            $table->decimal('entry_price', 18, 4);
            $table->decimal('stop_loss', 18, 4);
            $table->decimal('target_price', 18, 4);
            $table->decimal('risk_per_share', 18, 4);
            $table->decimal('risk_amount', 18, 4);
            $table->decimal('position_value', 18, 4);
            $table->decimal('fees', 18, 4);
            $table->decimal('risk_reward', 8, 2);
            $table->timestamps();

            $table->index(['portfolio_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buy_orders');
        Schema::table('portfolios', fn (Blueprint $table) => $table->dropColumn('cash_balance'));
    }
};
