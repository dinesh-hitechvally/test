<?php

namespace Tests\Feature\Portfolio;

use App\Models\DailyPrice;
use App\Models\Portfolio;
use App\Models\Signal;
use App\Models\Stock;
use App\Models\TechnicalIndicator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BuyOrderTest extends TestCase
{
    use RefreshDatabase;

    private const PREVIEW = 'query ($p: Int!, $s: String!) {
        buyOrderPreview(portfolio_id: $p, symbol: $s) { approved reason checks { name passed } plan { quantity entry_price stop_loss target_price risk_reward } }
    }';

    private const PLACE = 'mutation ($p: Int!, $s: String!) {
        placeBuyOrder(portfolio_id: $p, symbol: $s) { status quantity entry_price stop_loss target_price risk_reward stock { symbol } }
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

    public function test_a_good_buy_signal_becomes_an_order_with_entry_stop_target_and_size(): void
    {
        $response = $this->graphQL(self::PLACE, ['p' => $this->portfolio->id, 's' => 'NRN'])
            ->assertJsonPath('data.placeBuyOrder.status', 'pending')
            ->assertJsonPath('data.placeBuyOrder.stock.symbol', 'NRN');

        $order = $response->json('data.placeBuyOrder');
        $this->assertEquals(820, $order['entry_price']);
        $this->assertEquals(776.1, $order['stop_loss']); // support 780 less the 0.5% buffer
        $this->assertEquals(900, $order['target_price']);
        $this->assertEquals(1.82, $order['risk_reward']); // 80 / 43.9
        // Equity 1,000,000: 1% risk budget 10,000 / 43.9 = 227 shares; the 20% cap allows 243.
        $this->assertSame(227, $order['quantity']);
        $this->assertDatabaseCount('buy_orders', 1);
    }

    public function test_risk_reward_below_the_minimum_is_rejected(): void
    {
        $this->makeStock('LOW', price: 820, support: 780, resistance: 850); // 30 / 43.9 = 0.68

        $r = $this->graphQL(self::PREVIEW, ['p' => $this->portfolio->id, 's' => 'LOW']);

        $r->assertJsonPath('data.buyOrderPreview.approved', false);
        $this->assertStringContainsString('Risk/reward 0.68', $r->json('data.buyOrderPreview.reason'));
        $this->assertSame(['signal', 'risk'], array_column($r->json('data.buyOrderPreview.checks'), 'name'));
    }

    public function test_a_hold_signal_never_buys(): void
    {
        $this->makeStock('HLD', price: 820, support: 780, resistance: 900, signal: 'hold', score: 0.1);

        $r = $this->graphQL(self::PLACE, ['p' => $this->portfolio->id, 's' => 'HLD']);

        $this->assertSame(422, $this->graphQLStatus($r));
        $this->assertDatabaseCount('buy_orders', 0);
    }

    public function test_no_resistance_above_means_no_target_and_no_order(): void
    {
        $this->makeStock('TOP', price: 820, support: 780, resistance: null);

        $this->graphQL(self::PREVIEW, ['p' => $this->portfolio->id, 's' => 'TOP'])
            ->assertJsonPath('data.buyOrderPreview.approved', false);
    }

    public function test_without_cash_there_is_no_order(): void
    {
        $this->portfolio->update(['cash_balance' => 500]);

        $r = $this->graphQL(self::PREVIEW, ['p' => $this->portfolio->id, 's' => 'NRN']);

        $r->assertJsonPath('data.buyOrderPreview.approved', false);
        $this->assertStringContainsString('Free cash', $r->json('data.buyOrderPreview.reason'));
    }

    public function test_pending_orders_reserve_cash_and_block_a_duplicate(): void
    {
        $this->graphQL(self::PLACE, ['p' => $this->portfolio->id, 's' => 'NRN'])->assertOk();

        $r = $this->graphQL(self::PREVIEW, ['p' => $this->portfolio->id, 's' => 'NRN']);

        $r->assertJsonPath('data.buyOrderPreview.approved', false);
        $this->assertStringContainsString('already pending', $r->json('data.buyOrderPreview.reason'));
    }

    public function test_an_existing_holding_blocks_the_buy(): void
    {
        $this->portfolio->transactions()->create(['stock_id' => $this->stock->id, 'type' => 'buy', 'quantity' => 10, 'price' => 800, 'transaction_date' => '2026-09-01']);

        $this->graphQL(self::PREVIEW, ['p' => $this->portfolio->id, 's' => 'NRN'])
            ->assertJsonPath('data.buyOrderPreview.approved', false);
    }

    public function test_a_position_cap_limits_the_size(): void
    {
        config(['trading.max_position_pct' => 5, 'trading.risk_per_trade_pct' => 5]);

        $r = $this->graphQL(self::PREVIEW, ['p' => $this->portfolio->id, 's' => 'NRN']);

        $this->assertSame(60, $r->json('data.buyOrderPreview.plan.quantity')); // 5% of 1M / 820
    }

    public function test_a_pending_order_can_be_cancelled_and_cash_can_be_set(): void
    {
        $id = $this->graphQL(self::PLACE, ['p' => $this->portfolio->id, 's' => 'NRN'])->json('data.placeBuyOrder') ? $this->portfolio->buyOrders()->value('id') : null;

        $this->graphQL('mutation ($p: Int!, $o: Int!) { cancelBuyOrder(portfolio_id: $p, order_id: $o) { status } }', ['p' => $this->portfolio->id, 'o' => $id])
            ->assertJsonPath('data.cancelBuyOrder.status', 'cancelled');

        $this->graphQL('mutation ($p: Int!) { setPortfolioCash(portfolio_id: $p, cash_balance: 250000) { cash_balance } }', ['p' => $this->portfolio->id])
            ->assertJsonPath('data.setPortfolioCash.cash_balance', '250000.0000');
    }

    public function test_recording_the_buy_marks_the_pending_order_executed(): void
    {
        $this->graphQL(self::PLACE, ['p' => $this->portfolio->id, 's' => 'NRN'])->assertOk();

        $this->graphQL('mutation ($p: Int!, $s: Int) { addTransaction(portfolio_id: $p, stock_id: $s, type: "buy", quantity: 227, price: 820, transaction_date: "2026-10-02") { id } }', ['p' => $this->portfolio->id, 's' => $this->stock->id])->assertOk();

        $this->assertSame('executed', $this->portfolio->buyOrders()->value('status'));
    }

    public function test_a_stale_pending_order_expires_and_frees_the_stock(): void
    {
        $this->graphQL(self::PLACE, ['p' => $this->portfolio->id, 's' => 'NRN'])->assertOk();
        $this->portfolio->buyOrders()->update(['created_at' => now()->subDays(6)]);

        $this->graphQL(self::PREVIEW, ['p' => $this->portfolio->id, 's' => 'NRN'])->assertJsonPath('data.buyOrderPreview.approved', true);

        $this->assertSame('expired', $this->portfolio->buyOrders()->value('status'));
    }

    public function test_the_indicator_used_is_the_one_from_the_signals_day(): void
    {
        // A newer indicator row with no levels must not hide the signal day's support/resistance.
        TechnicalIndicator::create(['stock_id' => $this->stock->id, 'trade_date' => '2026-10-02']);

        $this->graphQL(self::PREVIEW, ['p' => $this->portfolio->id, 's' => 'NRN'])->assertJsonPath('data.buyOrderPreview.approved', true);
    }
}
