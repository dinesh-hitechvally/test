<?php

namespace Tests\Feature\DataQuality;

use App\Events\StockPricesUpdated;
use App\Models\DailyPrice;
use App\Models\DataQualityFlag;
use App\Models\Stock;
use App\Models\User;
use App\Services\DataSources\DailyPriceWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DataQualityFlagsTest extends TestCase
{
    use RefreshDatabase;

    private Stock $stock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stock = Stock::create(['symbol' => 'TEST', 'company_name' => 'Test Co', 'is_active' => true]);
    }

    public function test_stock_prices_updated_flags_a_bad_row_inline(): void
    {
        DailyPrice::create([
            'stock_id' => $this->stock->id, 'trade_date' => now()->toDateString(),
            'open_price' => 100, 'high_price' => 90, 'low_price' => 98, 'close_price' => 95, // high < low
            'volume' => 1000, 'turnover' => 100000,
        ]);

        StockPricesUpdated::dispatch([$this->stock->id], 'test');

        $this->assertTrue(DataQualityFlag::where('stock_id', $this->stock->id)->where('check_type', 'invalid_ohlc')->exists());
    }

    public function test_daily_price_writer_flags_an_overwrite_of_an_old_row(): void
    {
        DailyPrice::create([
            'stock_id' => $this->stock->id, 'trade_date' => now()->subDays(30)->toDateString(),
            'open_price' => 100, 'high_price' => 105, 'low_price' => 98, 'close_price' => 100,
            'volume' => 1000, 'turnover' => 100000,
        ]);

        app(DailyPriceWriter::class)->write(now()->subDays(30)->toDateString(), [[
            'symbol' => 'TEST', 'open' => 100, 'high' => 106, 'low' => 98, 'close' => 104, 'volume' => 1200, 'turnover' => 120000,
        ]], createMissingStocks: false);

        $this->assertTrue(DataQualityFlag::where('check_type', 'corrected_historical_value')->exists());
    }

    public function test_graphql_lists_and_resolves_flags(): void
    {
        Sanctum::actingAs(User::create(['name' => 'T', 'email' => 't@example.com', 'password' => 'password']));
        $flag = DataQualityFlag::create([
            'stock_id' => $this->stock->id, 'trade_date' => now()->toDateString(),
            'check_type' => 'invalid_ohlc', 'severity' => 'critical', 'message' => 'High below low.',
        ]);

        $this->graphQL('{ dataQualityFlags { id check_type severity stock { symbol } } }')
            ->assertJsonPath('data.dataQualityFlags.0.check_type', 'invalid_ohlc')
            ->assertJsonPath('data.dataQualityFlags.0.stock.symbol', 'TEST');

        $this->graphQL('mutation ($id: Int!) { resolveDataQualityFlag(id: $id) { resolved_at } }', ['id' => $flag->id])
            ->assertJsonPath('data.resolveDataQualityFlag.resolved_at', fn ($v) => $v !== null);

        // Resolved by default drops out of the unresolved list.
        $this->graphQL('{ dataQualityFlags { id } }')->assertJsonCount(0, 'data.dataQualityFlags');
        $this->graphQL('{ dataQualityFlags(resolved: true) { id } }')->assertJsonCount(1, 'data.dataQualityFlags');
    }

    public function test_the_cron_route_runs_the_market_wide_checks(): void
    {
        config(['services.cron.secret' => 'test-secret']);

        $this->get('/cron/reports/data-quality-scan?key=test-secret')
            ->assertOk()
            ->assertSeeText('$ scan-data-quality')
            ->assertSeeText('[ok]');
    }
}
