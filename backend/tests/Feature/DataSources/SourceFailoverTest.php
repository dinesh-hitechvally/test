<?php

namespace Tests\Feature\DataSources;

use App\Models\DailyPrice;
use App\Models\IndexSnapshot;
use App\Models\ScrapeLog;
use App\Models\Sector;
use App\Models\Stock;
use App\Services\DataSources\DailyPriceSyncService;
use App\Services\DataSources\MeroLagani\MeroLaganiCompanyListService;
use App\Services\DataSources\MeroLagani\MeroLaganiLiveMarketService;
use App\Services\DataSources\NepalStock\NepalStockIndexService;
use App\Services\DataSources\NepalStock\NepalStockTokenService;
use App\Services\DataSources\SourceFailover;
use App\Services\DataSources\ShareSansar\SharesansarLiveMarketService;
use App\Tasks\MarketData\SyncStockListTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * When nepalstock.com is blocked or down, the jobs that depend on it log the failure and switch to ShareSansar,
 * then MeroLagani — and only fail when every source has.
 */
class SourceFailoverTest extends TestCase
{
    use RefreshDatabase;

    /** What a network web filter answers instead of the real site. */
    private const BLOCKED = '<html><head><title>Web Page Blocked</title></head><body><h1>Web Page Blocked</h1><p>Web Filter Service Error: invalid license</p></body></html>';

    protected function setUp(): void
    {
        parent::setUp();

        $tokens = Mockery::mock(NepalStockTokenService::class);
        $tokens->shouldReceive('getAccessToken')->andReturn('tok');
        $this->app->instance(NepalStockTokenService::class, $tokens);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ------------------------------------------------------------ the helper

    public function test_the_first_source_that_works_is_used_and_earlier_failures_are_logged(): void
    {
        $result = app(SourceFailover::class)->run('demo', [
            'one.example' => fn () => throw new RuntimeException(self::BLOCKED),
            'two.example' => fn () => 'from two',
            'three.example' => fn () => 'from three',
        ]);

        [$value, $source, $failed] = $result;

        $this->assertSame('from two', $value);
        $this->assertSame('two.example', $source);
        $this->assertSame(['one.example'], array_keys($failed));
        $this->assertStringContainsString('Web Page Blocked', $failed['one.example']);
        $this->assertStringNotContainsString('<h1>', $failed['one.example']); // the HTML is stripped

        $log = ScrapeLog::sole();
        $this->assertSame('one.example', $log->source);
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('switching to two.example', $log->message);
    }

    public function test_nothing_is_logged_when_the_first_source_works(): void
    {
        [, $source, $failed] = app(SourceFailover::class)->run('demo', ['one.example' => fn () => 1, 'two.example' => fn () => 2]);

        $this->assertSame('one.example', $source);
        $this->assertSame([], $failed);
        $this->assertSame(0, ScrapeLog::count());
    }

    public function test_it_fails_naming_every_source_when_all_of_them_fail(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('one.example: first reason; two.example: second reason');

        app(SourceFailover::class)->run('demo', [
            'one.example' => fn () => throw new RuntimeException('first reason'),
            'two.example' => fn () => throw new RuntimeException('second reason'),
        ]);
    }

    // ------------------------------------------------------------ prices

    public function test_prices_switch_to_sharesansar_when_nepalstock_is_blocked(): void
    {
        Event::fake([\App\Events\StockPricesUpdated::class]);
        Http::fake([
            '*/api/nots/nepse-data/market-open' => Http::response(self::BLOCKED, 403),
            'www.sharesansar.com/live-trading' => Http::response($this->sharesansarLive(open: true)),
        ]);
        Stock::create(['symbol' => 'NABIL', 'company_name' => 'Nabil Bank', 'is_active' => true]);

        $result = app(DailyPriceSyncService::class)->sync();

        $this->assertSame('sharesansar.com/live-trading', $result['source']);
        $this->assertTrue($result['market_open']);
        $this->assertSame('2026-10-07', $result['trade_date']);
        $this->assertSame(['nepalstock.com'], array_keys($result['failed_sources']));

        $row = DailyPrice::sole();
        $this->assertEquals(567, $row->close_price);
        $this->assertEquals(570, $row->high_price);
        $this->assertEquals(567 * 25356, $row->turnover); // estimated, ShareSansar's live table has none

        $nepse = ScrapeLog::where('source', 'nepalstock.com')->get();
        $this->assertSame(['failed', 'failed'], $nepse->pluck('status')->all());
        $this->assertTrue($nepse->contains(fn ($l) => str_contains($l->message, 'switching to sharesansar.com/live-trading')));
        $this->assertTrue($nepse->contains(fn ($l) => str_contains($l->message, 'is blocking us'))); // the block itself, logged once
        $this->assertStringContainsString('nepalstock.com failed', ScrapeLog::where('source', 'sharesansar.com/live-trading')->sole()->message);
    }

    public function test_a_symbol_the_app_does_not_know_is_not_created_from_a_fallback_source(): void
    {
        Event::fake([\App\Events\StockPricesUpdated::class]);
        Http::fake([
            '*/api/nots/nepse-data/market-open' => Http::response(self::BLOCKED, 403),
            'www.sharesansar.com/live-trading' => Http::response($this->sharesansarLive(open: true)),
        ]);

        $result = app(DailyPriceSyncService::class)->sync();

        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, Stock::count()); // only NEPSE is the listing authority
    }

