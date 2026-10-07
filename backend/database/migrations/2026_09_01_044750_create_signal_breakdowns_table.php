<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_breakdowns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete()->comment('The stock this breakdown is for.');
            $table->date('trade_date')->comment('The trading day the breakdown explains (the same day as that stock\'s signal).');
            // One row per stock per day, so any past date can be opened and explained.
            $table->decimal('buy_pct', 5, 2)->comment('Final BUY %: the weighted average of every category\'s BUY %. Buy + sell + hold = 100.');
            $table->decimal('sell_pct', 5, 2)->comment('Final SELL %: the weighted average of every category\'s SELL %.');
            $table->decimal('hold_pct', 5, 2)->comment('Final HOLD %: the weighted average of every category\'s HOLD %.');
            $table->string('hold_type', 30)->nullable()->comment('Why to hold, when the decision is hold: long_term, consolidation, wait_confirmation, profit_protection, temporary_weakness or overbought.');
            // Each category's own BUY / SELL / HOLD %: technical_buy, technical_sell, technical_hold, fundamental_buy, ...
            // All three are null when the category had no data that day (e.g. fundamental / valuation on a past day).
            foreach (['technical', 'fundamental', 'trend', 'momentum', 'volume', 'risk', 'valuation'] as $category) {
                foreach (['buy', 'sell', 'hold'] as $side) {
                    $table->decimal("{$category}_{$side}", 5, 2)->nullable()->comment(ucfirst($category).' category '.strtoupper($side).' %: the average of its conditions\' '.strtoupper($side).' %. Null = no data that day.');
                }
            }
            // Why-to-hold scores, 0-100 each; the highest is hold_type when the decision is hold.
            $table->decimal('hold_score_long_term', 5, 2)->comment('Hold score: long-term strength (above the 200-day average, MA50 above MA200, sound fundamentals).');
            $table->decimal('hold_score_consolidation', 5, 2)->comment('Hold score: consolidation (no real trend, low ADX).');
            $table->decimal('hold_score_wait_confirmation', 5, 2)->comment('Hold score: waiting for confirmation (a dip-buy without a bounce, or buyers and sellers evenly matched).');
            $table->decimal('hold_score_profit_protection', 5, 2)->comment('Hold score: profit protection (trading near the top of its 52-week range).');
            $table->decimal('hold_score_temporary_weakness', 5, 2)->comment('Hold score: temporary weakness (below the short averages while the longer trend is intact).');
            $table->decimal('hold_score_overbought', 5, 2)->comment('Hold score: overbought (RSI well above 50).');
            $table->json('conditions')->comment('Every individual condition that day with its result and {buy, sell, hold} %.');
            $table->timestamps();
            $table->unique(['stock_id', 'trade_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_breakdowns');
    }
};
