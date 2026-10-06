<?php

namespace Tests\Feature\Stocks;

use App\Models\DailyPrice;
use App\Models\Sector;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SectorPerformanceQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sector_rollup_is_available_on_its_own(): void
    {
        Sanctum::actingAs(User::create(['name' => 'T', 'email' => 't@example.com', 'password' => 'password']));
        $banking = Sector::create(['name' => 'Banking']);

        foreach (['AAA' => [100, 110], 'BBB' => [200, 190]] as $symbol => [$before, $after]) {
            $stock = Stock::create(['symbol' => $symbol, 'company_name' => $symbol, 'sector_id' => $banking->id, 'is_active' => true]);
            foreach (['2026-10-01' => $before, '2026-10-02' => $after] as $date => $close) {
                DailyPrice::create(['stock_id' => $stock->id, 'trade_date' => $date, 'open_price' => $close, 'high_price' => $close, 'low_price' => $close, 'close_price' => $close, 'volume' => 10, 'turnover' => $close * 10]);
            }
        }

        $this->graphQL('{ sectorPerformance { sector stock_count advancing declining avg_change_pct total_turnover } }')
            ->assertJsonPath('data.sectorPerformance.0.sector', 'Banking')
            ->assertJsonPath('data.sectorPerformance.0.stock_count', 2)
            ->assertJsonPath('data.sectorPerformance.0.advancing', 1)
            ->assertJsonPath('data.sectorPerformance.0.declining', 1)
            ->assertJsonPath('data.sectorPerformance.0.avg_change_pct', 2.5) // (+10% and -5%) / 2
            ->assertJsonPath('data.sectorPerformance.0.total_turnover', 3000);
    }
}
