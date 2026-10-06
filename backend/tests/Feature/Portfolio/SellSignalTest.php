<?php

namespace Tests\Feature\Portfolio;

use App\Models\DailyPrice;
use App\Models\Portfolio;
use App\Models\PositionTarget;
use App\Models\Signal;
use App\Models\Stock;
use App\Models\TechnicalIndicator;
use App\Models\User;
use App\Services\Portfolio\PortfolioValuationService;
use App\Services\Portfolio\SellSignalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SellSignalTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'query ($p: Int!) { sellChecks(portfolio_id: $p) { symbol action primary_reason reasons { rule } stop_loss trailing_stop effective_stop highest_close } }';

    private Portfolio $portfolio;

    private Stock $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::create(['name' => 'Test', 'email' => 'test@example.com', 'password' => 'password']);
        $this->portfolio = $user->portfolios()->create(['name' => 'Main']);
        Sanctum::actingAs($user);

        // Bought 100 NRN at 820 on 2026-09-01 (no fees, so avg cost is exactly 820).
        $this->stock = Stock::create(['symbol' => 'NRN', 'company_name' => 'NRN', 'is_active' => true]);
        $this->portfolio->transactions()->create(['stock_id' => $this->stock->id, 'type' => 'buy', 'quantity' => 100, 'price' => 820, 'fees' => 0, 'transaction_date' => '2026-09-01']);
    }

    private function close(string $date, float $close, int $volume = 1000): void
    {
        DailyPrice::create(['stock_id' => $this->stock->id, 'trade_date' => $date, 'open_price' => $close, 'high_price' => $close, 'low_price' => $close, 'close_price' => $close, 'volume' => $volume]);
    }

    private function decision(): array
    {
        return $this->graphQL(self::QUERY, ['p' => $this->portfolio->id])->json('data.sellChecks.0');
    }

    private function levels(?float $stop = 775, ?float $target = 900): void
    {
        PositionTarget::create(['portfolio_id' => $this->portfolio->id, 'stock_id' => $this->stock->id, 'stop_loss' => $stop, 'target_price' => $target]);
    }

    public function test_a_healthy_position_is_held(): void
    {
        $this->levels();
        $this->close('2026-09-10', 840);

        $d = $this->decision();

        $this->assertSame('hold', $d['action']);
        $this->assertNull($d['primary_reason']);
        $this->assertNull($d['trailing_stop']); // not risen far enough yet
    }

    public function test_a_stop_loss_hit_sells(): void
    {
        $this->levels();
        $this->close('2026-09-10', 775);

        $d = $this->decision();

        $this->assertSame('sell', $d['action']);
        $this->assertSame('stop_loss', $d['primary_reason']);
    }

    public function test_reaching_the_target_sells(): void
    {
        $this->levels();
        $this->close('2026-09-10', 900);

        $d = $this->decision();

        $this->assertSame('sell', $d['action']);
        $this->assertContains('target', array_column($d['reasons'], 'rule'));
    }

    public function test_the_trailing_stop_follows_the_highest_close_and_never_moves_down(): void
    {
        $this->levels(stop: 775, target: null);
        $svc = new SellSignalService(app(PortfolioValuationService::class));

        $this->assertNull($svc->trailingStop(820, 860)); // stop would still be under cost
        $this->assertEquals(850.8, $svc->trailingStop(820, 900));
        $this->assertEquals(900.8, $svc->trailingStop(820, 950));
        $this->assertEquals(930.8, $svc->trailingStop(820, 980));

        // Rallied to 950, now back at 880: the trailing stop stays at 900.8, so this sells.
        $this->close('2026-09-10', 950);
        $this->close('2026-09-11', 880);

        $d = $this->decision();

        $this->assertSame('sell', $d['action']);
        $this->assertSame('trailing_stop', $d['primary_reason']);
        $this->assertEquals(900.8, $d['trailing_stop']);
        $this->assertEquals(950, $d['highest_close']);
        $this->assertEquals(900.8, $d['effective_stop']);
    }

    public function test_the_topbar_alert_fires_on_a_trailing_stop_with_the_effective_stop(): void
    {
        $this->levels(stop: 775, target: null);
        $this->close('2026-09-10', 950);
        $this->close('2026-09-11', 880);

        $alert = $this->graphQL('{ priceAlerts { kind symbol status stop_loss } }')->json('data.priceAlerts.0');

        $this->assertSame('stop_breached', $alert['status']);
        $this->assertEquals(900.8, $alert['stop_loss']);
    }

    public function test_a_close_above_the_trailing_stop_keeps_holding(): void
    {
        $this->levels(stop: 775, target: null);
        $this->close('2026-09-10', 950);
        $this->close('2026-09-11', 930);

        $this->assertSame('hold', $this->decision()['action']);
    }

    public function test_a_sell_signal_is_a_reversal(): void
    {
        $this->levels();
        $this->close('2026-09-10', 840);
        Signal::create(['stock_id' => $this->stock->id, 'trade_date' => '2026-09-09', 'signal' => 'buy', 'score' => 0.6]);
        Signal::create(['stock_id' => $this->stock->id, 'trade_date' => '2026-09-10', 'signal' => 'sell', 'score' => -0.6]);

        $d = $this->decision();

        $this->assertSame('sell', $d['action']);
        $this->assertSame('signal_reversal', $d['primary_reason']);
    }

    public function test_breakdown_needs_all_three_conditions(): void
    {
        $this->levels();
        $this->close('2026-09-09', 800, volume: 1000);
        $this->close('2026-09-10', 790, volume: 3000);
        TechnicalIndicator::create(['stock_id' => $this->stock->id, 'trade_date' => '2026-09-09', 'sma_50' => 810, 'support_price' => 795]);
        TechnicalIndicator::create(['stock_id' => $this->stock->id, 'trade_date' => '2026-09-10', 'sma_50' => 810, 'support_price' => 700, 'volume_ratio' => 2.0]);

        $d = $this->decision();

        $this->assertSame('sell', $d['action']);
        $this->assertSame('breakdown', $d['primary_reason']);

        // Same breakdown on falling volume is not a breakdown.
        DailyPrice::where('trade_date', '2026-09-10')->update(['volume' => 500]);
        $this->assertSame('hold', $this->decision()['action']);
    }

    private const BUY = 'mutation ($p: Int!, $s: Int) { addTransaction(portfolio_id: $p, stock_id: $s, type: "buy", quantity: 100, price: 820, transaction_date: "2026-09-02") { id } }';

    public function test_logging_a_buy_sets_the_holdings_stop_and_target_from_the_stocks_levels(): void
    {
        $this->portfolio->transactions()->delete();
        $this->close('2026-09-01', 820);
        TechnicalIndicator::create(['stock_id' => $this->stock->id, 'trade_date' => '2026-09-01', 'support_price' => 780, 'resistance_price' => 900]);

        $this->graphQL(self::BUY, ['p' => $this->portfolio->id, 's' => $this->stock->id])->assertOk();

        $target = PositionTarget::where('portfolio_id', $this->portfolio->id)->first();
        $this->assertEquals(776.1, (float) $target->stop_loss); // support 780 less the 0.5% buffer
        $this->assertEquals(900, (float) $target->target_price);
    }

    public function test_logging_a_buy_never_overwrites_levels_you_already_set(): void
    {
        $this->portfolio->transactions()->delete();
        $this->close('2026-09-01', 820);
        TechnicalIndicator::create(['stock_id' => $this->stock->id, 'trade_date' => '2026-09-01', 'support_price' => 780, 'resistance_price' => 900]);
        $this->levels(stop: 700, target: 1000);

        $this->graphQL(self::BUY, ['p' => $this->portfolio->id, 's' => $this->stock->id])->assertOk();

        $target = PositionTarget::where('portfolio_id', $this->portfolio->id)->first();
        $this->assertEquals(700, (float) $target->stop_loss);
        $this->assertEquals(1000, (float) $target->target_price);
    }

    public function test_logging_a_buy_without_usable_levels_still_works_and_sets_none(): void
    {
        $this->portfolio->transactions()->delete();
        $this->close('2026-09-01', 820); // no indicator row, so no support / resistance

        $this->graphQL(self::BUY, ['p' => $this->portfolio->id, 's' => $this->stock->id])->assertOk();

        $this->assertSame(1, $this->portfolio->transactions()->count());
        $this->assertSame(0, PositionTarget::count());
    }
}
