<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technical_indicators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete()->comment('The stock this row\'s indicators are for.');
            $table->date('trade_date')->comment('The trading day these indicators are computed as of.');
            $table->decimal('sma_20', 18, 4)->nullable()->comment('20-day simple moving average of the close price.');
            $table->decimal('sma_50', 18, 4)->nullable()->comment('50-day simple moving average of the close price.');
            $table->decimal('sma_100', 18, 4)->nullable()->comment('100-day simple moving average of the close price.');
            $table->decimal('sma_200', 18, 4)->nullable()->comment('200-day simple moving average of the close price.');
            $table->decimal('ema_12', 18, 4)->nullable()->comment('12-day exponential moving average of the close price — the MACD line\'s fast EMA.');
            $table->decimal('ema_26', 18, 4)->nullable()->comment('26-day exponential moving average of the close price — the MACD line\'s slow EMA.');
            $table->decimal('rsi_14', 8, 4)->nullable()->comment('14-day Relative Strength Index, 0-100. >=70 overbought, <=30 oversold.');
            $table->decimal('macd', 12, 4)->nullable()->comment('MACD line: ema_12 minus ema_26.');
            $table->decimal('macd_signal', 12, 4)->nullable()->comment('MACD signal line: a 9-day EMA of the MACD line.');
            $table->decimal('macd_histogram', 12, 4)->nullable()->comment('MACD line minus its signal line — momentum behind a MACD crossover.');
            $table->decimal('bb_upper', 18, 4)->nullable()->comment('Bollinger upper band: 20-day SMA of close + 2 standard deviations.');
            $table->decimal('bb_middle', 18, 4)->nullable()->comment('Bollinger middle band: the 20-day SMA of close (same value as sma_20).');
            $table->decimal('bb_lower', 18, 4)->nullable()->comment('Bollinger lower band: 20-day SMA of close - 2 standard deviations.');
            // Wider than rsi_14 on purpose: %B is unbounded outside the
            // bands, and a very narrow band can push it well past ±1.
            $table->decimal('bb_percent_b', 12, 4)->nullable()->comment('Where the close sits within the Bollinger bands: 0 = on the lower band, 1 = on the upper band, outside 0-1 = outside the bands.');
            $table->decimal('stoch_k', 8, 4)->nullable()->comment('Slow stochastic %K (14,3,3) — where the close sits in its trailing 14-day high/low range, 0-100, smoothed.');
            $table->decimal('stoch_d', 8, 4)->nullable()->comment('Slow stochastic %D: a 3-day SMA of %K.');
            $table->decimal('atr_14', 12, 4)->nullable()->comment('14-day Average True Range — volatility in the stock\'s own price units, used for stop-loss sizing.');
            $table->decimal('atr_percent', 12, 4)->nullable()->comment('atr_14 as a % of the close — comparable across stocks of different price levels, unlike atr_14 itself.');
            // ADX says nothing about direction, only whether a trend exists worth trading
            // (conventionally: <20 no trend/choppy, >25 trending). +DI/-DI say which
            // direction is winning the directional movement.
            $table->decimal('adx_14', 8, 4)->nullable()->comment('14-day Average Directional Index, 0-100 — trend strength, direction-agnostic.');
            $table->decimal('plus_di_14', 8, 4)->nullable()->comment('+DI(14): the smoothed upward directional movement, as a % of smoothed true range.');
            $table->decimal('minus_di_14', 8, 4)->nullable()->comment('-DI(14): the smoothed downward directional movement, as a % of smoothed true range.');
            // The closest support/resistance level as of this day — same swing-detection +
            // clustering the live Technical Analysis report uses, persisted daily too.
            $table->decimal('support_price', 18, 4)->nullable()->comment('Closest support level below the close as of this day, from clustered swing lows.');
            $table->decimal('resistance_price', 18, 4)->nullable()->comment('Closest resistance level above the close as of this day, from clustered swing highs.');
            // Trailing 365-calendar-day high/low as of this day.
            $table->decimal('high_52w', 18, 4)->nullable()->comment('Highest high in the trailing 365 calendar days ending this day.');
            $table->decimal('low_52w', 18, 4)->nullable()->comment('Lowest low in the trailing 365 calendar days ending this day.');
            // Today's volume over its trailing 20-day average — "volume change".
            $table->decimal('volume_ratio', 12, 4)->nullable()->comment('Today\'s volume over its trailing 20-day average volume — "volume change". >1 = above-average participation.');
            $table->timestamps();

            $table->unique(['stock_id', 'trade_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_indicators');
    }
};
