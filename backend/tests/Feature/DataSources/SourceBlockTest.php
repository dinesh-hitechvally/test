<?php

namespace Tests\Feature\DataSources;

use App\Events\TaskFailed;
use App\Models\ScrapeLog;
use App\Models\SourceBlock;
use App\Services\DataSources\SourceBlockedException;
use App\Services\DataSources\SourceFailover;
use App\Tasks\DataQuality\CheckSourcesTask;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * A website that blocks us is recorded in source_blocks and every request to it is skipped; the other websites
 * carry on, and the block lifts when the website answers again.
 */
class SourceBlockTest extends TestCase
{
    use RefreshDatabase;

    private const NEPSE = 'https://www.nepalstock.com/api/nots/security';

    private const SHARESANSAR = 'https://www.sharesansar.com/live-trading';

    /** Requests that really reached the (faked) network — a request the block skips never gets here. */
    private int $hits = 0;

    private const FILTER_PAGE = '<html><title>Web Page Blocked</title><h1>Web Page Blocked</h1><p>Web Filter Service Error: invalid license</p></html>';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.cron.secret' => 'k']);
    }

    private function fakeOk(): void
    {
        Http::fake(['*' => function () {
            $this->hits++;

            return Http::response('ok', 200);
        }]);
    }

    private function block(string $website, int $minutes = 30): SourceBlock
    {
        return SourceBlock::create([
            'website' => $website, 'is_blocked' => true, 'reason' => 'HTTP 403: Web Page Blocked', 'http_status' => 403,
            'consecutive_failures' => 1, 'times_blocked' => 1, 'blocked_at' => now(), 'blocked_until' => now()->addMinutes($minutes),
        ]);
    }

    // ----------------------------------------------------------- how a website gets blocked

    public function test_a_403_blocks_that_website_and_records_why(): void
    {
        Http::fake(['*' => Http::response(self::FILTER_PAGE, 403)]);

        Http::get(self::NEPSE);

        $row = SourceBlock::where('website', 'nepalstock.com')->sole();
        $this->assertTrue($row->is_blocked);
        $this->assertSame(403, $row->http_status);
        $this->assertStringContainsString('Web Page Blocked', $row->reason);
        $this->assertSame(1, $row->times_blocked);
        $this->assertEqualsWithDelta(30, now()->diffInMinutes($row->blocked_until), 1);

        $log = ScrapeLog::sole(); // recorded once, when it started
        $this->assertSame('nepalstock.com', $log->source);
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('is blocking us', $log->message);
    }

    public function test_a_block_page_is_a_block_even_when_it_comes_back_as_200(): void
    {
        Http::fake(['*' => Http::response(self::FILTER_PAGE, 200)]);

        Http::get(self::SHARESANSAR);

        $this->assertTrue(SourceBlock::where('website', 'sharesansar.com')->sole()->is_blocked);
    }

    public function test_an_ordinary_404_is_not_a_block(): void
    {
        Http::fake(['*' => Http::response('Not found', 404)]);

        Http::get(self::NEPSE);

        $this->assertSame(0, SourceBlock::count());
    }

    public function test_an_outage_only_blocks_after_several_failures_in_a_row(): void
    {
        Http::fake(['*' => Http::response('Bad gateway', 502)]);

        Http::get(self::NEPSE);
        Http::get(self::NEPSE);
        $this->assertFalse(SourceBlock::where('website', 'nepalstock.com')->sole()->is_blocked); // two failures: not yet
        $this->assertSame(2, SourceBlock::sole()->consecutive_failures);

        Http::get(self::NEPSE);
        $this->assertTrue(SourceBlock::where('website', 'nepalstock.com')->sole()->is_blocked);
    }

    public function test_a_success_between_failures_resets_the_count(): void
    {
        Http::fake(['*' => Http::sequence()->push('x', 502)->push('x', 502)->push('ok', 200)->push('x', 502)]);

        foreach (range(1, 4) as $_) {
            Http::get(self::NEPSE);
        }

        $row = SourceBlock::where('website', 'nepalstock.com')->sole();
        $this->assertFalse($row->is_blocked);
        $this->assertSame(1, $row->consecutive_failures);
    }

    public function test_an_interrupted_certificate_blocks_the_website(): void
    {
        // What a web filter that re-signs HTTPS causes: curl error 60, "unable to get local issuer certificate".
        Http::fake(['*' => fn () => throw new ConnectException('cURL error 60: SSL certificate problem', new Request('GET', self::NEPSE), null, ['errno' => 60])]);

        try {
            Http::get(self::NEPSE);
        } catch (ConnectionException) {
        }

        $this->assertTrue(SourceBlock::where('website', 'nepalstock.com')->sole()->is_blocked);
    }

    public function test_a_website_that_is_not_tracked_is_never_blocked(): void
    {
        Http::fake(['*' => Http::response('Forbidden', 403)]);

        Http::get('https://api.groq.com/openai/v1/models');

        $this->assertSame(0, SourceBlock::count());
    }

    // ----------------------------------------------------------- what a block does

    public function test_a_blocked_website_is_not_sent_any_request_and_the_others_still_are(): void
    {
        $this->block('nepalstock.com');
        $this->fakeOk();

        try {
            Http::get(self::NEPSE);
            $this->fail('The request to the blocked website should have been skipped.');
        } catch (ConnectionException $e) {
            $this->assertNotNull(SourceBlockedException::in($e));
            $this->assertStringContainsString('paused until', $e->getMessage());
        }

        $this->assertSame(0, $this->hits);

        $this->assertSame('ok', Http::get(self::SHARESANSAR)->body()); // sharesansar.com is a different website
        $this->assertSame(1, $this->hits);
    }

    public function test_all_of_a_blocked_websites_addresses_are_skipped(): void
    {
        $this->block('nepalstock.com');
        $this->fakeOk();

        foreach (['https://www.nepalstock.com/api/a', 'https://nepalstock.com/b', 'https://sub.nepalstock.com/c'] as $url) {
            $this->assertNotNull($this->skipped($url));
        }

        $this->assertSame(0, $this->hits);
    }

    public function test_the_next_request_after_the_pause_is_the_retry_and_success_lifts_the_block(): void
    {
        $this->block('nepalstock.com', minutes: 30);
        Http::fake(['*' => Http::response('ok', 200)]);

        $this->travel(31)->minutes();
        $this->assertSame('ok', Http::get(self::NEPSE)->body()); // sent: the pause is over

        $row = SourceBlock::where('website', 'nepalstock.com')->sole();
        $this->assertFalse($row->is_blocked);
        $this->assertSame(0, $row->consecutive_failures);
        $this->assertStringContainsString('reachable again', ScrapeLog::sole()->message);
    }

    public function test_a_failed_retry_blocks_it_again_for_another_pause(): void
    {
        $this->block('nepalstock.com', minutes: 30);
        Http::fake(['*' => Http::response(self::FILTER_PAGE, 403)]);

        $this->travel(31)->minutes();
        Http::get(self::NEPSE);

        $row = SourceBlock::where('website', 'nepalstock.com')->sole();
        $this->assertTrue($row->is_blocked);
        $this->assertTrue($row->blocked_until->isFuture());
        $this->assertSame(2, $row->times_blocked);
    }

    public function test_the_check_sources_probe_goes_through_a_block_and_lifts_it_when_the_website_answers(): void
    {
        $this->block('nepalstock.com');
        $this->fakeOk();

        $report = app(CheckSourcesTask::class)->handle();

        $this->assertFalse(SourceBlock::where('website', 'nepalstock.com')->sole()->is_blocked);
        $this->assertMatchesRegularExpression('/nepalstock\.com\s+ok/', $report);
        $this->assertMatchesRegularExpression('/sharesansar\.com\s+ok/', $report);
        $this->assertSame(3, $this->hits); // one probe per website, including the blocked one
    }

    public function test_the_check_sources_report_names_the_website_that_blocks_us(): void
    {
        Http::fake([
            '*nepalstock.com*' => Http::response(self::FILTER_PAGE, 403),
            '*' => Http::response('ok', 200),
        ]);

        $report = app(CheckSourcesTask::class)->handle();

        $this->assertMatchesRegularExpression('/nepalstock\.com\s+BLOCKED since .* Web Page Blocked/', $report);
        $this->assertMatchesRegularExpression('/sharesansar\.com\s+ok/', $report);
        $this->assertMatchesRegularExpression('/merolagani\.com\s+ok/', $report);
    }

    // ----------------------------------------------------------- jobs

    public function test_a_job_that_needs_only_the_blocked_website_is_skipped_not_failed(): void
    {
        $this->block('nepalstock.com');
        Event::fake([TaskFailed::class]);
        $this->fakeOk();

        $this->get('/cron/check/nepse-token?key=k')
            ->assertOk()
            ->assertSeeText('Skipped:')
            ->assertSeeText('nepalstock.com is blocking us')
            ->assertSeeText('[skipped]');

        Event::assertNotDispatched(TaskFailed::class); // nobody is alerted again for a block already known
        $this->assertSame(0, $this->hits);
    }

    public function test_the_price_job_skips_a_blocked_nepalstock_and_uses_the_next_website_quietly(): void
    {
        $this->block('nepalstock.com');
        Event::fake([\App\Events\StockPricesUpdated::class]);
        Http::fake(['www.sharesansar.com/live-trading' => function () {
            $this->hits++;

            return Http::response($this->liveTrading());
        }]);

        $result = app(\App\Services\DataSources\DailyPriceSyncService::class)->sync();

        $this->assertSame('sharesansar.com/live-trading', $result['source']);
        $this->assertSame(0, ScrapeLog::where('status', 'failed')->count()); // the skip is not logged as a new failure
        $this->assertSame(1, $this->hits); // only sharesansar.com was asked; nothing was sent to nepalstock.com
    }

    public function test_when_every_website_is_blocked_the_job_is_skipped_not_failed(): void
    {
        foreach (['nepalstock.com', 'sharesansar.com', 'merolagani.com'] as $website) {
            $this->block($website);
        }
        Event::fake([TaskFailed::class]);
        $this->fakeOk();

        $this->get('/cron/fetch/prices?key=k')->assertOk()->assertSeeText('[skipped]');

        Event::assertNotDispatched(TaskFailed::class);
        $this->assertSame(0, $this->hits);
    }

    public function test_a_real_failure_is_still_a_failure_and_is_alerted(): void
    {
        Event::fake([TaskFailed::class]);
        Http::fake(['*' => Http::response('Server error', 500)]);

        $this->get('/cron/check/nepse-token?key=k')->assertSeeText('[failed]');

        Event::assertDispatched(TaskFailed::class);
    }

    public function test_the_failover_helper_treats_only_all_blocked_as_a_skip(): void
    {
        $blocked = fn () => throw new ConnectionException('x', 0, new SourceBlockedException('a.example', 'a.example is blocking us', new Request('GET', 'https://a.example')));

        try {
            app(SourceFailover::class)->run('demo', ['a.example' => $blocked, 'b.example' => $blocked]);
            $this->fail('Expected an exception.');
        } catch (RuntimeException $e) {
            $this->assertNotNull(SourceBlockedException::in($e)); // all blocked: a skip
        }

        try {
            app(SourceFailover::class)->run('demo', ['a.example' => $blocked, 'b.example' => fn () => throw new RuntimeException('real error')]);
            $this->fail('Expected an exception.');
        } catch (RuntimeException $e) {
            $this->assertNull(SourceBlockedException::in($e)); // one really failed: that is a failure
        }
    }

    private function skipped(string $url): ?SourceBlockedException
    {
        try {
            Http::get($url);
        } catch (ConnectionException $e) {
            return SourceBlockedException::in($e);
        }

        return null;
    }

    private function liveTrading(): string
    {
        return '<h5>As of : <span id="dDate">2026-10-07 14:18:00</span></h5><button>Market Open</button>'
            .'<table><thead><tr><th>Symbol</th><th>LTP</th><th>Open</th><th>High</th><th>Low</th><th>Volume</th></tr></thead>'
            .'<tbody><tr><td>NABIL</td><td>567</td><td>569</td><td>570</td><td>565</td><td>100</td></tr></tbody></table>';
    }
}
