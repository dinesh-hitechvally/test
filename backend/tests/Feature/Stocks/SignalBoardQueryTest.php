<?php

namespace Tests\Feature\Stocks;

use App\Models\MlModel;
use App\Models\Sector;
use App\Models\Signal;
use App\Models\SignalBreakdown;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The Signals page's one query (every stock's latest signal with its percentages) and the ML model summary for Backtesting. */
class SignalBoardQueryTest extends TestCase
{
    use RefreshDatabase;

    private function day(Stock $stock, string $date, string $signal, float $buy, float $sell, ?string $holdType = null): void
    {
        Signal::create(['stock_id' => $stock->id, 'trade_date' => $date, 'signal' => $signal, 'score' => ($buy - $sell) / 100, 'reasons' => ['a reason'], 'rule_keys' => []]);
        SignalBreakdown::create([
            'stock_id' => $stock->id, 'trade_date' => $date, 'buy_pct' => $buy, 'sell_pct' => $sell, 'hold_pct' => 100 - $buy - $sell, 'hold_type' => $holdType,
            'hold_score_long_term' => 0, 'hold_score_consolidation' => 0, 'hold_score_wait_confirmation' => 0, 'hold_score_profit_protection' => 0,
            'hold_score_temporary_weakness' => 0, 'hold_score_overbought' => 0, 'conditions' => [],
        ]);
    }

    public function test_the_board_lists_each_stocks_latest_day_only_with_its_percentages_and_sector(): void
    {
        Sanctum::actingAs(User::create(['name' => 'T', 'email' => 't@example.com', 'password' => 'password']));
        $banks = Sector::create(['name' => 'Commercial Banks']);
        $nabil = Stock::create(['symbol' => 'NABIL', 'company_name' => 'Nabil', 'is_active' => true, 'sector_id' => $banks->id]);
        $chcl = Stock::create(['symbol' => 'CHCL', 'company_name' => 'Chilime', 'is_active' => true]);

        $this->day($nabil, '2024-01-02', 'sell', 10, 70);        // an older day: must not be shown
        $this->day($nabil, '2024-01-03', 'buy', 62.5, 12.5);
        $this->day($chcl, '2024-01-03', 'hold', 30, 20, 'consolidation');

        $rows = collect($this->graphQL('{ signalBoard { symbol sector trade_date signal score buy_pct sell_pct hold_pct hold_type reasons } }')
            ->assertJsonMissingPath('errors')
            ->json('data.signalBoard'))->keyBy('symbol');

        $this->assertCount(2, $rows);
        $this->assertSame('buy', $rows['NABIL']['signal']);
        $this->assertSame('2024-01-03', $rows['NABIL']['trade_date']);
        $this->assertSame('Commercial Banks', $rows['NABIL']['sector']);
        $this->assertEquals(62.5, $rows['NABIL']['buy_pct']);
        $this->assertEquals(12.5, $rows['NABIL']['sell_pct']);
        $this->assertEquals(25.0, $rows['NABIL']['hold_pct']);
        $this->assertNull($rows['NABIL']['hold_type']);

        $this->assertSame('hold', $rows['CHCL']['signal']);
        $this->assertSame('consolidation', $rows['CHCL']['hold_type']);
        $this->assertNull($rows['CHCL']['sector']);
    }

    public function test_the_board_is_ordered_strongest_buy_first(): void
    {
        Sanctum::actingAs(User::create(['name' => 'T', 'email' => 't@example.com', 'password' => 'password']));
        foreach ([['AAA', 'hold', 30, 20], ['BBB', 'buy', 70, 10], ['CCC', 'sell', 10, 65]] as [$symbol, $signal, $buy, $sell]) {
            $this->day(Stock::create(['symbol' => $symbol, 'company_name' => $symbol, 'is_active' => true]), '2024-01-03', $signal, $buy, $sell);
        }

        $symbols = collect($this->graphQL('{ signalBoard { symbol } }')->json('data.signalBoard'))->pluck('symbol')->all();

        $this->assertSame(['BBB', 'AAA', 'CCC'], $symbols);
    }

    public function test_a_stock_without_a_breakdown_still_appears_with_empty_percentages(): void
    {
        Sanctum::actingAs(User::create(['name' => 'T', 'email' => 't@example.com', 'password' => 'password']));
        $stock = Stock::create(['symbol' => 'OLD', 'company_name' => 'Old', 'is_active' => true]);
        Signal::create(['stock_id' => $stock->id, 'trade_date' => '2024-01-03', 'signal' => 'hold', 'score' => 0, 'reasons' => [], 'rule_keys' => []]);

        $row = $this->graphQL('{ signalBoard { symbol signal buy_pct } }')->json('data.signalBoard.0');

        $this->assertSame('OLD', $row['symbol']);
        $this->assertNull($row['buy_pct']);
    }

    public function test_the_board_needs_a_login(): void
    {
        $this->assertSame(401, $this->graphQLStatus($this->graphQL('{ signalBoard { symbol } }')));
    }

    public function test_the_ml_model_summary_is_null_before_any_training_and_filled_after(): void
    {
        Sanctum::actingAs(User::create(['name' => 'T', 'email' => 't@example.com', 'password' => 'password']));

        $this->graphQL('{ mlModel { accuracy } }')->assertJsonPath('data.mlModel', null);

        MlModel::create([
            'horizon_days' => 5, 'train_samples' => 80000, 'test_samples' => 20000, 'accuracy' => 0.5303, 'baseline_accuracy' => 0.6705,
            'precision' => 0.61, 'recall' => 0.42, 'f1' => 0.4975, 'stocks_used' => 309, 'model_path' => 'ml/x', 'trained_at' => now(),
        ]);

        $model = $this->graphQL('{ mlModel { accuracy baseline_accuracy beats_baseline train_samples test_samples f1 stocks_used horizon_days } }')->json('data.mlModel');

        $this->assertEquals(0.5303, $model['accuracy']);
        $this->assertFalse($model['beats_baseline']);
        $this->assertSame(309, $model['stocks_used']);
        $this->assertSame(80000, $model['train_samples']);
    }

    public function test_with_no_signals_the_board_is_empty_and_touches_nothing_else(): void
    {
        Sanctum::actingAs(User::create(['name' => 'T', 'email' => 't@example.com', 'password' => 'password']));
        Stock::create(['symbol' => 'NABIL', 'company_name' => 'Nabil', 'is_active' => true]);
        // Even a database that has not been brought up to date (here: no breakdown table at all) is no error while there is nothing to show.
        \Illuminate\Support\Facades\Schema::drop('signal_breakdowns');

        $this->graphQL('{ signalBoard { symbol } }')
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.signalBoard', []);
    }
}
