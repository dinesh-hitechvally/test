<?php

namespace Tests\Feature\Services;

use App\Models\DailyPrice;
use App\Models\Stock;
use App\Models\TechnicalIndicator;
use App\Services\MarketData\SignalGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignalGeneratorServiceTest extends TestCase
{
    use RefreshDatabase;

    private SignalGeneratorService $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = app(SignalGeneratorService::class);
    }

    private function makeStock(): Stock
    {
        return Stock::create(['symbol' => 'TEST', 'company_name' => 'Test Co', 'is_active' => true]);
    }

    private function addDay(Stock $stock, string $date, float $close, float $rsi, float $bbUpper): void
    {
        DailyPrice::create([
            'stock_id' => $stock->id,
            'trade_date' => $date,
            'open_price' => $close,
            'high_price' => $close,
            'low_price' => $close,
            'close_price' => $close,
            'volume' => 1000,
        ]);

        // sma_20/sma_50/macd/macd_signal held constant across every day so
        // those crossover rules never fire — isolates the RSI/Bollinger
        // rules under test. bb_lower is far below every close so the
        // bullish lower-band rule never fires either.
        TechnicalIndicator::create([
            'stock_id' => $stock->id,
            'trade_date' => $date,
            'sma_20' => 100,
            'sma_50' => 90,
            'macd' => 1,
            'macd_signal' => 0.5,
            'rsi_14' => $rsi,
            'bb_lower' => 50,
            'bb_upper' => $bbUpper,
        ]);
    }

    /**
     * Regression test for the bug this suite was written to catch: a stock
     * sitting overbought (RSI > 70, price at/above the upper Bollinger Band)
     * for several consecutive days must NOT be scored as bearish on every
     * one of those days — that's what made the old level-triggered rules
     * back-test worse than a coin flip (see SignalGeneratorService::score()
     * docblock). It should only count once the extreme actually rolls over.
     */
    public function test_sustained_overbought_does_not_repeatedly_fire_bearish_rules(): void
    {
        $stock = $this->makeStock();

        $this->addDay($stock, '2024-01-01', close: 101, rsi: 75, bbUpper: 100);
        $this->addDay($stock, '2024-01-02', close: 102, rsi: 72, bbUpper: 100);

        $this->generator->generate($stock);

        $day2 = $stock->signals()->where('trade_date', '2024-01-02')->firstOrFail();

        $this->assertSame('hold', $day2->signal);
        $this->assertEquals(0.0, (float) $day2->score);
        $this->assertSame(['No strong signals — indicators are neutral'], $day2->reasons);
    }

    /**
     * Once RSI actually rolls over (drops back through 70) and price is
     * rejected from the upper band on the same day, that confluence should
     * clear the sell bar — this is what re-enables the 'sell' tier that
     * previously never fired for any stock.
     */
    public function test_rsi_rollover_and_band_rejection_together_trigger_sell(): void
    {
        $stock = $this->makeStock();

        $this->addDay($stock, '2024-01-01', close: 101, rsi: 75, bbUpper: 100);
        $this->addDay($stock, '2024-01-02', close: 102, rsi: 72, bbUpper: 100);
        $this->addDay($stock, '2024-01-03', close: 95, rsi: 65, bbUpper: 100);

        $this->generator->generate($stock);

        $day3 = $stock->signals()->where('trade_date', '2024-01-03')->firstOrFail();

        $this->assertSame('sell', $day3->signal);
        $this->assertEqualsWithDelta(-0.3333, (float) $day3->score, 0.0001);
        $this->assertContains('rsi_overbought', $day3->rule_keys);
        $this->assertContains('bb_upper_touch', $day3->rule_keys);
    }

    public function test_rsi_oversold_still_fires_on_every_day_it_holds(): void
    {
        $stock = $this->makeStock();

        // Bullish side is a deliberate level test, not rollover — it
        // already backtests positive, so consecutive oversold days should
        // each still count (unlike the bearish rollover rules above).
        $this->addDay($stock, '2024-01-01', close: 60, rsi: 25, bbUpper: 200);
        $this->addDay($stock, '2024-01-02', close: 61, rsi: 22, bbUpper: 200);

        $this->generator->generate($stock);

        $day2 = $stock->signals()->where('trade_date', '2024-01-02')->firstOrFail();

        $this->assertContains('rsi_oversold', $day2->rule_keys);
    }
}
