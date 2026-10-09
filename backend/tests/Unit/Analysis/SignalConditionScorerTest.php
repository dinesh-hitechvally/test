<?php

namespace Tests\Unit\Analysis;

use App\Models\StockFundamental;
use App\Services\Analysis\Signals\SignalConditionScorer;
use Tests\TestCase;

class SignalConditionScorerTest extends TestCase
{
    private SignalConditionScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = new SignalConditionScorer;
    }

    /** A day's context with sensible blanks; override what a test is about. */
    private function ctx(array $today = [], array $over = []): array
    {
        // A real indicator row has every column, null when not computed.
        $columns = ['sma_20', 'sma_50', 'sma_200', 'rsi_14', 'macd', 'macd_signal', 'macd_histogram', 'stoch_k', 'stoch_d', 'atr_percent',
            'adx_14', 'plus_di_14', 'minus_di_14', 'support_price', 'resistance_price', 'high_52w', 'low_52w', 'volume_ratio'];
        $today = array_merge(array_fill_keys($columns, null), $today);

        return array_merge([
            'today' => (object) $today,
            'yesterday' => null,
            'earlier' => null,
            'close' => null,
            'prev_close' => null,
            'close_10d_ago' => null,
            'percent_b' => null,
            'rule_keys' => [],
            'scoring_keys' => [],
            'dip_suppressed' => false,
            'fundamental' => null,
        ], $over);
    }

    private function find(array $result, string $category, string $key): array
    {
        return collect($result['conditions'][$category])->firstWhere('key', $key);
    }

    public function test_the_weights_are_the_agreed_split_and_add_up_to_100(): void
    {
        $this->assertSame(
            ['technical' => 25, 'fundamental' => 25, 'trend' => 15, 'momentum' => 10, 'volume' => 10, 'risk' => 10, 'valuation' => 5],
            config('signals.weights')
        );
        $this->assertSame(100, array_sum(config('signals.weights')));
    }

    public function test_every_condition_adds_up_to_100_percent(): void
    {
        $result = $this->scorer->evaluate($this->ctx(
            ['rsi_14' => 65, 'macd' => 1, 'macd_signal' => 0.5, 'stoch_k' => 60, 'stoch_d' => 50, 'sma_20' => 100, 'sma_50' => 95, 'sma_200' => 90,
                'macd_histogram' => 0.4, 'volume_ratio' => 1.8, 'atr_percent' => 3, 'adx_14' => 30, 'plus_di_14' => 25, 'minus_di_14' => 10],
            ['close' => 105, 'prev_close' => 103, 'close_10d_ago' => 98, 'percent_b' => 0.7,
                'yesterday' => (object) ['rsi_14' => 60, 'macd_histogram' => 0.2], 'earlier' => (object) ['sma_50' => 92]]
        ));

        $count = 0;
        foreach ($result['conditions'] as $list) {
            foreach ($list as $c) {
                $count++;
                $this->assertEqualsWithDelta(100.0, $c['buy'] + $c['sell'] + $c['hold'], 0.001, $c['key']);
            }
        }
        $this->assertGreaterThan(10, $count);
    }

    public function test_a_category_is_the_average_of_its_conditions(): void
    {
        // Price above MA20 (80/10/10) and below MA50 (10/65/25) -> 45 / 37.5 / 17.5
        $result = $this->scorer->evaluate($this->ctx(['sma_20' => 100, 'sma_50' => 110], ['close' => 105]));

        $this->assertEqualsWithDelta(45.0, $result['categories']['trend']['buy'], 0.01);
        $this->assertEqualsWithDelta(37.5, $result['categories']['trend']['sell'], 0.01);
        $this->assertEqualsWithDelta(17.5, $result['categories']['trend']['hold'], 0.01);
    }

    public function test_the_final_percentages_are_the_weighted_category_averages(): void
    {
        $final = $this->scorer->combine([
            'technical' => ['buy' => 78.0, 'sell' => 8.0, 'hold' => 14.0],
            'fundamental' => ['buy' => 85.0, 'sell' => 5.0, 'hold' => 10.0],
            'trend' => ['buy' => 80.0, 'sell' => 10.0, 'hold' => 10.0],
            'momentum' => ['buy' => 65.0, 'sell' => 15.0, 'hold' => 20.0],
            'volume' => ['buy' => 70.0, 'sell' => 10.0, 'hold' => 20.0],
            'risk' => ['buy' => 55.0, 'sell' => 20.0, 'hold' => 25.0],
            'valuation' => ['buy' => 75.0, 'sell' => 10.0, 'hold' => 15.0],
        ]);

        // The worked example from the spec: 19.50 + 21.25 + 12.00 + 6.50 + 7.00 + 5.50 + 3.75 = 75.50
        $this->assertEqualsWithDelta(75.5, $final['buy'], 0.001);
        $this->assertEqualsWithDelta(100.0, $final['buy'] + $final['sell'] + $final['hold'], 0.05);
    }

    public function test_categories_without_data_are_left_out_and_the_rest_renormalised(): void
    {
        $final = $this->scorer->combine([
            'technical' => ['buy' => 80.0, 'sell' => 10.0, 'hold' => 10.0],
            'fundamental' => null,
            'trend' => ['buy' => 40.0, 'sell' => 30.0, 'hold' => 30.0],
        ]);

        // (80 * 25 + 40 * 15) / 40 = 65
        $this->assertEqualsWithDelta(65.0, $final['buy'], 0.001);
        $this->assertSame(['buy' => 0.0, 'sell' => 0.0, 'hold' => 100.0], $this->scorer->combine([]));
    }

    public function test_the_decision_is_derived_from_the_final_percentages(): void
    {
        $this->assertSame('buy', $this->scorer->decide(['buy' => 50.0, 'sell' => 10.0, 'hold' => 40.0]));
        $this->assertSame('sell', $this->scorer->decide(['buy' => 10.0, 'sell' => 55.0, 'hold' => 35.0]));
        $this->assertSame('hold', $this->scorer->decide(['buy' => 49.9, 'sell' => 30.0, 'hold' => 20.1]));
    }

    public function test_the_decision_threshold_comes_from_config(): void
    {
        config(['signals.decision_min_pct' => 70]);

        $this->assertSame('hold', $this->scorer->decide(['buy' => 60.0, 'sell' => 10.0, 'hold' => 30.0]));
        $this->assertSame('buy', $this->scorer->decide(['buy' => 70.0, 'sell' => 10.0, 'hold' => 20.0]));
    }

    public function test_a_confirmed_dip_is_a_buy_condition_and_an_unconfirmed_one_is_not(): void
    {
        $dip = ['rsi_14' => 25];

        $confirmed = $this->scorer->evaluate($this->ctx($dip, ['rule_keys' => ['rsi_oversold'], 'scoring_keys' => ['rsi_oversold']]));
        $unconfirmed = $this->scorer->evaluate($this->ctx($dip, ['rule_keys' => ['rsi_oversold'], 'dip_suppressed' => true]));

        $this->assertEquals(70.0, $this->find($confirmed, 'technical', 'rsi')['buy']);
        $this->assertEquals(10.0, $this->find($unconfirmed, 'technical', 'rsi')['buy']);
        $this->assertEquals(90.0, $unconfirmed['hold_scores']['wait_confirmation']);
    }

    public function test_fundamental_and_valuation_conditions_exist_only_with_a_snapshot(): void
    {
        $without = $this->scorer->evaluate($this->ctx());
        $with = $this->scorer->evaluate($this->ctx([], ['fundamental' => new StockFundamental(['eps' => 20, 'book_value' => 100, 'pe_ratio' => 8, 'pbv' => 0.8])]));

        $this->assertNull($without['categories']['fundamental']);
        $this->assertNull($without['categories']['valuation']);
        $this->assertEquals(85.0, $this->find($with, 'fundamental', 'roe')['buy']);   // ROE 20%
        $this->assertEquals(80.0, $this->find($with, 'valuation', 'pbv')['buy']);     // below book value
        $this->assertEquals(80.0, $this->find($with, 'valuation', 'pe')['buy']);      // P/E 8
    }

    public function test_a_loss_making_company_scores_bearish_on_fundamentals_and_valuation(): void
    {
        $loss = new StockFundamental(['eps' => -3, 'book_value' => 100, 'pe_ratio' => -20, 'pbv' => 1.5]);
        $result = $this->scorer->evaluate($this->ctx([], ['fundamental' => $loss]));

        $this->assertEquals(80.0, $this->find($result, 'fundamental', 'eps')['sell']);
        $this->assertEquals(80.0, $this->find($result, 'valuation', 'pe')['sell']);
    }

    public function test_volume_confirms_the_direction_of_the_day(): void
    {
        $up = $this->scorer->evaluate($this->ctx(['volume_ratio' => 2.0], ['close' => 105, 'prev_close' => 100]));
        $down = $this->scorer->evaluate($this->ctx(['volume_ratio' => 2.0], ['close' => 95, 'prev_close' => 100]));
        $thin = $this->scorer->evaluate($this->ctx(['volume_ratio' => 0.6], ['close' => 105, 'prev_close' => 100]));

        $this->assertEquals(85.0, $this->find($up, 'volume', 'volume')['buy']);
        $this->assertEquals(85.0, $this->find($down, 'volume', 'volume')['sell']);
        $this->assertGreaterThan(50.0, $this->find($thin, 'volume', 'volume')['hold']); // thin volume says "no conviction"
    }

    public function test_hold_type_is_the_highest_hold_score_and_only_set_for_a_hold(): void
    {
        // Dip-buy waiting for confirmation, in a flat market (neutral overall -> HOLD).
        $hold = $this->scorer->evaluate($this->ctx(['rsi_14' => 25, 'adx_14' => 30], ['rule_keys' => ['rsi_oversold'], 'dip_suppressed' => true]));

        $this->assertSame('hold', $hold['decision']);
        $this->assertSame('wait_confirmation', $hold['hold_type']);
        $this->assertSame(array_keys(SignalConditionScorer::HOLD_TYPES), array_keys($hold['hold_scores']));

        // A strongly bullish day is a BUY and carries no hold type.
        $buy = $this->scorer->evaluate($this->ctx(
            ['rsi_14' => 60, 'macd' => 2, 'macd_signal' => 1, 'sma_20' => 100, 'sma_50' => 95, 'sma_200' => 90, 'macd_histogram' => 1.0, 'volume_ratio' => 2, 'adx_14' => 35, 'plus_di_14' => 30, 'minus_di_14' => 10, 'atr_percent' => 1.5],
            ['close' => 110, 'prev_close' => 105, 'close_10d_ago' => 100, 'percent_b' => 0.8, 'yesterday' => (object) ['rsi_14' => 55, 'macd_histogram' => 0.5], 'earlier' => (object) ['sma_50' => 90]]
        ));
        $this->assertSame('buy', $buy['decision']);
        $this->assertNull($buy['hold_type']);
    }

    public function test_hold_scores_read_the_situation(): void
    {
        // Above its 200-day average but below its 20 and 50 -> temporary weakness; RSI 80 -> overbought; near the 52w high.
        $r = $this->scorer->evaluate($this->ctx(
            ['sma_20' => 110, 'sma_50' => 108, 'sma_200' => 90, 'rsi_14' => 80, 'adx_14' => 10, 'high_52w' => 120, 'low_52w' => 80],
            ['close' => 100]
        ));

        $this->assertEquals(80.0, $r['hold_scores']['temporary_weakness']);
        $this->assertEquals(100.0, $r['hold_scores']['overbought']);
        $this->assertEquals(75.0, $r['hold_scores']['consolidation']);   // 100 - 2.5 * 10
        $this->assertEquals(100.0, $r['hold_scores']['long_term']);      // above and rising MA200 structure
    }

    public function test_the_explanation_names_the_decision_and_all_three_percentages(): void
    {
        $result = $this->scorer->evaluate($this->ctx(['sma_20' => 100, 'sma_50' => 90], ['close' => 105]));
        $line = $this->scorer->explain(['decision' => 'buy'] + $result);

        $this->assertStringStartsWith('Decision BUY — Buy ', $line);
        $this->assertStringContainsString('Sell ', $line);
        $this->assertStringContainsString('Hold ', $line);
    }

    // ------------------------------------------------------------ guards against acting at an extreme

    /** A day where everything reads bearish: below every average, MACD falling, heavy selling. Only the RSI / %B are varied. */
    private function bearishDay(float $rsi, ?float $percentB = 0.3): array
    {
        return $this->ctx(
            ['rsi_14' => $rsi, 'sma_20' => 100, 'sma_50' => 110, 'sma_200' => 130, 'macd' => -2, 'macd_signal' => -1, 'macd_histogram' => -1.5, 'stoch_k' => 10, 'stoch_d' => 20,
                'adx_14' => 35, 'plus_di_14' => 10, 'minus_di_14' => 30, 'atr_percent' => 7, 'volume_ratio' => 2.0],
            ['close' => 85, 'prev_close' => 90, 'close_10d_ago' => 100, 'percent_b' => $percentB,
                'yesterday' => (object) ['rsi_14' => $rsi + 2, 'macd_histogram' => -1.0], 'earlier' => (object) ['sma_50' => 118]]
        );
    }

    private function bullishDay(float $rsi, ?float $percentB = 0.7): array
    {
        return $this->ctx(
            ['rsi_14' => $rsi, 'sma_20' => 100, 'sma_50' => 95, 'sma_200' => 80, 'macd' => 2, 'macd_signal' => 1, 'macd_histogram' => 1.5, 'stoch_k' => 70, 'stoch_d' => 60,
                'adx_14' => 35, 'plus_di_14' => 30, 'minus_di_14' => 10, 'atr_percent' => 1.5, 'volume_ratio' => 2.0],
            ['close' => 110, 'prev_close' => 105, 'close_10d_ago' => 98, 'percent_b' => $percentB,
                'yesterday' => (object) ['rsi_14' => $rsi - 2, 'macd_histogram' => 1.0], 'earlier' => (object) ['sma_50' => 90]]
        );
    }

    public function test_a_sell_on_an_oversold_price_is_held_back_as_a_hold(): void
    {
        config(['signals.guard_extremes' => false]);
        $unguarded = $this->scorer->evaluate($this->bearishDay(rsi: 25));
        $this->assertSame('sell', $unguarded['decision']); // the trend, momentum and volume all say sell

        config(['signals.guard_extremes' => true]);
        $guarded = $this->scorer->evaluate($this->bearishDay(rsi: 25));

        $this->assertSame('hold', $guarded['decision']);
        $this->assertStringContainsString('Sell held back', $guarded['guard']);
        $this->assertStringContainsString('selling the low', $guarded['guard']);
        $this->assertSame('wait_confirmation', $guarded['hold_type']); // waiting for the bounce
        $this->assertEquals($unguarded['final'], $guarded['final']);      // the percentages are untouched: only the action changes
    }

    public function test_the_lower_bollinger_band_counts_as_oversold_too(): void
    {
        config(['signals.guard_extremes' => true]);

        $result = $this->scorer->evaluate($this->bearishDay(rsi: 40, percentB: -0.1));

        $this->assertSame('hold', $result['decision']);
        $this->assertStringContainsString('lower Bollinger band', $result['guard']);
    }

    public function test_a_sell_that_is_not_oversold_is_left_alone(): void
    {
        config(['signals.guard_extremes' => true]);

        $result = $this->scorer->evaluate($this->bearishDay(rsi: 40));

        $this->assertSame('sell', $result['decision']);
        $this->assertNull($result['guard']);
        $this->assertNull($result['hold_type']);
    }

    public function test_a_buy_on_an_overbought_price_is_held_back_as_a_hold(): void
    {
        config(['signals.guard_extremes' => false]);
        $this->assertSame('buy', $this->scorer->evaluate($this->bullishDay(rsi: 78))['decision']);

        config(['signals.guard_extremes' => true]);
        $guarded = $this->scorer->evaluate($this->bullishDay(rsi: 78));

        $this->assertSame('hold', $guarded['decision']);
        $this->assertStringContainsString('Buy held back', $guarded['guard']);
        $this->assertStringContainsString('buying the high', $guarded['guard']);
        $this->assertSame('overbought', $guarded['hold_type']);

        $this->assertSame('buy', $this->scorer->evaluate($this->bullishDay(rsi: 60))['decision']); // not overbought: still a buy
    }

    public function test_the_guard_can_be_switched_off(): void
    {
        config(['signals.guard_extremes' => false]);

        $this->assertSame('sell', $this->scorer->evaluate($this->bearishDay(rsi: 25))['decision']);
        $this->assertSame('buy', $this->scorer->evaluate($this->bullishDay(rsi: 78))['decision']);
    }

    public function test_the_winning_side_must_also_lead_by_the_margin(): void
    {
        config(['signals.decision_margin' => 0]);
        $this->assertSame('buy', $this->scorer->decide(['buy' => 52.0, 'sell' => 45.0, 'hold' => 3.0]));

        config(['signals.decision_margin' => 10]);
        $this->assertSame('hold', $this->scorer->decide(['buy' => 52.0, 'sell' => 45.0, 'hold' => 3.0]));
        $this->assertSame('buy', $this->scorer->decide(['buy' => 60.0, 'sell' => 30.0, 'hold' => 10.0]));
        $this->assertSame('sell', $this->scorer->decide(['buy' => 20.0, 'sell' => 62.0, 'hold' => 18.0]));
    }

    public function test_a_stretched_price_counts_in_the_risk_category_when_switched_on(): void
    {
        $above = $this->ctx(['sma_50' => 100], ['close' => 135]);
        $below = $this->ctx(['sma_50' => 100], ['close' => 70]);
        $normal = $this->ctx(['sma_50' => 100], ['close' => 105]);

        config(['signals.stretch_pct' => null]);
        $this->assertNull(collect($this->scorer->evaluate($above)['conditions']['risk'])->firstWhere('key', 'stretch'));

        config(['signals.stretch_pct' => 25]);
        $up = collect($this->scorer->evaluate($above)['conditions']['risk'])->firstWhere('key', 'stretch');
        $down = collect($this->scorer->evaluate($below)['conditions']['risk'])->firstWhere('key', 'stretch');

        $this->assertSame([10.0, 45.0], [$up['buy'], $up['sell']]);     // far above the average: against buying the high
        $this->assertSame([40.0, 15.0], [$down['buy'], $down['sell']]); // far below: against selling the low
        $this->assertNull(collect($this->scorer->evaluate($normal)['conditions']['risk'])->firstWhere('key', 'stretch'));
    }
}
