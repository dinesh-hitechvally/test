<?php

namespace Tests\Unit\Portfolio;

use App\Services\Portfolio\PositionSizer;
use Tests\TestCase;

class PositionSizerTest extends TestCase
{
    public function test_size_comes_from_risk_not_from_a_rupee_amount(): void
    {
        config(['trading.risk_per_trade_pct' => 1, 'trading.max_position_pct' => 100, 'trading.fee_pct' => 0]);

        $r = (new PositionSizer)->size(equity: 1_000_000, freeCash: 1_000_000, entry: 820, stop: 775);

        $this->assertSame(222, $r['quantity']); // 10,000 / 45
        $this->assertSame('risk', $r['limited_by']);
        $this->assertEquals(10_000, $r['max_risk']);
        $this->assertEquals(9_990, $r['risk_amount']); // 222 x 45
        $this->assertEquals(182_040, $r['position_value']); // 222 x 820
    }

    public function test_quantity_is_reduced_when_cash_is_short(): void
    {
        config(['trading.risk_per_trade_pct' => 1, 'trading.max_position_pct' => 100, 'trading.fee_pct' => 0]);

        $r = (new PositionSizer)->size(equity: 1_000_000, freeCash: 123_000, entry: 820, stop: 775);

        $this->assertSame(150, $r['quantity']); // 123,000 / 820
        $this->assertSame('cash', $r['limited_by']);
    }

    public function test_fees_are_included_in_what_cash_must_cover(): void
    {
        config(['trading.risk_per_trade_pct' => 1, 'trading.max_position_pct' => 100, 'trading.fee_pct' => 0.4]);

        $r = (new PositionSizer)->size(equity: 1_000_000, freeCash: 123_000, entry: 820, stop: 775);

        $this->assertSame(149, $r['quantity']); // 123,000 / (820 x 1.004)
        $this->assertLessThanOrEqual(123_000, $r['position_value'] + $r['fees']);
    }

    public function test_the_position_cap_applies(): void
    {
        config(['trading.risk_per_trade_pct' => 1, 'trading.max_position_pct' => 10, 'trading.fee_pct' => 0]);

        $r = (new PositionSizer)->size(equity: 1_000_000, freeCash: 1_000_000, entry: 820, stop: 775);

        $this->assertSame(121, $r['quantity']); // 100,000 / 820
        $this->assertSame('size_cap', $r['limited_by']);
    }

    public function test_a_stop_at_or_above_entry_gives_zero_shares(): void
    {
        $this->assertSame(0, (new PositionSizer)->size(1_000_000, 1_000_000, 820, 820)['quantity']);
    }
}
