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

    public function test_fetch_histories_fetches_one_stock_per_run(): void
    {
        $this->app->instance(PriceHistorySource::class, new class implements PriceHistorySource
        {
            public function fetchHistory(Stock $stock): array
            {
                if ($stock->symbol === 'BAD') {
                    $stock->ensureScrapeStatus()->flagHistoryError('source down'); // as the real service does
                    throw new RuntimeException('source down');
                }

                $stock->ensureScrapeStatus()->markHistoryFetched();

                return ['rows_imported' => 10, 'oldest_date' => '2020-01-01', 'newest_date' => '2020-01-10'];
            }
        });

        foreach (['AAA', 'BAD', 'CCC', 'DDD', 'EEE', 'FFF', 'GGG'] as $symbol) {
            Stock::create(['symbol' => $symbol, 'company_name' => $symbol, 'is_active' => true]);
        }

        // Run 1: only the first pending stock, however many are waiting (and ?limit= can't raise it).
        $this->get('/cron/fetch/histories?key=test-secret&limit=5')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertSeeText('$ fetch-histories')
            ->assertSeeText('AAA: 10 rows imported (2020-01-01 to 2020-01-10).')
            ->assertDontSeeText('BAD')
            ->assertDontSeeText('CCC')
            ->assertSeeText('Done — 1 stock(s) processed, 0 failed.')
            ->assertSeeText('6 stock(s) still pending — run it again for the next.')
            ->assertSeeText('[ok]');

        // Run 2: the next one. It fails, is flagged, and is not retried by later runs.
        $this->get('/cron/fetch/histories?key=test-secret')
            ->assertSeeText('BAD: failed — source down')
            ->assertSeeText('Done — 0 stock(s) processed, 1 failed.')
            ->assertSeeText('5 stock(s) still pending');

        $this->get('/cron/fetch/histories?key=test-secret')
            ->assertSeeText('CCC: 10 rows imported')
            ->assertDontSeeText('BAD')
            ->assertSeeText('4 stock(s) still pending');

        $this->assertSame(2, Stock::whereHas('scrapeStatus', fn ($q) => $q->whereNotNull('history_fetched_at'))->count());
    }

    public function test_the_last_pending_stock_reports_nothing_left(): void
    {
        $this->app->instance(PriceHistorySource::class, new class implements PriceHistorySource
        {
            public function fetchHistory(Stock $stock): array
            {
                $stock->ensureScrapeStatus()->markHistoryFetched();

                return ['rows_imported' => 3, 'oldest_date' => '2020-01-01', 'newest_date' => '2020-01-03'];
            }
        });
        Stock::create(['symbol' => 'ONLY', 'company_name' => 'Only', 'is_active' => true]);

        $this->get('/cron/fetch/histories?key=test-secret')
            ->assertSeeText('ONLY: 3 rows imported')
            ->assertDontSeeText('still pending');
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

    public function test_ai_opinions_do_one_stock_per_run_and_report_how_many_are_left(): void
    {
        $this->app->instance(AiOpinionProvider::class, new class implements AiOpinionProvider
        {
            public function isConfigured(): bool
            {
                return true;
            }

            public function requestOpinion(string $prompt): array
            {
                return ['verdict' => 'buy', 'confidence' => 'high', 'reasoning' => 'test'];
            }
        });

        foreach (['AAA', 'BBB', 'CCC'] as $symbol) {
            $stock = Stock::create(['symbol' => $symbol, 'company_name' => $symbol, 'is_active' => true]);
            Signal::create(['stock_id' => $stock->id, 'trade_date' => '2024-01-01', 'signal' => 'hold', 'score' => 0, 'reasons' => [], 'rule_keys' => []]);
        }

        $this->get('/cron/generate/ai-opinions?key=test-secret')
            ->assertOk()
            ->assertSeeText('AAA: buy (high confidence)')
            ->assertDontSeeText('BBB')
            ->assertSeeText('2 stock(s) still pending');

        $this->assertSame(1, \App\Models\AiStockOpinion::count());
    }

    private function aiStock(): Stock
    {
        $stock = Stock::create(['symbol' => 'AAA', 'company_name' => 'A', 'is_active' => true]);
        Signal::create(['stock_id' => $stock->id, 'trade_date' => '2024-01-01', 'signal' => 'hold', 'score' => 0, 'reasons' => [], 'rule_keys' => []]);

        return $stock;
    }

    /** A provider that does whatever the test says on each call. */
    private function aiProvider(callable $behaviour): object
    {
        $provider = new class($behaviour) implements AiOpinionProvider
        {
            public int $calls = 0;

            public function __construct(private $behaviour) {}

            public function isConfigured(): bool
            {
                return true;
            }

            public function requestOpinion(string $prompt): array
            {
                $this->calls++;

                return ($this->behaviour)($this->calls);
            }
        };
        $this->app->instance(AiOpinionProvider::class, $provider);

        return $provider;
    }

    public function test_a_rate_limit_leaves_the_stock_pending_at_once_without_waiting_or_a_failure(): void
    {
        $provider = $this->aiProvider(fn () => throw new RequestException(new Response(new PsrResponse(429, ['Retry-After' => '30']))));
        $stock = $this->aiStock();

        $started = microtime(true);
        $this->get('/cron/generate/ai-opinions?key=test-secret')
            ->assertOk()
            ->assertSeeText("AAA: Groq's rate limit is in effect")
            ->assertSeeText('left pending')
            ->assertSeeText('[ok]'); // not a failed run, so nobody is alerted

        $this->assertLessThan(5, microtime(true) - $started);      // it did not sleep through Retry-After
        $this->assertSame(1, $provider->calls);                      // and did not retry inside the request
        $this->assertSame(0, \App\Models\AiStockOpinion::count()); // no opinion and no error: still pending, no 6-hour cooldown
        $this->assertTrue(app(\App\Tasks\Ai\GenerateAiOpinionsTask::class)->hasPendingStock());
    }

    public function test_a_groq_that_does_not_answer_in_time_leaves_the_stock_pending_too(): void
    {
        $provider = $this->aiProvider(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out'));
        $this->aiStock();

        $this->get('/cron/generate/ai-opinions?key=test-secret')
            ->assertOk()
            ->assertSeeText('Groq did not answer in time')
            ->assertSeeText('[ok]');

        $this->assertSame(1, $provider->calls);
        $this->assertSame(0, \App\Models\AiStockOpinion::count());
    }

    public function test_a_real_groq_error_is_recorded_and_the_stock_rests_for_six_hours(): void
    {
        $this->aiProvider(fn () => throw new RequestException(new Response(new PsrResponse(401, [], '{"error":"invalid api key"}'))));
        $stock = $this->aiStock();

        $this->get('/cron/generate/ai-opinions?key=test-secret')->assertOk()->assertSeeText('AAA: failed');

        $this->assertNotNull($stock->aiOpinion()->first()->error);
        $this->assertFalse(app(\App\Tasks\Ai\GenerateAiOpinionsTask::class)->hasPendingStock()); // on cooldown, not retried every minute
    }

    public function test_a_good_answer_reports_how_long_it_took(): void
    {
        $this->aiProvider(fn () => ['verdict' => 'buy', 'confidence' => 'high', 'reasoning' => 'test']);
        $this->aiStock();

        $this->get('/cron/generate/ai-opinions?key=test-secret')->assertOk()->assertSeeText('AAA: buy (high confidence) in ');
    }

    public function test_the_groq_provider_never_retries_inside_the_request(): void
    {
        config(['services.groq.api_key' => 'k', 'services.groq.model' => 'm']);
        \Illuminate\Support\Facades\Http::fake(['api.groq.com/*' => \Illuminate\Support\Facades\Http::sequence()->push('rate limited', 429)->push(['choices' => []], 200)]);

        try {
            app(\App\Services\Ai\GroqOpinionProvider::class)->requestOpinion('prompt');
            $this->fail('Expected the 429 to throw.');
        } catch (RequestException $e) {
            $this->assertSame(429, $e->response->status());
        }

        \Illuminate\Support\Facades\Http::assertSentCount(1);
    }
}
