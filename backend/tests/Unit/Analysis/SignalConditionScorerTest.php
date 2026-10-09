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

    public function test_the_indicator_weights_add_up_to_100_and_every_indicator_is_made_of_known_conditions(): void
    {
        $weights = array_column(config('signals.indicators'), 'weight');

        $this->assertSame(100, array_sum($weights));
        $this->assertEqualsCanonicalizing(array_keys(config('signals.indicators')), array_keys(SignalConditionScorer::INDICATORS));
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

    public function test_the_final_percentages_are_the_indicators_weighted(): void
    {
        // RSI 65 (bullish zone: 50 buy), MACD above its signal (65 buy): weights 11 and 11 -> (50*11 + 65*11) / 22
        $result = $this->scorer->evaluate($this->ctx(['rsi_14' => 65, 'macd' => 1, 'macd_signal' => 0.5]));

        $this->assertEqualsWithDelta((50 * 11 + 65 * 11) / 22, $result['final']['buy'], 0.01);
        $this->assertEqualsWithDelta(100.0, array_sum($result['final']), 0.05);
    }

    public function test_every_condition_carries_its_share_of_the_final_result(): void
    {
        $result = $this->scorer->evaluate($this->ctx(['rsi_14' => 65, 'macd' => 1, 'macd_signal' => 0.5]));

        $this->assertEqualsWithDelta(11 / 22 * 100, $this->find($result, 'technical', 'rsi')['weight'], 0.01);
        $this->assertEqualsWithDelta(11 / 22 * 100, $this->find($result, 'technical', 'macd')['weight'], 0.01);
        $this->assertEqualsWithDelta(100.0, collect($result['conditions'])->flatten(1)->sum('weight'), 0.05);
    }

    public function test_an_indicator_with_several_conditions_averages_them_and_splits_its_weight(): void
    {
        // MA50 = price vs MA50 (above: 80 buy) and its 10-session slope (falling: 10 buy) -> 45 buy.
        $result = $this->scorer->evaluate($this->ctx(['sma_50' => 100], ['close' => 105, 'earlier' => (object) ['sma_50' => 110]]));

        $this->assertEqualsWithDelta(45.0, $result['final']['buy'], 0.01);
        $this->assertEqualsWithDelta(50.0, $this->find($result, 'trend', 'above_ma50')['weight'], 0.01);
        $this->assertEqualsWithDelta(50.0, $this->find($result, 'trend', 'ma50_slope')['weight'], 0.01);
    }

    public function test_indicators_without_data_are_left_out_and_the_rest_renormalised(): void
    {
        $result = $this->scorer->evaluate($this->ctx(['rsi_14' => 65]));

        // Only RSI has data, so it carries the whole result.
        $this->assertEqualsWithDelta(50.0, $result['final']['buy'], 0.01);
        $this->assertEqualsWithDelta(100.0, $this->find($result, 'technical', 'rsi')['weight'], 0.01);
        $this->assertSame(['buy' => 0.0, 'sell' => 0.0, 'hold' => 100.0], $this->scorer->evaluate($this->ctx())['final']);
    }

    public function test_a_switched_off_indicator_is_left_out(): void
    {
        config(['signals.indicators.macd.enabled' => false]);

        $result = $this->scorer->evaluate($this->ctx(['rsi_14' => 65, 'macd' => 1, 'macd_signal' => 0.5]));

        $this->assertEqualsWithDelta(50.0, $result['final']['buy'], 0.01); // RSI alone
        $this->assertSame(0.0, $this->find($result, 'technical', 'macd')['weight']);
    }

    public function test_related_indicators_cannot_add_up_to_more_than_twice_the_heaviest(): void
    {
        // The MA family (ma20 8, ma50 9, ma200 5, golden/death 2 = 24) is capped at 18; RSI keeps its 11.
        $ctx = $this->ctx(['rsi_14' => 65, 'sma_20' => 100, 'sma_50' => 95, 'sma_200' => 90], ['close' => 105, 'earlier' => (object) ['sma_50' => 90]]);
        $result = $this->scorer->evaluate($ctx);

        $family = collect(['above_ma20', 'ma_cross', 'above_ma50', 'ma50_slope', 'above_ma200', 'ma50_ma200'])
            ->sum(fn ($k) => collect($result['conditions'])->flatten(1)->firstWhere('key', $k)['weight']);

        $this->assertEqualsWithDelta(18 / 29 * 100, $family, 0.05); // 18 of the 29 total weight present

        config(['signals.correlation.enabled' => false]);
        $uncapped = $this->scorer->evaluate($ctx);
        $familyUncapped = collect(['above_ma20', 'ma_cross', 'above_ma50', 'ma50_slope', 'above_ma200', 'ma50_ma200'])
            ->sum(fn ($k) => collect($uncapped['conditions'])->flatten(1)->firstWhere('key', $k)['weight']);
        $this->assertGreaterThan($family, $familyUncapped);
    }

    public function test_the_decision_is_derived_from_the_final_percentages(): void
    {
        $this->assertSame('buy', $this->scorer->decide(['buy' => 60.0, 'sell' => 10.0, 'hold' => 30.0]));
        $this->assertSame('sell', $this->scorer->decide(['buy' => 10.0, 'sell' => 55.0, 'hold' => 35.0]));
        $this->assertSame('hold', $this->scorer->decide(['buy' => 49.9, 'sell' => 30.0, 'hold' => 20.1]));
    }

    public function test_the_winning_side_must_also_lead_by_the_margin(): void
    {
        $this->assertSame('hold', $this->scorer->decide(['buy' => 52.0, 'sell' => 45.0, 'hold' => 3.0]));  // leads by 7, needs 10
        $this->assertSame('buy', $this->scorer->decide(['buy' => 55.0, 'sell' => 45.0, 'hold' => 0.0]));   // exactly 10
        $this->assertSame('sell', $this->scorer->decide(['buy' => 20.0, 'sell' => 62.0, 'hold' => 18.0]));

        config(['signals.decision.minimum_margin_pct' => 0]);
        $this->assertSame('buy', $this->scorer->decide(['buy' => 52.0, 'sell' => 45.0, 'hold' => 3.0]));
    }

    public function test_the_minimum_percentages_come_from_config(): void
    {
        config(['signals.decision.minimum_buy_pct' => 70, 'signals.decision.minimum_sell_pct' => 60]);

        $this->assertSame('hold', $this->scorer->decide(['buy' => 65.0, 'sell' => 10.0, 'hold' => 25.0]));
        $this->assertSame('buy', $this->scorer->decide(['buy' => 70.0, 'sell' => 10.0, 'hold' => 20.0]));
        $this->assertSame('hold', $this->scorer->decide(['buy' => 10.0, 'sell' => 55.0, 'hold' => 35.0]));
        $this->assertSame('sell', $this->scorer->decide(['buy' => 10.0, 'sell' => 60.0, 'hold' => 30.0]));
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

    /** The blocking tests turn the trend confirmation off, so they show the block itself; its own tests are below. */
    private function noConfirmation(): void
    {
        config(['signals.guards.require_reversal_confirmation' => false]);
    }

    public function test_a_sell_on_an_oversold_price_is_held_back_as_a_hold(): void
    {
        $this->noConfirmation();
        config(['signals.guards.enabled' => false]);
        $unguarded = $this->scorer->evaluate($this->bearishDay(rsi: 25));
        $this->assertSame('sell', $unguarded['decision']); // the trend, momentum and volume all say sell

        config(['signals.guards.enabled' => true]);
        $guarded = $this->scorer->evaluate($this->bearishDay(rsi: 25));

        $this->assertSame('hold', $guarded['decision']);
        $this->assertStringContainsString('Sell held back', $guarded['guard']);
        $this->assertStringContainsString('selling the low', $guarded['guard']);
        $this->assertSame('wait_confirmation', $guarded['hold_type']); // waiting for the bounce
        $this->assertEquals($unguarded['final'], $guarded['final']);      // the percentages are untouched: only the action changes
    }

    public function test_a_sell_that_is_not_oversold_is_left_alone(): void
    {
        $this->noConfirmation();

        $result = $this->scorer->evaluate($this->bearishDay(rsi: 40));

        $this->assertSame('sell', $result['decision']);
        $this->assertNull($result['guard']);
        $this->assertNull($result['hold_type']);
    }

    public function test_a_buy_on_an_overbought_price_is_held_back_as_a_hold(): void
    {
        $this->noConfirmation();
        config(['signals.guards.enabled' => false]);
        $this->assertSame('buy', $this->scorer->evaluate($this->bullishDay(rsi: 78))['decision']);

        config(['signals.guards.enabled' => true]);
        $guarded = $this->scorer->evaluate($this->bullishDay(rsi: 78));

        $this->assertSame('hold', $guarded['decision']);
        $this->assertStringContainsString('Buy held back', $guarded['guard']);
        $this->assertStringContainsString('buying the high', $guarded['guard']);
        $this->assertSame('overbought', $guarded['hold_type']);

        $this->assertSame('buy', $this->scorer->evaluate($this->bullishDay(rsi: 60))['decision']); // not overbought: still a buy
    }

    public function test_each_side_of_the_guard_can_be_switched_off_on_its_own(): void
    {
        $this->noConfirmation();

        config(['signals.guards.block_sell_when_oversold' => false]);
        $this->assertSame('sell', $this->scorer->evaluate($this->bearishDay(rsi: 25))['decision']);
        $this->assertSame('hold', $this->scorer->evaluate($this->bullishDay(rsi: 78))['decision']);

        config(['signals.guards.block_sell_when_oversold' => true, 'signals.guards.block_buy_when_overbought' => false]);
        $this->assertSame('hold', $this->scorer->evaluate($this->bearishDay(rsi: 25))['decision']);
        $this->assertSame('buy', $this->scorer->evaluate($this->bullishDay(rsi: 78))['decision']);
    }

    public function test_the_rsi_limits_come_from_config(): void
    {
        $this->noConfirmation();
        config(['signals.guards.oversold_rsi' => 20, 'signals.guards.overbought_rsi' => 80]);

        $this->assertSame('sell', $this->scorer->evaluate($this->bearishDay(rsi: 25))['decision']);
        $this->assertSame('buy', $this->scorer->evaluate($this->bullishDay(rsi: 78))['decision']);
    }

    public function test_a_blocked_entry_is_let_through_when_the_trend_confirms_it(): void
    {
        config(['signals.guards.require_reversal_confirmation' => true]);

        // ADX 35, -DI above +DI and a falling MACD histogram back a sell; ADX 35, +DI above -DI and a rising one a buy.
        $this->assertSame('sell', $this->scorer->evaluate($this->bearishDay(rsi: 25))['decision']);
        $this->assertSame('buy', $this->scorer->evaluate($this->bullishDay(rsi: 78))['decision']);
    }

    public function test_a_blocked_entry_stays_held_back_when_the_trend_is_weak(): void
    {
        config(['signals.guards.require_reversal_confirmation' => true]);

        $weak = $this->bearishDay(rsi: 25);
        $weak['today']->adx_14 = 15; // no real trend behind the move

        $this->assertSame('hold', $this->scorer->evaluate($weak)['decision']);
    }
}
