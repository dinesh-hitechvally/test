<?php

namespace Tests\Feature\Portfolio;

use App\Models\DailyPrice;
use App\Models\Portfolio;
use App\Models\PositionTarget;
use App\Models\Signal;
use App\Models\Stock;
use App\Models\TechnicalIndicator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The advisory trade plan for a buy signal. Nothing is ever bought or stored by it. */
class TradePlanTest extends TestCase
{
    use RefreshDatabase;

    private const PLAN = 'query ($p: Int!, $s: String!) {
        tradePlan(portfolio_id: $p, symbol: $s) { approved reason checks { name passed } plan { quantity entry_price stop_loss target_price risk_reward } }
    }';

    private Portfolio $portfolio;

    private Stock $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::create(['name' => 'Test', 'email' => 'test@example.com', 'password' => 'password']);
        $this->portfolio = $user->portfolios()->create(['name' => 'Main', 'cash_balance' => 1_000_000]);
        Sanctum::actingAs($user);

        // NRN: price 820, support 780, resistance 900, score 0.74.
        $this->stock = $this->makeStock('NRN', price: 820, support: 780, resistance: 900);
    }

    private function makeStock(string $symbol, float $price, ?float $support, ?float $resistance, string $signal = 'buy', float $score = 0.74): Stock
    {
        $stock = Stock::create(['symbol' => $symbol, 'company_name' => $symbol, 'is_active' => true]);
        $date = '2026-10-01';
        DailyPrice::create(['stock_id' => $stock->id, 'trade_date' => $date, 'open_price' => $price, 'high_price' => $price, 'low_price' => $price, 'close_price' => $price, 'volume' => 1000]);
        TechnicalIndicator::create(['stock_id' => $stock->id, 'trade_date' => $date, 'support_price' => $support, 'resistance_price' => $resistance]);
        Signal::create(['stock_id' => $stock->id, 'trade_date' => $date, 'signal' => $signal, 'score' => $score, 'price_at_signal' => $price]);

        return $stock;
    }

    private function plan(string $symbol = 'NRN'): \Illuminate\Testing\TestResponse
    {
        return $this->graphQL(self::PLAN, ['p' => $this->portfolio->id, 's' => $symbol]);
    }

    public function test_a_good_buy_signal_gets_entry_stop_target_and_size(): void
    {
        $r = $this->plan()->assertJsonPath('data.tradePlan.approved', true)->assertJsonPath('data.tradePlan.reason', null);

        $plan = $r->json('data.tradePlan.plan');
        $this->assertEquals(820, $plan['entry_price']);
        $this->assertEquals(776.1, $plan['stop_loss']); // support 780 less the 0.5% buffer
        $this->assertEquals(900, $plan['target_price']);
        $this->assertEquals(1.82, $plan['risk_reward']); // 80 / 43.9
        // Equity 1,000,000: 1% risk budget 10,000 / 43.9 = 227 shares; the 20% cap allows 243.
        $this->assertSame(227, $plan['quantity']);
        $this->assertSame(['signal', 'risk', 'portfolio', 'cash', 'sizing'], array_column($r->json('data.tradePlan.checks'), 'name'));
    }

    public function test_the_plan_is_advice_only_and_stores_nothing(): void
    {
        $this->plan();
        $this->plan();

        $this->assertFalse(Schema::hasTable('buy_orders'));
        $this->assertSame(0, PositionTarget::count());
        $this->assertSame('1000000.0000', (string) $this->portfolio->fresh()->cash_balance); // cash is not touched
    }

    public function test_risk_reward_below_the_minimum_is_rejected_with_the_reason(): void
    {
        $this->makeStock('LOW', price: 820, support: 780, resistance: 850); // 30 / 43.9 = 0.68

        $r = $this->plan('LOW');

        $r->assertJsonPath('data.tradePlan.approved', false);
        $this->assertStringContainsString('Risk/reward 0.68', $r->json('data.tradePlan.reason'));
        $this->assertSame(['signal', 'risk'], array_column($r->json('data.tradePlan.checks'), 'name'));
        $this->assertNull($r->json('data.tradePlan.plan'));
    }

    public function test_a_hold_signal_has_no_plan(): void
    {
        $this->makeStock('HLD', price: 820, support: 780, resistance: 900, signal: 'hold', score: 0.1);

        $this->plan('HLD')->assertJsonPath('data.tradePlan.approved', false);
    }

    /** A regression: a "not a buy" test written as `! $signal->signal === 'buy'` was always false, so only the score stopped a hold. */
    public function test_a_hold_or_sell_signal_is_refused_as_not_a_buy_even_with_a_high_score(): void
    {
        $this->makeStock('HLD', price: 820, support: 780, resistance: 900, signal: 'hold', score: 0.9);
        $this->makeStock('SEL', price: 820, support: 780, resistance: 900, signal: 'sell', score: 0.9);

        foreach (['HLD' => 'hold', 'SEL' => 'sell'] as $symbol => $signal) {
            $r = $this->plan($symbol)->assertJsonPath('data.tradePlan.approved', false);

            $this->assertStringContainsString("Latest signal is {$signal}, not a buy", $r->json('data.tradePlan.reason'));
        }
    }

    public function test_no_resistance_above_means_no_target_and_no_plan(): void
    {
        $this->makeStock('TOP', price: 820, support: 780, resistance: null);

        $r = $this->plan('TOP')->assertJsonPath('data.tradePlan.approved', false);
        $this->assertStringContainsString('No resistance', $r->json('data.tradePlan.reason'));
    }

    public function test_without_enough_cash_there_is_no_plan(): void
    {
        $this->portfolio->update(['cash_balance' => 500]);

        $r = $this->plan()->assertJsonPath('data.tradePlan.approved', false);
        $this->assertStringContainsString('Cash 500.00 cannot buy even one share', $r->json('data.tradePlan.reason'));
    }

    public function test_cash_limits_the_quantity(): void
    {
        // Portfolio value is about 1,000,000 (877,000 held + 123,000 cash), so the risk size is 227 shares,
        // but the cash only pays for 149 of them once the 0.4% fees are added.
        $held = $this->makeStock('HLD2', price: 100, support: 90, resistance: 130);
        $this->portfolio->transactions()->create(['stock_id' => $held->id, 'type' => 'buy', 'quantity' => 8770, 'price' => 100, 'transaction_date' => '2026-09-01']);
        $this->portfolio->update(['cash_balance' => 123_000]);

        $this->assertSame(149, $this->plan()->json('data.tradePlan.plan.quantity'));
    }

    public function test_a_stock_already_held_gets_no_plan(): void
    {
        $this->portfolio->transactions()->create(['stock_id' => $this->stock->id, 'type' => 'buy', 'quantity' => 10, 'price' => 800, 'transaction_date' => '2026-09-01']);

        $r = $this->plan()->assertJsonPath('data.tradePlan.approved', false);
        $this->assertStringContainsString('already held', $r->json('data.tradePlan.reason'));
    }

    public function test_the_position_count_limit_applies_to_holdings(): void
    {
        config(['trading.max_positions' => 1]);
        $other = $this->makeStock('OTH', price: 100, support: 90, resistance: 130);
        $this->portfolio->transactions()->create(['stock_id' => $other->id, 'type' => 'buy', 'quantity' => 10, 'price' => 100, 'transaction_date' => '2026-09-01']);

        $r = $this->plan()->assertJsonPath('data.tradePlan.approved', false);
        $this->assertStringContainsString('maximum of 1 positions', $r->json('data.tradePlan.reason'));
    }

    public function test_a_position_cap_limits_the_size(): void
    {
        config(['trading.max_position_pct' => 5, 'trading.risk_per_trade_pct' => 5]);

        $this->assertSame(60, $this->plan()->json('data.tradePlan.plan.quantity')); // 5% of 1M / 820
    }

    public function test_the_indicator_used_is_the_one_from_the_signals_day(): void
    {
        // A newer indicator row with no levels must not hide the signal day's support/resistance.
        TechnicalIndicator::create(['stock_id' => $this->stock->id, 'trade_date' => '2026-10-02']);

        $this->plan()->assertJsonPath('data.tradePlan.approved', true);
    }

    public function test_the_cash_balance_can_be_set(): void
    {
        $this->graphQL('mutation ($p: Int!) { setPortfolioCash(portfolio_id: $p, cash_balance: 250000) { cash_balance } }', ['p' => $this->portfolio->id])
            ->assertJsonPath('data.setPortfolioCash.cash_balance', '250000.0000');
    }

    public function test_the_order_operations_are_gone_from_the_api(): void
    {
        $this->assertSame(
            'Cannot query field "placeBuyOrder" on type "Mutation".',
            $this->graphQL('mutation { placeBuyOrder(portfolio_id: 1, symbol: "NRN") { id } }')->json('errors.0.message'),
        );
    }
}
