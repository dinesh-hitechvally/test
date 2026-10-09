<?php

namespace Tests\Feature\Stocks;

use App\Models\Dividend;
use App\Models\Sector;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The Market > Dividends page: every declaration on record, across stocks, newest fiscal year first. */
class MarketDividendsQueryTest extends TestCase
{
    use RefreshDatabase;

    private function login(): void
    {
        Sanctum::actingAs(User::create(['name' => 'T', 'email' => 't@example.com', 'password' => 'password']));
    }

    public function test_it_lists_every_stocks_declarations_with_company_and_sector(): void
    {
        $this->login();
        $banks = Sector::create(['name' => 'Commercial Banks']);
        $nabil = Stock::create(['symbol' => 'NABIL', 'company_name' => 'Nabil Bank', 'is_active' => true, 'sector_id' => $banks->id]);
        $chcl = Stock::create(['symbol' => 'CHCL', 'company_name' => 'Chilime', 'is_active' => true]);

        Dividend::create(['stock_id' => $nabil->id, 'fiscal_year' => '2080/2081', 'bonus_share_pct' => 10, 'cash_dividend_pct' => 5.26, 'total_dividend_pct' => 15.26, 'book_closure_date' => '2025-01-10 [Closed]']);
        Dividend::create(['stock_id' => $nabil->id, 'fiscal_year' => '2081/2082', 'bonus_share_pct' => 8, 'cash_dividend_pct' => 12, 'total_dividend_pct' => 20, 'announcement_date' => '2026-09-01']);
        Dividend::create(['stock_id' => $chcl->id, 'fiscal_year' => '2081/2082', 'cash_dividend_pct' => 6, 'total_dividend_pct' => 6]);

        $rows = $this->graphQL('{ marketDividends { symbol company_name sector fiscal_year bonus_share_pct cash_dividend_pct total_dividend_pct announcement_date book_closure_date } }')
            ->assertJsonMissingPath('errors')
            ->json('data.marketDividends');

        $this->assertCount(3, $rows);
        // Newest fiscal year first, biggest payout first within it.
        $this->assertSame(['NABIL 2081/2082', 'CHCL 2081/2082', 'NABIL 2080/2081'], array_map(fn ($r) => $r['symbol'].' '.$r['fiscal_year'], $rows));

        $this->assertSame('Nabil Bank', $rows[0]['company_name']);
        $this->assertSame('Commercial Banks', $rows[0]['sector']);
        $this->assertEquals(20.0, $rows[0]['total_dividend_pct']);
        $this->assertSame('2026-09-01', $rows[0]['announcement_date']);
        $this->assertNull($rows[1]['sector']);
        $this->assertNull($rows[1]['bonus_share_pct']);
        $this->assertSame('2025-01-10 [Closed]', $rows[2]['book_closure_date']); // the source's own text is kept
    }

    public function test_with_no_dividends_yet_it_is_an_empty_list_not_an_error(): void
    {
        $this->login();

        $this->graphQL('{ marketDividends { symbol } }')
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.marketDividends', []);
    }

    public function test_it_needs_a_login(): void
    {
        $this->assertSame(401, $this->graphQLStatus($this->graphQL('{ marketDividends { symbol } }')));
    }
}
