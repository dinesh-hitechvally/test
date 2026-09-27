<?php

namespace Tests\Feature\Services;

use App\Models\DailyPrice;
use App\Models\Stock;
use App\Models\TechnicalIndicator;
use App\Services\Analysis\Signals\SignalGeneratorService;
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

    private function addDay(Stock $stock, string $date, float $close, float $rsi, float $bbUpper, ?float $sma200 = null): void
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
            'sma_200' => $sma200,
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
     * rejected from the upper band on the same day — with the stock
     * already below its SMA200 — both bearish rules agree: strong sell.
     */
    public function test_rsi_rollover_and_band_rejection_below_sma200_is_a_strong_sell(): void
    {
        $stock = $this->makeStock();

        $this->addDay($stock, '2024-01-01', close: 101, rsi: 75, bbUpper: 100, sma200: 120);
        $this->addDay($stock, '2024-01-02', close: 102, rsi: 72, bbUpper: 100, sma200: 120);
        $this->addDay($stock, '2024-01-03', close: 95, rsi: 65, bbUpper: 100, sma200: 120);

        $this->generator->generate($stock);

        $day3 = $stock->signals()->where('trade_date', '2024-01-03')->firstOrFail();

        $this->assertSame('strong_sell', $day3->signal);
        $this->assertEqualsWithDelta(-1.0, (float) $day3->score, 0.0001);
        $this->assertContains('rsi_overbought', $day3->rule_keys);
        $this->assertContains('bb_upper_touch', $day3->rule_keys);
    }

    /**
     * The same bearish setup inside an uptrend (close above SMA200) is only
     * a pause, not a top — backtesting found sells there lost to baseline,
     * so it stays hold. The rules are still recorded as context.
     */
    public function test_bearish_rules_above_sma200_stay_hold(): void
    {
        $stock = $this->makeStock();

        $this->addDay($stock, '2024-01-01', close: 101, rsi: 75, bbUpper: 100, sma200: 80);
        $this->addDay($stock, '2024-01-02', close: 102, rsi: 72, bbUpper: 100, sma200: 80);
        $this->addDay($stock, '2024-01-03', close: 95, rsi: 65, bbUpper: 100, sma200: 80);

        $this->generator->generate($stock);

        $day3 = $stock->signals()->where('trade_date', '2024-01-03')->firstOrFail();

        $this->assertSame('hold', $day3->signal);
        $this->assertEqualsWithDelta(-1.0, (float) $day3->score, 0.0001);
        $this->assertContains('rsi_overbought', $day3->rule_keys);
    }

    public function test_a_single_bullish_mean_reversion_rule_is_a_buy(): void
    {
        $stock = $this->makeStock();

        $this->addDay($stock, '2024-01-01', close: 60, rsi: 25, bbUpper: 200);

        $this->generator->generate($stock);

        $day1 = $stock->signals()->where('trade_date', '2024-01-01')->firstOrFail();

        $this->assertSame('buy', $day1->signal);
        $this->assertEqualsWithDelta(0.5, (float) $day1->score, 0.0001);
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

    private function addStochDay(Stock $stock, string $date, float $k, float $d, float $rsi = 50): void
    {
        DailyPrice::create([
            'stock_id' => $stock->id,
            'trade_date' => $date,
            'open_price' => 100,
            'high_price' => 100,
            'low_price' => 100,
            'close_price' => 100,
            'volume' => 1000,
        ]);

        // Same isolation as addDay(), with %B held mid-band (0.5) so
        // neither Bollinger rule fires — only the stochastic (and, when
        // asked for, RSI) rules are in play.
        TechnicalIndicator::create([
            'stock_id' => $stock->id,
            'trade_date' => $date,
            'sma_20' => 100,
            'sma_50' => 90,
            'macd' => 1,
            'macd_signal' => 0.5,
            'rsi_14' => $rsi,
            'bb_lower' => 50,
            'bb_upper' => 150,
            'stoch_k' => $k,
            'stoch_d' => $d,
        ]);
    }

    public function test_stochastic_bullish_cross_is_recorded_but_carries_no_weight(): void
    {
        $stock = $this->makeStock();

        $this->addStochDay($stock, '2024-01-01', k: 10, d: 14);
        $this->addStochDay($stock, '2024-01-02', k: 18, d: 15);

        $this->generator->generate($stock);

        $day2 = $stock->signals()->where('trade_date', '2024-01-02')->firstOrFail();

        // Context only (weight 0 — it backtested below baseline).
        $this->assertSame(['stoch_bull_cross'], $day2->rule_keys);
        $this->assertSame('hold', $day2->signal);
        $this->assertEquals(0.0, (float) $day2->score);
    }

    public function test_stochastic_does_not_add_to_an_rsi_oversold_buy(): void
    {
        $stock = $this->makeStock();

        $this->addStochDay($stock, '2024-01-01', k: 10, d: 14, rsi: 28);
        $this->addStochDay($stock, '2024-01-02', k: 18, d: 15, rsi: 27);

        $this->generator->generate($stock);

        $day2 = $stock->signals()->where('trade_date', '2024-01-02')->firstOrFail();

        $this->assertSame('buy', $day2->signal);
        $this->assertEqualsWithDelta(0.5, (float) $day2->score, 0.0001); // RSI alone, not RSI + stochastic
        $this->assertEqualsCanonicalizing(['rsi_oversold', 'stoch_bull_cross'], $day2->rule_keys);
    }

    public function test_stochastic_bearish_cross_in_overbought_zone_fires(): void
    {
        $stock = $this->makeStock();

        $this->addStochDay($stock, '2024-01-01', k: 92, d: 88);
        $this->addStochDay($stock, '2024-01-02', k: 84, d: 87);

        $this->generator->generate($stock);

        $day2 = $stock->signals()->where('trade_date', '2024-01-02')->firstOrFail();

        $this->assertSame(['stoch_bear_cross'], $day2->rule_keys);
    }

    public function test_stochastic_cross_in_the_middle_zone_is_ignored(): void
    {
        $stock = $this->makeStock();

        $this->addStochDay($stock, '2024-01-01', k: 45, d: 50);
        $this->addStochDay($stock, '2024-01-02', k: 55, d: 51);

        $this->generator->generate($stock);

        $day2 = $stock->signals()->where('trade_date', '2024-01-02')->firstOrFail();

        $this->assertSame([], $day2->rule_keys);
    }
}
