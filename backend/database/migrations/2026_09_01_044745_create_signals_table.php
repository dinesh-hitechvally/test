<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->date('trade_date');
            $table->enum('signal', ['buy', 'hold', 'sell'])->comment('The decision for the day.');
            $table->decimal('score', 5, 4)->comment('Final BUY % minus SELL % as -1..1 — what the feeds and screens sort on. The full percentages are in signal_breakdowns.');
            $table->json('reasons')->nullable()->comment('Readable lines for the feed.');
            $table->json('rule_keys')->nullable()->comment('Codes of the rules that fired (see SignalRules); the rule scanner filters on these.');
            // Only updated_at: it is compared with technical_indicators.updated_at to find stocks that need new signals.
            $table->timestamp('updated_at')->nullable()->comment('When this signal was last (re)generated.');

            $table->unique(['stock_id', 'trade_date']);
            $table->index(['trade_date', 'signal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signals');
    }
};