    public function test_prices_switch_to_merolagani_when_nepalstock_and_sharesansar_both_fail(): void
    {
        Event::fake([\App\Events\StockPricesUpdated::class]);
        $this->travelTo(Carbon::parse('2026-10-07 14:20:00', 'Asia/Kathmandu')); // a Wednesday, market hours
        Http::fake([
            '*/api/nots/nepse-data/market-open' => Http::response(self::BLOCKED, 403),
            'www.sharesansar.com/live-trading' => Http::response('Service Unavailable', 503),
            'merolagani.com/LatestMarket.aspx' => Http::response($this->merolaganiLive()),
        ]);
        Stock::create(['symbol' => 'NABIL', 'company_name' => 'Nabil Bank', 'is_active' => true]);

        $result = app(DailyPriceSyncService::class)->sync();

        $this->assertSame('merolagani.com', $result['source']);
        $this->assertTrue($result['market_open']);
        $this->assertSame(['nepalstock.com', 'sharesansar.com/live-trading'], array_keys($result['failed_sources']));
        $this->assertEquals(567, DailyPrice::sole()->close_price);
    }

    public function test_prices_fail_with_every_reason_when_all_three_sources_fail(): void
    {
        Http::fake([
            '*/api/nots/nepse-data/market-open' => Http::response(self::BLOCKED, 403),
            'www.sharesansar.com/live-trading' => Http::response('Service Unavailable', 503),
            'merolagani.com/LatestMarket.aspx' => Http::response('Gateway Timeout', 504),
        ]);

        try {
            app(DailyPriceSyncService::class)->sync();
            $this->fail('Expected the sync to fail.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('nepalstock.com', $e->getMessage());
            $this->assertStringContainsString('Web Page Blocked', $e->getMessage());
            $this->assertStringContainsString('sharesansar.com/live-trading', $e->getMessage());
            $this->assertStringContainsString('merolagani.com', $e->getMessage());
        }

        // One row per failed source, plus the one-off "nepalstock.com is blocking us" row.
        $this->assertSame(4, ScrapeLog::where('status', 'failed')->count());
    }

    // ------------------------------------------------------------ stock list

    public function test_the_stock_list_switches_to_merolagani_with_sectors_and_types(): void
    {
        Http::fake([
            '*/api/nots/security*' => Http::response(self::BLOCKED, 403),
            'merolagani.com/CompanyList.aspx' => Http::response($this->merolaganiCompanies()),
        ]);

        $message = app(SyncStockListTask::class)->handle();

        $this->assertStringContainsString('from merolagani.com/company-list', $message);
        $this->assertStringContainsString('nepalstock.com/security-list failed', $message);

        $nabil = Stock::firstWhere('symbol', 'NABIL');
        $this->assertSame('Nabil Bank Limited', $nabil->company_name);
        $this->assertSame('Commercial Banks', $nabil->sector->name);
        $this->assertSame('Equity', $nabil->instrument_type);
        $this->assertNull($nabil->nepse_security_id); // only NEPSE knows its ids; they fill in when it is reachable

        $this->assertSame('Mutual Fund', Stock::firstWhere('symbol', 'SEF')->sector->name);
        $this->assertSame('Mutual Funds', Stock::firstWhere('symbol', 'SEF')->instrument_type);
        $this->assertSame('Non-Convertible Debentures', Stock::firstWhere('symbol', 'EBLB2079')->instrument_type);
        $this->assertNull(Stock::firstWhere('symbol', 'EBLB2079')->sector_id);

        // A promoter share is listed under its own heading: its sector comes from the parent company.
        $promoter = Stock::firstWhere('symbol', 'NABILP');
        $this->assertSame('Commercial Banks', $promoter->sector->name);
        $this->assertNull($promoter->instrument_type);
    }

    public function test_a_fallback_sector_name_matches_an_existing_one_instead_of_duplicating_it(): void
    {
        Sector::create(['name' => 'Hydropower']);
        Sector::create(['name' => 'Development Banks']);
        Http::fake([
            '*/api/nots/security*' => Http::response(self::BLOCKED, 403),
            'merolagani.com/CompanyList.aspx' => Http::response($this->merolaganiCompanies()),
        ]);

        app(SyncStockListTask::class)->handle();

        $this->assertSame('Hydropower', Stock::firstWhere('symbol', 'AHPC')->sector->name);        // "Hydro Power" on MeroLagani
        $this->assertSame('Development Banks', Stock::firstWhere('symbol', 'CORBL')->sector->name); // "Development Bank Limited"
        $this->assertSame(1, Sector::where('name', 'like', 'Hydro%')->count());
    }

    // ------------------------------------------------------------ index

    public function test_the_index_switches_to_sharesansar_when_nepalstock_is_blocked(): void
    {
        Http::fake([
            '*/api/nots/nepse-data/market-open' => Http::response(self::BLOCKED, 403),
            'www.sharesansar.com/live-trading' => Http::response($this->sharesansarLive(open: true)),
            'www.sharesansar.com/market' => Http::response($this->sharesansarIndices()),
        ]);

        $result = app(NepalStockIndexService::class)->sync();

        $this->assertSame('sharesansar.com/market', $result['source']);
        $this->assertSame(2, $result['indices_updated']);

        $nepse = IndexSnapshot::where('index_name', 'NEPSE Index')->sole();
        $this->assertSame('2026-10-07', $nepse->trade_date->toDateString());
        $this->assertEquals(2578.73, $nepse->close);
        $this->assertEquals(2566.77, $nepse->previous_close); // close less the point change
    }

    public function test_the_index_is_not_stored_when_the_market_is_closed_whichever_source_answers(): void
    {
        Http::fake([
            '*/api/nots/nepse-data/market-open' => Http::response(self::BLOCKED, 403),
            'www.sharesansar.com/live-trading' => Http::response($this->sharesansarLive(open: false)),
        ]);

        $result = app(NepalStockIndexService::class)->sync();

        $this->assertFalse($result['market_open']);
        $this->assertSame(0, IndexSnapshot::count());
    }

    // ------------------------------------------------------------ the page readers

    public function test_sharesansar_live_page_gives_status_date_and_prices(): void
    {
        $open = true;
        Http::fake(['www.sharesansar.com/live-trading' => function () use (&$open) {
            return Http::response($this->sharesansarLive($open));
        }]);

        $snapshot = app(SharesansarLiveMarketService::class)->snapshot();

        $this->assertTrue($snapshot['open']);
        $this->assertSame('2026-10-07', $snapshot['date']);
        $this->assertSame('NABIL', $snapshot['rows'][0]['symbol']);
        $this->assertSame(25356, $snapshot['rows'][0]['volume']);

        $open = false;
        $this->assertFalse(app(SharesansarLiveMarketService::class)->snapshot()['open']);
    }

    public function test_merolagani_live_prices_are_made_consistent(): void
    {
        $this->travelTo(Carbon::parse('2026-10-07 14:20:00', 'Asia/Kathmandu'));
        // Cells: LTP, % change, high, low, open — here the "open" (a previous close) is above the high and the last price below the low.
        Http::fake(['merolagani.com/LatestMarket.aspx' => Http::response($this->merolaganiLive(cells: ['NABIL', '560.00', '-1.0', '570.00', '565.00', '575.00', '100']))]);

        $row = app(MeroLaganiLiveMarketService::class)->snapshot()['rows'][0];

        $this->assertEquals(575, $row['high']);   // widened to include the open and the last price
        $this->assertEquals(560, $row['low']);
        $this->assertEquals(575, $row['open']);
        $this->assertLessThanOrEqual($row['high'], $row['close']);
        $this->assertGreaterThanOrEqual($row['low'], $row['close']);
    }

    public function test_merolagani_market_is_open_only_in_trading_hours_on_a_trading_day_with_a_fresh_page(): void
    {
        $page = app(MeroLaganiLiveMarketService::class);
        $asOf = Carbon::parse('2026-10-07 14:16:00', 'Asia/Kathmandu');

        $this->assertTrue($page->isOpen($asOf, Carbon::parse('2026-10-07 14:20:00', 'Asia/Kathmandu')));   // Wednesday afternoon
        $this->assertFalse($page->isOpen($asOf, Carbon::parse('2026-10-07 16:30:00', 'Asia/Kathmandu')));  // after the close
        $this->assertFalse($page->isOpen($asOf, Carbon::parse('2026-10-07 09:00:00', 'Asia/Kathmandu')));  // before the open
        $this->assertFalse($page->isOpen(Carbon::parse('2026-10-09 14:16:00', 'Asia/Kathmandu'), Carbon::parse('2026-10-09 14:20:00', 'Asia/Kathmandu'))); // Friday: no trading
        $this->assertFalse($page->isOpen(Carbon::parse('2026-10-07 13:00:00', 'Asia/Kathmandu'), Carbon::parse('2026-10-07 14:20:00', 'Asia/Kathmandu'))); // stale page
    }

    public function test_the_merolagani_company_list_is_read_by_sector_heading(): void
    {
        $list = app(MeroLaganiCompanyListService::class)->parse($this->merolaganiCompanies());

        $this->assertCount(6, $list['securities']);
        $this->assertSame(['sector' => 'Commercial Banks', 'instrument_type' => 'Equity'], $list['companies']['NABIL']);
        $this->assertSame(['sector' => null, 'instrument_type' => 'Non-Convertible Debentures'], $list['companies']['EBLB2079']);
        $this->assertArrayNotHasKey('NABILP', $list['companies']); // promoter shares have no sector of their own
    }

    // ------------------------------------------------------------ page fixtures

    private function sharesansarLive(bool $open): string
    {
        $status = $open ? 'Market Open' : 'Market Close';

        return '<h5>As of : <span id="dDate" class="text-org">2026-10-07 14:18:00</span></h5>'
            ."<ul><li><button class=\"btn btn-success\">{$status}</button></li></ul>"
            .'<table><thead><tr><th>S.No</th><th>Symbol</th><th>LTP</th><th>Point Change</th><th>% Change</th><th>Open</th><th>High</th><th>Low</th><th>Volume</th><th>Prev. Close</th></tr></thead>'
            .'<tbody><tr><td>1</td><td><a>NABIL</a></td><td>567.00</td><td>-3.00</td><td>-0.5</td><td>569.00</td><td>570.00</td><td>565.00</td><td>25,356.00</td><td>570.00</td></tr></tbody></table>';
    }

    private function sharesansarIndices(): string
    {
        return '<table><thead><tr><th>Index</th><th>Open</th><th>High</th><th>Low</th><th>Close</th><th>Point Change</th><th>% Change</th><th>Turnover</th></tr></thead><tbody>'
            .'<tr><td>NEPSE Index</td><td>2,557.77</td><td>2,581.58</td><td>2,557.30</td><td>2,578.73</td><td>11.96</td><td>0.46</td><td>3,138,119,528.45</td></tr>'
            .'<tr><td>Sensitive Index</td><td>458.05</td><td>462.37</td><td>458.05</td><td>461.86</td><td>2.00</td><td>0.43</td><td>1,010,684,122.10</td></tr>'
            .'</tbody></table>';
    }

    private function merolaganiLive(array $cells = ['NABIL', '567.00', '-0.5', '570.00', '565.00', '569.00', '25,356']): string
    {
        $tds = implode('', array_map(fn ($c, $i) => $i === 0 ? "<td><a href='/CompanyDetail.aspx?symbol={$c}'>{$c}</a></td>" : "<td class='text-right'>{$c}</td>", $cells, array_keys($cells)));

        return '<span class="label label-default">As of 2026/10/07 14:16:00</span>'
            .'<div id="ctl00_ContentPlaceHolder1_LiveTrading"><table><thead><tr><th>Symbol</th></tr></thead><tbody>'
            ."<tr>{$tds}<td><a href='#'></a></td></tr></tbody></table></div>";
    }

    private function merolaganiCompanies(): string
    {
        $panel = fn (string $title, array $companies) => '<div class="panel panel-default"><div class="panel-heading"><h3 class="panel-title"><a data-toggle="collapse" href="#c">'.$title.'</a></h3></div>'
            .'<div><table><tr><th>Symbol</th><th>Company Name</th></tr>'
            .implode('', array_map(fn ($c) => "<tr><td class=\"text-left\"><a onclick=\"x()\" href='/CompanyDetail.aspx?symbol={$c[0]}'>{$c[0]}</a></td><td class=\"text-left\">{$c[1]}</td><td>1</td></tr>", $companies))
            .'</table></div></div>';

        return '<div id="accordion">'
            .$panel('Commercial Banks', [['NABIL', 'Nabil Bank Limited']])
            .$panel('Corporate Debenture', [['EBLB2079', 'Everest Bank Limited--2079']])
            .$panel('Development Bank Limited', [['CORBL', 'Corporate Development Bank Limited']])
            .$panel('Hydro Power', [['AHPC', 'Arun Valley Hydropower']])
            .$panel('Mutual Fund', [['SEF', 'Siddhartha Equity Fund']])
            .$panel('Promotor Share', [['NABILP', 'Nabil Bank Promoter Share']])
            .'</div>';
    }
}
