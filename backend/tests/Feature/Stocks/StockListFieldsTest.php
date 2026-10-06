<?php

namespace Tests\Feature\Stocks;

use App\Models\DailyPrice;
use App\Models\Signal;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The stocks query only does the work for the fields it asks for (the Stocks page's column picker relies on it). */
class StockListFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::create(['name' => 'T', 'email' => 't@example.com', 'password' => 'password']));

        $stock = Stock::create(['symbol' => 'NABIL', 'company_name' => 'Nabil Bank', 'is_active' => true]);
        foreach (['2026-10-01' => 100, '2026-10-02' => 110] as $date => $close) {
            DailyPrice::create(['stock_id' => $stock->id, 'trade_date' => $date, 'open_price' => $close, 'high_price' => $close + 5, 'low_price' => $close - 5, 'close_price' => $close, 'volume' => 10]);
        }
        Signal::create(['stock_id' => $stock->id, 'trade_date' => '2026-10-02', 'signal' => 'buy', 'score' => 0.6]);
    }

    /** @return list<string> the tables the request read from */
    private function tablesRead(string $query): array
    {
        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            $sql[] = $q->sql;
        });

        $this->graphQL($query)->assertOk();

        return array_values(array_filter(['daily_prices', 'signals', 'ai_stock_opinions'], fn ($t) => collect($sql)->contains(fn ($s) => str_contains($s, "`{$t}`") || str_contains($s, "\"{$t}\""))));
    }

    public function test_asking_only_for_names_reads_no_prices_signals_or_opinions(): void
    {
        $this->assertSame([], $this->tablesRead('{ stocks { symbol company_name sector } }'));
    }

    public function test_each_field_group_reads_only_its_own_table(): void
    {
        $this->assertSame(['daily_prices'], $this->tablesRead('{ stocks { symbol latest_price { high_price low_price } } }'));
        $this->assertSame(['signals'], $this->tablesRead('{ stocks { symbol latest_signal { signal } } }'));
        $this->assertSame(['ai_stock_opinions'], $this->tablesRead('{ stocks { symbol ai_opinion { verdict } } }'));
    }

    public function test_change_pct_still_works_and_only_runs_when_asked_for(): void
    {
        $this->graphQL('{ stocks { symbol change_pct } }')->assertJsonPath('data.stocks.0.change_pct', 10);
        $this->assertSame([], $this->tablesRead('{ stocks { symbol } }'));
    }

    public function test_the_full_query_returns_everything(): void
    {
        $this->graphQL('{ stocks { symbol company_name latest_price { close_price high_price low_price } latest_signal { signal } change_pct } }')
            ->assertJsonPath('data.stocks.0.symbol', 'NABIL')
            ->assertJsonPath('data.stocks.0.latest_price.high_price', '115.0000')
            ->assertJsonPath('data.stocks.0.latest_signal.signal', 'buy')
            ->assertJsonPath('data.stocks.0.change_pct', 10);
    }
}
