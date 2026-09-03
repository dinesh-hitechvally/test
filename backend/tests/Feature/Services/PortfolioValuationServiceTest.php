<?php

namespace Tests\Feature\Services;

use App\Models\DailyPrice;
use App\Models\Portfolio;
use App\Models\Stock;
use App\Models\User;
use App\Services\Portfolio\PortfolioValuationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PortfolioValuationServiceTest extends TestCase
{
    use RefreshDatabase;

    private PortfolioValuationService $valuation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->valuation = app(PortfolioValuationService::class);
    }

    private function makePortfolio(): Portfolio
    {
        $user = User::create(['name' => 'Test', 'email' => 'test@example.com', 'password' => 'password']);

        return $user->portfolios()->create(['name' => 'Test Portfolio']);
    }

    private function makeStock(float $currentClose): Stock
    {
        $stock = Stock::create(['symbol' => 'TEST', 'company_name' => 'Test Co', 'is_active' => true]);

        DailyPrice::create([
            'stock_id' => $stock->id,
            'trade_date' => now()->toDateString(),
            'open_price' => $currentClose,
            'high_price' => $currentClose,
            'low_price' => $currentClose,
            'close_price' => $currentClose,
            'volume' => 1000,
        ]);

        return $stock;
    }

    public function test_weighted_average_cost_and_realized_pnl_on_partial_sell(): void
    {
        $portfolio = $this->makePortfolio();
        $stock = $this->makeStock(currentClose: 181.20);

        // Buy 100 @ 500 + 50 fees -> total cost 50050, avg cost 500.50
        $portfolio->transactions()->create([
            'stock_id' => $stock->id, 'type' => 'buy', 'quantity' => 100,
            'price' => 500, 'fees' => 50, 'transaction_date' => '2026-06-01',
        ]);

        // Sell 40 @ 550 - 20 fees -> realized = (40*550-20) - (500.5*40) = 21980 - 20020 = 1960
        $portfolio->transactions()->create([
            'stock_id' => $stock->id, 'type' => 'sell', 'quantity' => 40,
            'price' => 550, 'fees' => 20, 'transaction_date' => '2026-07-01',
        ]);

        $holdings = $this->valuation->holdings($portfolio);
        $this->assertCount(1, $holdings);
        $holding = $holdings->first();

        $this->assertSame(60, $holding['quantity']);
        $this->assertEqualsWithDelta(500.50, $holding['avg_cost'], 0.001);
        $this->assertEqualsWithDelta(30030.0, $holding['invested'], 0.001); // 50050 - 20020
        $this->assertEqualsWithDelta(181.20, $holding['current_price'], 0.001);
        $this->assertEqualsWithDelta(10872.0, $holding['current_value'], 0.001); // 60 * 181.20
        $this->assertEqualsWithDelta(-19158.0, $holding['unrealized_pnl'], 0.001); // 10872 - 30030

        $realized = $this->valuation->realizedPnl($portfolio);
        $this->assertCount(1, $realized);
        $this->assertEqualsWithDelta(1960.0, $realized->first()['realized_pnl'], 0.001);

        $summary = $this->valuation->summary($portfolio);
        $this->assertEqualsWithDelta(1960.0, $summary['realized_pnl'], 0.001);
        $this->assertEqualsWithDelta(-19158.0, $summary['unrealized_pnl'], 0.001);
        $this->assertEqualsWithDelta(-17198.0, $summary['total_pnl'], 0.001); // -19158 + 1960
    }

    public function test_full_sell_removes_the_holding_entirely(): void
    {
        $portfolio = $this->makePortfolio();
        $stock = $this->makeStock(currentClose: 100);

        $portfolio->transactions()->create([
            'stock_id' => $stock->id, 'type' => 'buy', 'quantity' => 10,
            'price' => 90, 'fees' => 0, 'transaction_date' => '2026-01-01',
        ]);
        $portfolio->transactions()->create([
            'stock_id' => $stock->id, 'type' => 'sell', 'quantity' => 10,
            'price' => 95, 'fees' => 0, 'transaction_date' => '2026-02-01',
        ]);

        $this->assertCount(0, $this->valuation->holdings($portfolio));

        $realized = $this->valuation->realizedPnl($portfolio);
        $this->assertEqualsWithDelta(50.0, $realized->first()['realized_pnl'], 0.001); // (95-90)*10
    }

    public function test_selling_more_than_held_is_rejected(): void
    {
        $portfolio = $this->makePortfolio();
        $stock = $this->makeStock(currentClose: 100);

        $portfolio->transactions()->create([
            'stock_id' => $stock->id, 'type' => 'buy', 'quantity' => 10,
            'price' => 90, 'fees' => 0, 'transaction_date' => '2026-01-01',
        ]);

        $this->expectException(RuntimeException::class);
        $this->valuation->assertTransactionIsValid($portfolio, $stock->id, 'sell', 11, '2026-02-01');
    }

    public function test_selling_exactly_the_held_quantity_is_allowed(): void
    {
        $portfolio = $this->makePortfolio();
        $stock = $this->makeStock(currentClose: 100);

        $portfolio->transactions()->create([
            'stock_id' => $stock->id, 'type' => 'buy', 'quantity' => 10,
            'price' => 90, 'fees' => 0, 'transaction_date' => '2026-01-01',
        ]);

        $this->valuation->assertTransactionIsValid($portfolio, $stock->id, 'sell', 10, '2026-02-01');
        $this->assertTrue(true); // no exception thrown
    }

    public function test_a_backdated_sell_before_any_buy_is_rejected(): void
    {
        $portfolio = $this->makePortfolio();
        $stock = $this->makeStock(currentClose: 100);

        $portfolio->transactions()->create([
            'stock_id' => $stock->id, 'type' => 'buy', 'quantity' => 10,
            'price' => 90, 'fees' => 0, 'transaction_date' => '2026-06-01',
        ]);

        $this->expectException(RuntimeException::class);
        // dated before the only buy that exists — nothing would be held yet
        $this->valuation->assertTransactionIsValid($portfolio, $stock->id, 'sell', 1, '2026-01-01');
    }
}
