<?php

namespace Tests\Feature\Stocks;

use App\Models\DailyPrice;
use App\Models\Sector;
use App\Models\Stock;
use App\Models\StockFundamental;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The Fundamental Analysis page: every stock's fundamentals with return on equity and the latest price. */
class FundamentalsBoardQueryTest extends TestCase
{
    use RefreshDatabase;

    private function login(): void
    {
        Sanctum::actingAs(User::create(['name' => 'T', 'email' => 't@example.com', 'password' => 'password']));
    }

    public function test_it_lists_stocks_with_fundamentals_and_works_out_return_on_equity(): void
    {
        $this->login();
        $banks = Sector::create(['name' => 'Commercial Banks']);
        $nabil = Stock::create(['symbol' => 'NABIL', 'company_name' => 'Nabil Bank', 'is_active' => true, 'sector_id' => $banks->id]);
        $loss = Stock::create(['symbol' => 'LOSS', 'company_name' => 'Loss Co', 'is_active' => true]);
        Stock::create(['symbol' => 'NODATA', 'company_name' => 'No fundamentals', 'is_active' => true]);

        DailyPrice::create(['stock_id' => $nabil->id, 'trade_date' => '2026-10-07', 'open_price' => 530, 'high_price' => 533, 'low_price' => 528, 'close_price' => 530.2, 'volume' => 1]);
        StockFundamental::create(['stock_id' => $nabil->id, 'eps' => 24, 'eps_fiscal_year' => '2081/2082', 'pe_ratio' => 22.1, 'book_value' => 160, 'pbv' => 3.31, 'market_cap' => 150000000000, 'one_year_yield_pct' => 8.5]);
        StockFundamental::create(['stock_id' => $loss->id, 'eps' => -3, 'pe_ratio' => null, 'book_value' => 90, 'pbv' => 1.1]);

        $rows = collect($this->graphQL('{ fundamentalsBoard { symbol company_name sector close eps eps_fiscal_year pe_ratio book_value pbv roe_pct market_cap one_year_yield_pct } }')
            ->assertJsonMissingPath('errors')
            ->json('data.fundamentalsBoard'))->keyBy('symbol');

        $this->assertSame(['LOSS', 'NABIL'], $rows->keys()->sort()->values()->all()); // a stock with no fundamentals row is not listed

        $this->assertSame('Commercial Banks', $rows['NABIL']['sector']);
        $this->assertEquals(530.2, $rows['NABIL']['close']);
        $this->assertEquals(15.0, $rows['NABIL']['roe_pct']);   // 24 / 160
        $this->assertEquals(22.1, $rows['NABIL']['pe_ratio']);
        $this->assertEquals(150000000000.0, $rows['NABIL']['market_cap']);
        $this->assertSame('2081/2082', $rows['NABIL']['eps_fiscal_year']);

        $this->assertEquals(-3.33, $rows['LOSS']['roe_pct']);   // a loss shows as negative ROE
        $this->assertNull($rows['LOSS']['pe_ratio']);
        $this->assertNull($rows['LOSS']['close']);
    }

    public function test_roe_is_empty_when_book_value_is_missing_or_not_positive(): void
    {
        $this->login();
        foreach ([['AAA', 10, null], ['BBB', 10, 0], ['CCC', 10, -5]] as [$symbol, $eps, $book]) {
            $stock = Stock::create(['symbol' => $symbol, 'company_name' => $symbol, 'is_active' => true]);
            StockFundamental::create(['stock_id' => $stock->id, 'eps' => $eps, 'book_value' => $book]);
        }

        $rows = $this->graphQL('{ fundamentalsBoard { symbol roe_pct } }')->json('data.fundamentalsBoard');

        foreach ($rows as $row) {
            $this->assertNull($row['roe_pct'], $row['symbol']);
        }
    }

    public function test_with_no_fundamentals_yet_it_is_an_empty_list_not_an_error(): void
    {
        $this->login();

        $this->graphQL('{ fundamentalsBoard { symbol } }')->assertJsonMissingPath('errors')->assertJsonPath('data.fundamentalsBoard', []);
    }

    public function test_it_needs_a_login(): void
    {
        $this->assertSame(401, $this->graphQLStatus($this->graphQL('{ fundamentalsBoard { symbol } }')));
    }
}
