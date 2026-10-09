<?php

namespace Tests\Feature\Stocks;

use App\Models\DailyPrice;
use App\Models\Stock;
use App\Services\Reports\PriceStatisticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** changeFor() answers for one stock exactly what priceChanges() answers for it, without ranking the whole price table. */
class PriceChangeForStockTest extends TestCase
{
    use RefreshDatabase;

    private function prices(Stock $stock, array $closes): void
    {
        foreach ($closes as $i => $close) {
            DailyPrice::create(['stock_id' => $stock->id, 'trade_date' => now()->subDays(count($closes) - $i)->toDateString(), 'open_price' => $close, 'high_price' => $close, 'low_price' => $close, 'close_price' => $close, 'volume' => 1, 'turnover' => $close * 10]);
        }
    }

    public function test_it_matches_the_market_wide_figure_for_the_same_stock(): void
    {
        $a = Stock::create(['symbol' => 'AAA', 'company_name' => 'A', 'is_active' => true]);
        $b = Stock::create(['symbol' => 'BBB', 'company_name' => 'B', 'is_active' => true]);
        $this->prices($a, [100, 102, 105]);
        $this->prices($b, [50, 40]);

        $service = app(PriceStatisticsService::class);

        foreach ([$a, $b] as $stock) {
            $this->assertEquals($service->priceChanges()->get($stock->id), $service->changeFor($stock));
        }

        $this->assertEquals(2.94, $service->changeFor($a)['change_pct']); // (105 - 102) / 102
        $this->assertEquals(-20.0, $service->changeFor($b)['change_pct']);
    }

    public function test_a_stock_with_one_price_has_no_change_and_one_with_none_has_no_figure(): void
    {
        $one = Stock::create(['symbol' => 'ONE', 'company_name' => 'One', 'is_active' => true]);
        $none = Stock::create(['symbol' => 'NONE', 'company_name' => 'None', 'is_active' => true]);
        $this->prices($one, [100]);

        $service = app(PriceStatisticsService::class);

        $this->assertNull($service->changeFor($one)['change_pct']);
        $this->assertEquals(100.0, $service->changeFor($one)['close']);
        $this->assertNull($service->changeFor($none));
    }
}
