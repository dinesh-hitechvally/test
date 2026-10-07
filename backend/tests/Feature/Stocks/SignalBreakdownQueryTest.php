<?php

namespace Tests\Feature\Stocks;

use App\Models\Signal;
use App\Models\SignalBreakdown;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** signal_breakdowns is keyed by stock + trade date (not by signal id); the API still serves it on a signal. */
class SignalBreakdownQueryTest extends TestCase
{
    use RefreshDatabase;

    private function seedDay(Stock $stock, string $date, float $buy, float $sell): void
    {
        Signal::create(['stock_id' => $stock->id, 'trade_date' => $date, 'signal' => 'buy', 'score' => ($buy - $sell) / 100, 'reasons' => [], 'rule_keys' => []]);
        SignalBreakdown::create([
            'stock_id' => $stock->id, 'trade_date' => $date, 'buy_pct' => $buy, 'sell_pct' => $sell, 'hold_pct' => 100 - $buy - $sell,
            'technical_buy' => 70, 'technical_sell' => 10, 'technical_hold' => 20, 'hold_score_long_term' => 50, 'hold_score_consolidation' => 10,
            'hold_score_wait_confirmation' => 20, 'hold_score_profit_protection' => 5, 'hold_score_temporary_weakness' => 15,
            'hold_score_overbought' => 0, 'conditions' => ['technical' => [['key' => 'rsi', 'label' => 'RSI', 'result' => '25', 'buy' => 70, 'sell' => 10, 'hold' => 20]]],
        ]);
    }

    public function test_each_signal_serves_the_breakdown_for_its_own_stock_and_day(): void
    {
        Sanctum::actingAs(User::create(['name' => 'T', 'email' => 't@example.com', 'password' => 'password']));
        $nabil = Stock::create(['symbol' => 'NABIL', 'company_name' => 'Nabil', 'is_active' => true]);
        $nica = Stock::create(['symbol' => 'NICA', 'company_name' => 'Nica', 'is_active' => true]);
        $this->seedDay($nabil, '2024-01-02', 62, 10);
        $this->seedDay($nabil, '2024-01-03', 55, 20);
        $this->seedDay($nica, '2024-01-03', 80, 5); // same date, different stock

        $response = $this->graphQL('{ stockSignals(symbol: "NABIL") { data { trade_date breakdown { buy_pct sell_pct hold_type category_scores hold_scores conditions } } } }');
        $this->assertNull($response->json('errors'), json_encode($response->json('errors')));
        $data = $response->json('data.stockSignals.data');
        $byDate = collect($data)->keyBy('trade_date');

        $this->assertEquals(62.0, $byDate['2024-01-02']['breakdown']['buy_pct']);
        $this->assertEquals(55.0, $byDate['2024-01-03']['breakdown']['buy_pct']); // NABIL's, not NICA's 80
        $this->assertEquals(['buy' => 70.0, 'sell' => 10.0, 'hold' => 20.0], $byDate['2024-01-02']['breakdown']['category_scores']['technical']);
        $this->assertNull($byDate['2024-01-02']['breakdown']['category_scores']['fundamental']);
        $this->assertEquals(50.0, $byDate['2024-01-02']['breakdown']['hold_scores']['long_term']);
        $this->assertSame('rsi', $byDate['2024-01-02']['breakdown']['conditions']['technical'][0]['key']);
    }

    public function test_a_day_without_a_breakdown_returns_null(): void
    {
        Sanctum::actingAs(User::create(['name' => 'T', 'email' => 't@example.com', 'password' => 'password']));
        $stock = Stock::create(['symbol' => 'NABIL', 'company_name' => 'Nabil', 'is_active' => true]);
        Signal::create(['stock_id' => $stock->id, 'trade_date' => '2024-01-02', 'signal' => 'hold', 'score' => 0, 'reasons' => [], 'rule_keys' => []]);

        $this->graphQL('{ stockSignals(symbol: "NABIL") { data { breakdown { buy_pct } } } }')
            ->assertJsonPath('data.stockSignals.data.0.breakdown', null);
    }

    public function test_deleting_a_stock_removes_its_breakdowns(): void
    {
        $stock = Stock::create(['symbol' => 'NABIL', 'company_name' => 'Nabil', 'is_active' => true]);
        $this->seedDay($stock, '2024-01-02', 62, 10);

        $stock->delete();

        $this->assertSame(0, SignalBreakdown::count());
    }
}
