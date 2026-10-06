<?php

namespace Tests\Feature\Cron;

use App\Models\Stock;
use App\Services\DataSources\NepalStock\NepalStockClient;
use App\Services\DataSources\NepalStock\NepalStockCorporateActionsService;
use App\Services\DataSources\NepalStock\NepalStockSecurityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class SyncDividendsTaskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.cron.secret' => 'test-secret']);

        // nepalstock.com stand-in: AAA and CCC have dividends, BBB's request fails.
        $client = new NepalStockClient(app(\App\Services\DataSources\NepalStock\NepalStockTokenService::class));
        $this->app->instance(NepalStockCorporateActionsService::class, new class($client, new NepalStockSecurityResolver($client)) extends NepalStockCorporateActionsService
        {
            public function fetchDividends(Stock $stock): array
            {
                return match ($stock->symbol) {
                    'AAA' => ['dividends' => 3],
                    'BBB' => throw new RuntimeException('connection reset'),
                    default => ['dividends' => 0],
                };
            }
        });

        foreach (['AAA', 'BBB', 'CCC'] as $symbol) {
            Stock::create(['symbol' => $symbol, 'company_name' => $symbol, 'is_active' => true]);
        }
    }

    private function ping(): TestResponse
    {
        return $this->get('/cron/fetch/dividends?key=test-secret')->assertOk();
    }

    public function test_it_fetches_one_stock_per_run_and_says_how_many_are_left(): void
    {
        $this->ping()
            ->assertSeeText('AAA: 3 dividend row(s) imported.')
            ->assertDontSeeText('BBB')
            ->assertSeeText('Done — 1 stock(s) processed, 0 failed.')
            ->assertSeeText('2 stock(s) still pending — run it again for the next.');

        $this->assertNotNull(Stock::firstWhere('symbol', 'AAA')->scrapeStatus->dividend_fetched_at);
        $this->assertNull(Stock::firstWhere('symbol', 'CCC')->scrapeStatus?->dividend_fetched_at); // not touched yet
    }

    public function test_a_failing_stock_goes_to_the_back_so_it_cannot_block_the_rest(): void
    {
        $this->ping(); // AAA

        $this->ping() // BBB fails and is flagged...
            ->assertSeeText('BBB: failed — connection reset')
            ->assertSeeText('2 stock(s) still pending');

        // ...so the next run moves on to CCC instead of retrying BBB.
        $this->ping()
            ->assertSeeText('CCC: 0 dividend row(s) imported.')
            ->assertDontSeeText('BBB')
            ->assertSeeText('1 stock(s) still pending');

        // Only BBB is left, and it is retried rather than skipped forever.
        $this->ping()->assertSeeText('BBB: failed — connection reset');
    }

    public function test_it_says_so_when_nothing_is_left(): void
    {
        foreach (Stock::all() as $stock) {
            $stock->ensureScrapeStatus()->markDividendFetched();
        }

        $this->ping()->assertSeeText('No stocks are missing dividend data.');
    }
}
