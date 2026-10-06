<?php

namespace Tests\Feature\Stocks;

use App\Models\DailyPrice;
use App\Models\Sector;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SectorDetailQueryTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'query ($id: Int!) { sectorDetail(id: $id) {
        sector_id sector totals { stock_count advancing declining } avg_change_pct
        stocks { symbol change_pct } top_gainers { symbol } top_losers { symbol }
    } }';

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::create(['name' => 'T', 'email' => 't@example.com', 'password' => 'password']));
    }

    private function sectorWithStocks(): Sector
    {
        $banking = Sector::create(['name' => 'Banking']);
        $other = Sector::create(['name' => 'Hydro']);

        foreach ([['AAA', $banking, 100, 110], ['BBB', $banking, 200, 190], ['ZZZ', $other, 50, 60]] as [$symbol, $sector, $before, $after]) {
            $stock = Stock::create(['symbol' => $symbol, 'company_name' => $symbol, 'sector_id' => $sector->id, 'is_active' => true]);
            foreach (['2026-10-01' => $before, '2026-10-02' => $after] as $date => $close) {
                DailyPrice::create(['stock_id' => $stock->id, 'trade_date' => $date, 'open_price' => $close, 'high_price' => $close, 'low_price' => $close, 'close_price' => $close, 'volume' => 10, 'turnover' => 100]);
            }
        }

        return $banking;
    }

    public function test_a_sector_is_found_by_its_id_with_only_its_own_stocks(): void
    {
        $banking = $this->sectorWithStocks();

        $this->graphQL(self::QUERY, ['id' => $banking->id])
            ->assertJsonPath('data.sectorDetail.sector_id', $banking->id)
            ->assertJsonPath('data.sectorDetail.sector', 'Banking')
            ->assertJsonPath('data.sectorDetail.totals.stock_count', 2)
            ->assertJsonPath('data.sectorDetail.totals.advancing', 1)
            ->assertJsonPath('data.sectorDetail.totals.declining', 1)
            ->assertJsonPath('data.sectorDetail.avg_change_pct', 2.5)
            ->assertJsonPath('data.sectorDetail.top_gainers.0.symbol', 'AAA')
            ->assertJsonPath('data.sectorDetail.top_losers.0.symbol', 'BBB')
            ->assertJsonCount(2, 'data.sectorDetail.stocks'); // ZZZ belongs to Hydro
    }

    public function test_an_unknown_sector_id_is_a_404(): void
    {
        $this->sectorWithStocks();

        $response = $this->graphQL(self::QUERY, ['id' => 9999]);

        $this->assertSame(404, $this->graphQLStatus($response));
    }

    public function test_a_sector_with_no_stocks_yet_returns_zeros_not_an_error(): void
    {
        $empty = Sector::create(['name' => 'Brand New']);

        $this->graphQL(self::QUERY, ['id' => $empty->id])
            ->assertJsonPath('data.sectorDetail.sector', 'Brand New')
            ->assertJsonPath('data.sectorDetail.totals.stock_count', 0)
            ->assertJsonPath('data.sectorDetail.avg_change_pct', null)
            ->assertJsonCount(0, 'data.sectorDetail.stocks');
    }

    public function test_the_sector_list_carries_each_sectors_id_for_the_link(): void
    {
        $banking = $this->sectorWithStocks();
        Stock::create(['symbol' => 'NOSEC', 'company_name' => 'No sector', 'is_active' => true]);

        $rows = collect($this->graphQL('{ sectorPerformance { sector_id sector } }')->json('data.sectorPerformance'));

        $this->assertSame($banking->id, $rows->firstWhere('sector', 'Banking')['sector_id']);
        $this->assertNull($rows->firstWhere('sector', 'No Sector')['sector_id']);
    }
}
