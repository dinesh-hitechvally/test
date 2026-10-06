<?php

namespace Tests\Feature\Cron;

use App\Contracts\AiOpinionProvider;
use App\Contracts\PriceHistorySource;
use App\Models\Signal;
use App\Models\Stock;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Tests\TestCase;

class PerStockTasksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.cron.secret' => 'test-secret']);
    }

    public function test_cron_routes_reject_a_missing_key(): void
    {
        $this->get('/cron/fetch/histories')->assertForbidden();
    }

    public function test_fetch_histories_processes_every_pending_stock_in_one_run(): void
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

        foreach (['AAA', 'BAD', 'CCC', 'DDD', 'EEE', 'FFF', 'GGG'] as $symbol) {
            Stock::create(['symbol' => $symbol, 'company_name' => $symbol, 'is_active' => true]);
        }

        // ?limit= is gone — it's ignored if a pinger still sends it.
        $this->get('/cron/fetch/histories?key=test-secret&limit=1')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertSeeText('$ fetch-histories')
            ->assertSeeText('AAA: 10 rows imported (2020-01-01 to 2020-01-10).')
            ->assertSeeText('GGG: 10 rows imported')
            ->assertSeeText('BAD: failed — source down')
            ->assertSeeText('Done — 6 stock(s) processed, 1 failed.')
            ->assertSeeText('[ok]');
    }

    public function test_fetch_histories_says_so_when_nothing_is_pending(): void
    {
        $this->get('/cron/fetch/histories?key=test-secret')
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

        $this->get('/cron/generate/ai-opinions?key=test-secret')
            ->assertOk()
            ->assertSeeText('AI opinion is not configured on this instance');
    }

    public function test_ai_opinions_wait_out_a_rate_limit_instead_of_skipping_the_stock(): void
    {
        $provider = new class implements AiOpinionProvider
        {
            public int $calls = 0;

            public function isConfigured(): bool
            {
                return true;
            }

            public function requestOpinion(string $prompt): array
            {
                if (++$this->calls === 1) {
                    throw new RequestException(new Response(new PsrResponse(429, ['Retry-After' => '1'])));
                }

                return ['verdict' => 'hold', 'confidence' => 'low', 'reasoning' => 'test'];
            }
        };
        $this->app->instance(AiOpinionProvider::class, $provider);

        $stock = Stock::create(['symbol' => 'AAA', 'company_name' => 'A', 'is_active' => true]);
        Signal::create(['stock_id' => $stock->id, 'trade_date' => '2024-01-01', 'signal' => 'hold', 'score' => 0, 'reasons' => [], 'rule_keys' => []]);

        $this->get('/cron/generate/ai-opinions?key=test-secret')
            ->assertOk()
            ->assertSeeText('AAA: hold (low confidence)')
            ->assertSeeText('Done — 1 stock(s) processed, 0 failed.');

        $this->assertSame(2, $provider->calls);
        $this->assertSame('hold', $stock->aiOpinion()->first()->verdict);
    }
}
