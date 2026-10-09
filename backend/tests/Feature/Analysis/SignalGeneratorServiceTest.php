<?php

namespace Tests\Feature\Analysis;

use App\Models\DailyPrice;
use App\Models\SignalBreakdown;
use App\Models\Stock;
use App\Models\StockFundamental;
use App\Models\TechnicalIndicator;
use App\Services\Analysis\Signals\SignalConditionScorer;
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

    private function makeStock(string $symbol = 'TEST'): Stock
    {
        return Stock::create(['symbol' => $symbol, 'company_name' => 'Test Co', 'is_active' => true]);
    }

    /** The percentage breakdown stored for a day. */
    private function breakdown(Stock $stock, string $date): SignalBreakdown
    {
        return $stock->signals()->where('trade_date', $date)->firstOrFail()->breakdown;
    }

    /** One stored condition (latest day only), e.g. condition($stock, $date, 'technical', 'rsi'). */
    private function condition(Stock $stock, string $date, string $category, string $key): array
    {
        $found = collect($this->breakdown($stock, $date)->conditions[$category] ?? [])->firstWhere('key', $key);
        $this->assertNotNull($found, "no {$category}/{$key} condition stored");

        return $found;
    }

    private function addDay(Stock $stock, string $date, float $close, float $rsi, float $bbUpper, ?float $sma200 = null, ?float $volumeRatio = null, float $sma50 = 90): void
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
            'sma_50' => $sma50,
            'macd' => 1,
            'macd_signal' => 0.5,
            'rsi_14' => $rsi,
            'bb_lower' => 50,
            'bb_upper' => $bbUpper,
            'sma_200' => $sma200,
            'volume_ratio' => $volumeRatio,
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

        // Sitting overbought is not a bearish rule firing: nothing is recorded, and RSI reads as plain "overbought".
        $this->assertSame([], $day2->rule_keys);
        $this->assertStringContainsString('overbought', $this->condition($stock, '2024-01-02', 'technical', 'rsi')['result']);
        $this->assertEquals(25.0, $this->condition($stock, '2024-01-02', 'technical', 'rsi')['sell']);
    }

    /**
     * Once RSI actually rolls over (drops back through 70) and price is
     * rejected from the upper band on the same day, both bearish rules agree:
     * each condition leans strongly to SELL.
     */
    public function test_rsi_rollover_and_band_rejection_both_score_as_sell_conditions(): void
    {
        $stock = $this->makeStock();

        $this->addDay($stock, '2024-01-01', close: 101, rsi: 75, bbUpper: 100, sma200: 120);
        $this->addDay($stock, '2024-01-02', close: 102, rsi: 72, bbUpper: 100, sma200: 120);
        $this->addDay($stock, '2024-01-03', close: 95, rsi: 65, bbUpper: 100, sma200: 120);

        $this->generator->generate($stock);

        $day3 = $stock->signals()->where('trade_date', '2024-01-03')->firstOrFail();

        $this->assertContains('rsi_overbought', $day3->rule_keys);
        $this->assertContains('bb_upper_touch', $day3->rule_keys);
        $this->assertEquals(65.0, $this->condition($stock, '2024-01-03', 'technical', 'rsi')['sell']);
        $this->assertEquals(65.0, $this->condition($stock, '2024-01-03', 'technical', 'bollinger')['sell']);
    }

    /**
     * The same bearish setup reads the same in the Technical category wherever the
     * price is, but the Trend category tells an uptrend (a pause) from a downtrend
     * (a top), so the final SELL % is lower above SMA200.
     */
    public function test_the_same_bearish_setup_scores_better_above_sma200_than_below(): void
    {
        $stock = $this->makeStock();

        $this->addDay($stock, '2024-01-01', close: 101, rsi: 75, bbUpper: 100, sma200: 80);
        $this->addDay($stock, '2024-01-02', close: 102, rsi: 72, bbUpper: 100, sma200: 80);
        $this->addDay($stock, '2024-01-03', close: 95, rsi: 65, bbUpper: 100, sma200: 80);

        $below = $this->makeStock('BELOW');
        $this->addDay($below, '2024-01-01', close: 101, rsi: 75, bbUpper: 100, sma200: 120);
        $this->addDay($below, '2024-01-02', close: 102, rsi: 72, bbUpper: 100, sma200: 120);
        $this->addDay($below, '2024-01-03', close: 95, rsi: 65, bbUpper: 100, sma200: 120);

        $this->generator->generate($stock);
        $this->generator->generate($below);

        $above = $stock->signals()->where('trade_date', '2024-01-03')->firstOrFail();
        $under = $below->signals()->where('trade_date', '2024-01-03')->firstOrFail();

        $this->assertContains('rsi_overbought', $above->rule_keys);
        $this->assertGreaterThan($above->breakdown->category_scores['trend']['sell'], $under->breakdown->category_scores['trend']['sell']);
        $this->assertGreaterThan($above->breakdown->sell_pct, $under->breakdown->sell_pct);
    }

    public function test_an_oversold_reading_alone_is_not_a_buy_condition(): void
    {
        $stock = $this->makeStock();

        // Oversold, but nothing says it has turned: no prior day, no volume.
        $this->addDay($stock, '2024-01-01', close: 60, rsi: 25, bbUpper: 200);

        $this->generator->generate($stock);

        $day1 = $stock->signals()->where('trade_date', '2024-01-01')->firstOrFail();

        $rsi = $this->condition($stock, '2024-01-01', 'technical', 'rsi');
        $this->assertStringContainsString('no bounce yet', $rsi['result']);
        $this->assertEquals(10.0, $rsi['buy']); // does not vote buy
        $this->assertEquals(90.0, $this->breakdown($stock, '2024-01-01')->hold_scores['wait_confirmation']);
        $this->assertContains('rsi_oversold', $day1->rule_keys); // still recorded for the scanner
        $this->assertStringContainsString('no bounce yet', implode(' ', $day1->reasons));
    }

    public function test_an_oversold_reading_with_a_bounce_is_a_buy_condition(): void
    {
        $stock = $this->makeStock();

        $this->addDay($stock, '2024-01-01', close: 60, rsi: 25, bbUpper: 200, volumeRatio: 1.3);

        $this->generator->generate($stock);

        $day1 = $stock->signals()->where('trade_date', '2024-01-01')->firstOrFail();

        $this->assertEquals(70.0, $this->condition($stock, '2024-01-01', 'technical', 'rsi')['buy']);
        $this->assertLessThan(90.0, $this->breakdown($stock, '2024-01-01')->hold_scores['wait_confirmation']);
        $this->assertStringContainsString('Dip-buy confirmed', implode(' ', $day1->reasons));
    }

    public function test_a_dip_in_an_uptrend_needs_no_extra_confirmation(): void
    {
        $stock = $this->makeStock();

        // 11 days with the 50-day average rising, price above both averages, then a one-day oversold dip.
        for ($i = 0; $i < 11; $i++) {
            $this->addDay($stock, now()->setDate(2024, 1, 1)->addDays($i)->toDateString(), close: 120, rsi: 50, bbUpper: 300, sma200: 80, sma50: 90 + $i);
        }
        $this->addDay($stock, '2024-01-12', close: 119, rsi: 25, bbUpper: 300, sma200: 80, sma50: 101);

        $this->generator->generate($stock);

        $day = $stock->signals()->where('trade_date', '2024-01-12')->firstOrFail();

        $this->assertEquals(70.0, $this->condition($stock, '2024-01-12', 'technical', 'rsi')['buy']);
        $this->assertStringNotContainsString('no bounce yet', implode(' ', $day->reasons));
    }

    public function test_a_dip_in_a_downtrend_needs_two_bounce_signs(): void
    {
        $stock = $this->makeStock();

        // 50-day average falling, price below both averages.
        for ($i = 0; $i < 11; $i++) {
            $this->addDay($stock, now()->setDate(2024, 1, 1)->addDays($i)->toDateString(), close: 60, rsi: 40, bbUpper: 200, sma200: 120, sma50: 110 - $i);
        }
        // One sign only (volume): not enough in a downtrend.
        $this->addDay($stock, '2024-01-12', close: 59, rsi: 25, bbUpper: 200, sma200: 120, volumeRatio: 1.5, sma50: 99);
        // Close up + volume: two signs.
        $this->addDay($stock, '2024-01-13', close: 61, rsi: 24, bbUpper: 200, sma200: 120, volumeRatio: 1.5, sma50: 98);

        $this->generator->generate($stock);

        $one = $stock->signals()->where('trade_date', '2024-01-12')->firstOrFail();

        $this->assertStringContainsString('no bounce yet', implode(' ', $one->reasons));
        $this->assertContains('rsi_oversold', $one->rule_keys);
        $this->assertEquals(70.0, $this->condition($stock, '2024-01-13', 'technical', 'rsi')['buy']);
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

    private function addStochDay(Stock $stock, string $date, float $k, float $d, float $rsi = 50, ?float $volumeRatio = null): void
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
            'volume_ratio' => $volumeRatio,
        ]);
    }

    public function test_stochastic_bullish_cross_is_recorded_and_scored_as_its_own_condition(): void
    {
        $stock = $this->makeStock();

        $this->addStochDay($stock, '2024-01-01', k: 10, d: 14);
        $this->addStochDay($stock, '2024-01-02', k: 18, d: 15);

        $this->generator->generate($stock);

        $day2 = $stock->signals()->where('trade_date', '2024-01-02')->firstOrFail();

        $this->assertSame(['stoch_bull_cross'], $day2->rule_keys);
        $this->assertEquals(75.0, $this->condition($stock, '2024-01-02', 'technical', 'stochastic')['buy']);
    }

    public function test_stochastic_and_rsi_are_scored_as_separate_conditions(): void
    {
        $stock = $this->makeStock();

        $this->addStochDay($stock, '2024-01-01', k: 10, d: 14, rsi: 28);
        $this->addStochDay($stock, '2024-01-02', k: 18, d: 15, rsi: 27, volumeRatio: 1.2);

        $this->generator->generate($stock);

        $day2 = $stock->signals()->where('trade_date', '2024-01-02')->firstOrFail();

        // Each indicator is its own condition; stochastic does not change what RSI says.
        $this->assertEquals(70.0, $this->condition($stock, '2024-01-02', 'technical', 'rsi')['buy']);
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

    public function test_the_decision_is_buy_sell_or_hold_and_the_breakdown_adds_up(): void
    {
        $stock = $this->makeStock();

        $this->addDay($stock, '2024-01-01', close: 101, rsi: 75, bbUpper: 100, sma200: 120);
        $this->addDay($stock, '2024-01-02', close: 60, rsi: 25, bbUpper: 200, volumeRatio: 1.3);
        $this->addDay($stock, '2024-01-03', close: 95, rsi: 65, bbUpper: 100, sma200: 120);

        $this->generator->generate($stock);

        foreach ($stock->signals as $signal) {
            $b = $signal->breakdown;

            $this->assertContains($signal->signal, ['buy', 'sell', 'hold']);
            $this->assertEqualsWithDelta(100.0, $b->buy_pct + $b->sell_pct + $b->hold_pct, 0.05);
            $this->assertEqualsWithDelta(($b->buy_pct - $b->sell_pct) / 100, (float) $signal->score, 0.0001);
            // The decision follows the percentages, never the other way round.
            $expected = match (true) {
                $b->buy_pct >= 50 && $b->buy_pct - $b->sell_pct >= 10 => 'buy',
                $b->sell_pct >= 50 && $b->sell_pct - $b->buy_pct >= 10 => 'sell',
                default => 'hold',
            };
            // ...unless the entry guard held a buy/sell back at an extreme, which is a hold with its reason.
            $held = $expected !== 'hold' && $signal->signal === 'hold' && str_contains(implode(' ', $signal->reasons ?? []), 'held back');
            $this->assertSame($held ? 'hold' : $expected, $signal->signal);
            $this->assertSame($signal->signal === 'hold', $b->hold_type !== null);
        }

        // The category detail is there for the latest day.
        $this->assertEqualsCanonicalizing(SignalConditionScorer::CATEGORIES, array_keys($this->breakdown($stock, '2024-01-03')->category_scores));
    }

    public function test_every_day_keeps_its_full_breakdown(): void
    {
        $stock = $this->makeStock();

        $this->addDay($stock, '2024-01-01', close: 100, rsi: 50, bbUpper: 200);
        $this->addDay($stock, '2024-01-02', close: 100, rsi: 50, bbUpper: 200);

        $this->generator->generate($stock);

        $old = $this->breakdown($stock, '2024-01-01');
        $new = $this->breakdown($stock, '2024-01-02');

        foreach ([$old, $new] as $day) {
            $this->assertEqualsWithDelta(100.0, $day->buy_pct + $day->sell_pct + $day->hold_pct, 0.05);
            $this->assertNotNull($day->conditions);
            $this->assertNotNull($day->category_scores);
            $this->assertNotNull($day->hold_scores);
        }
    }

    public function test_fundamental_and_valuation_only_apply_to_the_latest_day(): void
    {
        $stock = $this->makeStock();
        StockFundamental::create(['stock_id' => $stock->id, 'eps' => 20, 'book_value' => 100, 'pe_ratio' => 8, 'pbv' => 0.8]);

        $this->addDay($stock, '2024-01-01', close: 100, rsi: 50, bbUpper: 200);
        $this->addDay($stock, '2024-01-02', close: 100, rsi: 50, bbUpper: 200);

        $this->generator->generate($stock);

        $first = $this->breakdown($stock, '2024-01-01');
        $last = $this->breakdown($stock, '2024-01-02');

        // Earlier days never had the fundamentals applied: their weights are simply absent from the average.
        $this->assertNull($first->category_scores['fundamental']);
        $this->assertNull($first->category_scores['valuation']);
        $this->assertSame([], $first->conditions['fundamental']); // no fundamental conditions on a past day
        $this->assertGreaterThan(70, $last->category_scores['fundamental']['buy']);
        $this->assertGreaterThan(70, $last->category_scores['valuation']['buy']);
        $this->assertContains('valuation_undervalued', $stock->signals()->where('trade_date', '2024-01-02')->first()->rule_keys);
    }

    public function test_regenerating_replaces_the_breakdown_instead_of_duplicating_it(): void
    {
        $stock = $this->makeStock();
        $this->addDay($stock, '2024-01-01', close: 100, rsi: 50, bbUpper: 200);

        $this->generator->generate($stock);
        $this->generator->generate($stock);

        $this->assertSame(1, SignalBreakdown::count());
    }

    public function test_each_category_has_its_own_buy_sell_hold_columns(): void
    {
        $stock = $this->makeStock();
        StockFundamental::create(['stock_id' => $stock->id, 'eps' => 20, 'book_value' => 100, 'pe_ratio' => 8, 'pbv' => 0.8]);
        $this->addDay($stock, '2024-01-01', close: 100, rsi: 50, bbUpper: 200);
        $this->addDay($stock, '2024-01-02', close: 100, rsi: 50, bbUpper: 200);

        $this->generator->generate($stock);

        $past = $this->breakdown($stock, '2024-01-01');
        $latest = $this->breakdown($stock, '2024-01-02');

        foreach (SignalConditionScorer::CATEGORIES as $category) {
            $this->assertSame($latest->category_scores[$category]['buy'] ?? null, $latest->{$category.'_buy'});
        }

        // A category with data fills all three columns and they add up to 100.
        $this->assertEqualsWithDelta(100.0, $past->technical_buy + $past->technical_sell + $past->technical_hold, 0.05);
        // A category with no data that day is null in all three (fundamental / valuation on a past day).
        $this->assertNull($past->fundamental_buy);
        $this->assertNull($past->fundamental_sell);
        $this->assertNull($past->fundamental_hold);
        $this->assertNull($past->valuation_buy);
        // ...and filled on the latest day.
        $this->assertNotNull($latest->fundamental_buy);
        $this->assertNotNull($latest->valuation_hold);
    }
}
