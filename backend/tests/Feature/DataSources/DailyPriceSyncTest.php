<?php

namespace Tests\Feature\DataSources;

use App\Events\StockPricesUpdated;
use App\Models\DailyPrice;
use App\Models\ScrapeLog;
use App\Models\Stock;
use App\Services\DataSources\DailyPriceSyncService;
use App\Services\DataSources\NepalStock\NepalStockTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DailyPriceSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $tokens = Mockery::mock(NepalStockTokenService::class);
        $tokens->shouldReceive('getAccessToken')->andReturn('tok');
        $this->app->instance(NepalStockTokenService::class, $tokens);
        Event::fake([StockPricesUpdated::class]);
    }

    public function test_open_market_saves_live_prices_under_nepses_date_not_the_servers(): void
    {
        $this->travelTo('2026-09-29 00:30:00'); // server clock already past midnight
        $this->fakeSources(open: true, asOf: '2026-09-28T14:55:00', live: [
            ['symbol' => 'NABIL', 'securityName' => 'Nabil Bank', 'securityId' => 131, 'openPrice' => 570, 'highPrice' => 570,
                'lowPrice' => 565, 'lastTradedPrice' => 567, 'totalTradeQuantity' => 25356, 'totalTradeValue' => 14400000],
        ]);

        $result = $this->sync();

        $this->assertTrue($result['market_open']);
        $this->assertSame('nepalstock.com', $result['source']);
        $this->assertSame(1, $result['created_stocks']); // NEPSE is the listing authority
        $row = DailyPrice::sole();
        $this->assertSame('2026-09-28', $row->trade_date->toDateString());
        $this->assertEquals(567, $row->close_price);
        Event::assertDispatched(StockPricesUpdated::class);
    }

    public function test_closed_market_takes_final_prices_and_updates_only_what_needs_it(): void
    {
        $nabil = Stock::create(['symbol' => 'NABIL', 'company_name' => 'Nabil Bank', 'is_active' => true]);
        $nica = Stock::create(['symbol' => 'NICA', 'company_name' => 'NIC Asia', 'is_active' => true]);
        $hbl = Stock::create(['symbol' => 'HBL', 'company_name' => 'Himalayan Bank', 'is_active' => true]);
        // NABIL was captured mid-session; HBL is already final; NICA was missed.
        $this->price($nabil, close: 567, volume: 25356);
        $this->price($hbl, close: 200, volume: 5000, open: 198, high: 201, low: 197, turnover: 1000000);

        $this->fakeSources(open: false, asOf: '2026-09-28T15:00:00', final: [
            ['NABIL', 570, 570, 565, 565, 88903, 50000000],
            ['NICA', 400, 405, 398, 402, 12000, 4800000],
            ['HBL', 198, 201, 197, 200, 5000, 1000000],
            ['NEWCO', 100, 100, 100, 100, 10, 1000], // not a stock the app knows
        ]);

        $result = $this->sync();

        $this->assertFalse($result['market_open']);
        $this->assertSame('sharesansar.com/today-share-price', $result['source']);
        $this->assertSame([1, 1, 1, 1, 0], [$result['inserted'], $result['updated'], $result['unchanged'], $result['skipped'], $result['created_stocks']]);

        $this->assertEquals(565, $this->row($nabil)->close_price);   // corrected to final
        $this->assertSame(88903, $this->row($nabil)->volume);
        $this->assertEquals(402, $this->row($nica)->close_price);    // filled in
        $this->assertNull(Stock::where('symbol', 'NEWCO')->first()); // not created from ShareSansar

        Event::assertDispatched(StockPricesUpdated::class, fn ($e) => collect($e->stockIds)->sort()->values()->all() === collect([$nabil->id, $nica->id])->sort()->values()->all());
    }

    public function test_running_again_changes_nothing_and_recalculates_nothing(): void
    {
        $nabil = Stock::create(['symbol' => 'NABIL', 'company_name' => 'Nabil Bank', 'is_active' => true]);
        $this->price($nabil, close: 565, volume: 88903, open: 570, high: 570, low: 565, turnover: 50000000);
        $this->fakeSources(open: false, asOf: '2026-09-28T15:00:00', final: [['NABIL', 570, 570, 565, 565, 88903, 50000000]]);

        $result = $this->sync();

        $this->assertSame(1, $result['unchanged']);
        $this->assertSame(0, $result['inserted'] + $result['updated']);
        Event::assertNotDispatched(StockPricesUpdated::class);
    }

    public function test_a_date_with_no_prices_is_a_quiet_no_op(): void
    {
        $this->fakeSources(open: false, asOf: '2026-09-28T15:00:00', final: []);

        $result = $this->sync();

        $this->assertSame(0, $result['fetched']);
        $this->assertSame(0, DailyPrice::count());
        Event::assertNotDispatched(StockPricesUpdated::class);
        $this->assertStringContainsString('nothing to update', ScrapeLog::sole()->message);
    }

    public function test_sharesansar_answering_for_a_different_date_is_refused(): void
    {
        $this->fakeSources(open: false, asOf: '2026-09-28T15:00:00', final: [['NABIL', 1, 1, 1, 1, 1, 1]], sharesansarAsOf: '2026-09-24');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('as of 2026-09-24, not 2026-09-28');

        $this->sync();
    }

    public function test_an_empty_live_feed_while_open_is_an_error(): void
    {
        $this->fakeSources(open: true, asOf: '2026-09-29T12:00:00', live: []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('empty while the market is open');

        $this->sync();
    }

    private function sync(): array
    {
        return app(DailyPriceSyncService::class)->sync();
    }

    /** @param  list<array{0: string, 1: float, 2: float, 3: float, 4: float, 5: int, 6: float}>  $final  [symbol, open, high, low, close, volume, turnover] */
    private function fakeSources(bool $open, string $asOf, array $live = [], array $final = [], ?string $sharesansarAsOf = null): void
    {
        $asOfDate = $sharesansarAsOf ?? substr($asOf, 0, 10);
        $cols = ['S.No', 'Symbol', 'Conf.', 'Open', 'High', 'Low', 'Close', 'LTP', 'VWAP', 'Vol', 'Prev. Close', 'Turnover'];
        $body = $final === []
            ? '<tr><td colspan="12">No Record Found.</td></tr>'
            : collect($final)->map(fn ($r, $i) => '<tr><td>'.($i + 1).'</td><td><a>'.$r[0].'</a></td><td>50</td><td>'.number_format($r[1], 2).'</td><td>'.number_format($r[2], 2).'</td><td>'.number_format($r[3], 2).'</td><td>'.number_format($r[4], 2).'</td><td>'.number_format($r[4], 2).'</td><td>0</td><td>'.number_format($r[5], 2).'</td><td>0</td><td>'.number_format($r[6], 2).'</td></tr>')->implode('');

        Http::fake([
            '*/api/nots/nepse-data/market-open' => Http::response(['isOpen' => $open ? 'OPEN' : 'CLOSE', 'asOf' => $asOf, 'id' => 80]),
            '*/api/nots/lives-market' => Http::response($live),
            'www.sharesansar.com/today-share-price' => Http::response('<meta name="_token" content="csrf-123">'),
            'www.sharesansar.com/ajaxtodayshareprice' => Http::response(
                "<h5>As of : {$asOfDate}</h5><table><thead><tr>".collect($cols)->map(fn ($c) => "<th>{$c}</th>")->implode('')."</tr></thead><tbody>{$body}</tbody></table>"
            ),
        ]);
    }

    private function price(Stock $stock, float $close, int $volume, ?float $open = null, ?float $high = null, ?float $low = null, float $turnover = 0): void
    {
        DailyPrice::create([
            'stock_id' => $stock->id, 'trade_date' => '2026-09-28', 'open_price' => $open ?? $close, 'high_price' => $high ?? $close,
            'low_price' => $low ?? $close, 'close_price' => $close, 'volume' => $volume, 'turnover' => $turnover,
        ]);
    }

    private function row(Stock $stock): DailyPrice
    {
        return DailyPrice::where('stock_id', $stock->id)->where('trade_date', '2026-09-28')->sole();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
