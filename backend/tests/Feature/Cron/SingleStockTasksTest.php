<?php

namespace Tests\Feature\Cron;

use App\Models\Stock;
use App\Models\StockFundamental;
use App\Services\DataSources\MeroLagani\MeroLaganiFundamentalsService;
use App\Services\DataSources\NepalStock\NepalStockClient;
use App\Services\DataSources\NepalStock\NepalStockCorporateActionsService;
use App\Services\DataSources\NepalStock\NepalStockSecurityResolver;
use App\Services\DataSources\NepalStock\NepalStockTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/** /cron/fetch/dividends/{symbol} and /cron/fetch/fundamentals/{symbol}: one named stock, refreshed on demand. */
class SingleStockTasksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.cron.secret' => 'test-secret']);

        $client = new NepalStockClient(app(NepalStockTokenService::class));
        $this->app->instance(NepalStockCorporateActionsService::class, new class($client, new NepalStockSecurityResolver($client)) extends NepalStockCorporateActionsService
        {
            public array $fetched = [];

            public function fetchDividends(Stock $stock): array
            {
                $this->fetched[] = $stock->symbol;

                if ($stock->symbol === 'BAD') {
                    throw new RuntimeException('connection reset');
                }

                return ['dividends' => 4];
            }
        });

        $this->app->instance(MeroLaganiFundamentalsService::class, new class extends MeroLaganiFundamentalsService
        {
            public function syncOne(Stock $stock): StockFundamental
            {
                return StockFundamental::updateOrCreate(['stock_id' => $stock->id], ['eps' => 12.5, 'pe_ratio' => 30.2, 'book_value' => 210.0, 'fetched_at' => now()]);
            }
        });

        foreach (['NABIL', 'BAD'] as $symbol) {
            Stock::create(['symbol' => $symbol, 'company_name' => $symbol, 'is_active' => true]);
        }
    }

    public function test_dividends_for_one_stock_are_fetched_and_it_is_marked_done(): void
    {
        $this->get('/cron/fetch/dividends/nabil?key=test-secret')
            ->assertOk()
            ->assertSeeText('$ fetch-stock-dividends NABIL')
            ->assertSeeText('NABIL: 4 dividend row(s) imported.')
            ->assertSeeText('[ok]');

        $this->assertNotNull(Stock::firstWhere('symbol', 'NABIL')->scrapeStatus->dividend_fetched_at);
        $this->assertNull(Stock::firstWhere('symbol', 'BAD')->scrapeStatus?->dividend_fetched_at); // other stocks untouched
    }

    public function test_dividends_can_be_refetched_for_a_stock_that_is_already_done(): void
    {
        Stock::firstWhere('symbol', 'NABIL')->ensureScrapeStatus()->markDividendFetched();

        $this->get('/cron/fetch/dividends/NABIL?key=test-secret')->assertSeeText('NABIL: 4 dividend row(s) imported.');
        $this->assertSame(['NABIL'], app(NepalStockCorporateActionsService::class)->fetched);
    }

    public function test_a_failed_dividend_fetch_is_flagged_on_the_stock(): void
    {
        $this->get('/cron/fetch/dividends/BAD?key=test-secret')
            ->assertOk()
            ->assertSeeText('Failed: connection reset')
            ->assertSeeText('[failed]');

        $this->assertSame('connection reset', Stock::firstWhere('symbol', 'BAD')->scrapeStatus->dividend_error);
    }

    public function test_an_unknown_symbol_is_reported_not_crashed(): void
    {
        $this->get('/cron/fetch/dividends/NOPE?key=test-secret')->assertSeeText('No stock found for symbol [NOPE].');
        $this->get('/cron/fetch/fundamentals/NOPE?key=test-secret')->assertSeeText('No stock found for symbol [NOPE].');
    }

    public function test_fundamentals_for_one_stock_are_refreshed_even_when_fresh(): void
    {
        $nabil = Stock::firstWhere('symbol', 'NABIL');
        StockFundamental::create(['stock_id' => $nabil->id, 'eps' => 1, 'fetched_at' => now()]); // fetched just now: the bulk job would skip it

        $this->get('/cron/fetch/fundamentals/NABIL?key=test-secret')
            ->assertOk()
            ->assertSeeText('$ fetch-stock-fundamentals NABIL')
            ->assertSeeText('NABIL: EPS=12.5000 PE=30.2000 BookValue=210.0000')
            ->assertSeeText('[ok]');

        $this->assertEquals(12.5, (float) $nabil->fundamental()->first()->eps);
    }

    public function test_the_routes_need_the_cron_key(): void
    {
        $this->get('/cron/fetch/dividends/NABIL')->assertForbidden();
        $this->get('/cron/fetch/fundamentals/NABIL')->assertForbidden();
    }
}
