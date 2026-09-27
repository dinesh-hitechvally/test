<?php

namespace Tests\Feature\Cron;

use App\Contracts\AiOpinionProvider;
use App\Contracts\PriceHistorySource;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class StockBatchJobsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.cron.secret' => 'test-secret']);
    }

    public function test_cron_routes_reject_a_missing_key(): void
    {
        $this->get('/cron/scrape/fetch-histories')->assertForbidden();
    }

    public function test_fetch_histories_processes_one_batch_and_reports_the_remainder(): void
    {
        $this->app->instance(PriceHistorySource::class, new class implements PriceHistorySource
        {
            public function fetchHistory(Stock $stock): array
            {
                if ($stock->symbol === 'BAD') {
                    throw new RuntimeException('source down');
                }

                $stock->ensureScrapeStatus()->markHistoryFetched();

                return ['rows_imported' => 10, 'oldest_date' => '2020-01-01', 'newest_date' => '2020-01-10'];
            }
        });

        Stock::create(['symbol' => 'AAA', 'company_name' => 'A', 'is_active' => true]);
        Stock::create(['symbol' => 'BAD', 'company_name' => 'B', 'is_active' => true]);
        Stock::create(['symbol' => 'CCC', 'company_name' => 'C', 'is_active' => true]);

        $this->get('/cron/scrape/fetch-histories?key=test-secret&limit=2')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertSeeText('AAA: 10 rows imported (2020-01-01 to 2020-01-10).')
            ->assertSeeText('BAD: failed — source down')
            // AAA is done; BAD failed without flagging an error, so it's still pending alongside CCC.
            ->assertSeeText('2 stock(s) still missing history');
    }

    public function test_fetch_histories_says_so_when_nothing_is_pending(): void
    {
        $this->get('/cron/scrape/fetch-histories?key=test-secret')
            ->assertOk()
            ->assertSeeText('No stocks are missing full history.');
    }

    public function test_ai_opinions_are_skipped_when_no_provider_is_configured(): void
    {
        $this->app->instance(AiOpinionProvider::class, new class implements AiOpinionProvider
        {
            public function isConfigured(): bool
            {
                return false;
            }

            public function requestOpinion(string $prompt): array
            {
                throw new RuntimeException('should never be called');
            }
        });

        $this->get('/cron/scrape/ai-opinions?key=test-secret')
            ->assertOk()
            ->assertSeeText('AI opinion is not configured on this instance');
    }
}
