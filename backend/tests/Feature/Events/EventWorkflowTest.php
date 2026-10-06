<?php

namespace Tests\Feature\Events;

use App\Events\ScrapeFinished;
use App\Events\StockPricesUpdated;
use App\Events\TaskFailed;
use App\Events\UserLoggedIn;
use App\Listeners\AlertTaskFailure;
use App\Listeners\FlagPriceQualityIssues;
use App\Listeners\RecordLoginHistory;
use App\Listeners\RecordScrapeLog;
use App\Models\DailyPrice;
use App\Models\ScrapeLog;
use App\Models\Stock;
use App\Services\DataSources\Csv\CsvPriceImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class EventWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_listeners_are_wired_to_their_events(): void
    {
        Event::fake();

        Event::assertListening(StockPricesUpdated::class, FlagPriceQualityIssues::class);
        Event::assertListening(ScrapeFinished::class, RecordScrapeLog::class);
        Event::assertListening(TaskFailed::class, AlertTaskFailure::class);
        Event::assertListening(UserLoggedIn::class, RecordLoginHistory::class);
    }

    public function test_a_price_update_alone_does_not_generate_indicators_the_cron_does(): void
    {
        $updated = Stock::create(['symbol' => 'UPD', 'company_name' => 'U', 'is_active' => true]);
        $untouched = Stock::create(['symbol' => 'OTH', 'company_name' => 'O', 'is_active' => true]);
        foreach ([$updated, $untouched] as $stock) {
            DailyPrice::create([
                'stock_id' => $stock->id, 'trade_date' => '2024-01-01',
                'open_price' => 100, 'high_price' => 100, 'low_price' => 100, 'close_price' => 100, 'volume' => 1000,
            ]);
        }

        StockPricesUpdated::dispatch([$updated->id], 'test');

        $this->assertSame(0, $updated->technicalIndicators()->count());

        config(['services.cron.secret' => 'test-secret']);
        $this->get('/cron/generate/indicators?key=test-secret')->assertOk();

        $this->assertSame(1, $updated->technicalIndicators()->count());
        $this->assertSame(1, $untouched->technicalIndicators()->count());
    }

    public function test_recalculation_also_populates_support_resistance_52_week_and_volume_ratio(): void
    {
        $stock = Stock::create(['symbol' => 'FUL', 'company_name' => 'F', 'is_active' => true]);

        foreach (range(0, 24) as $i) {
            DailyPrice::create([
                'stock_id' => $stock->id,
                'trade_date' => now()->subDays(24 - $i)->toDateString(),
                'open_price' => 100 + $i,
                'high_price' => 105 + $i,
                'low_price' => 95 + $i,
                'close_price' => 100 + $i,
                'volume' => 1000 + ($i * 10),
            ]);
        }

        config(['services.cron.secret' => 'test-secret']);
        $this->get('/cron/generate/indicators?key=test-secret')->assertOk();

        $latest = $stock->technicalIndicators()->orderByDesc('trade_date')->first();

        $this->assertNotNull($latest->high_52w);
        $this->assertNotNull($latest->low_52w);
        $this->assertEqualsWithDelta(129.0, (float) $latest->high_52w, 0.0001); // 105 + 24
        $this->assertEqualsWithDelta(95.0, (float) $latest->low_52w, 0.0001); // the first day's low
        $this->assertNotNull($latest->volume_ratio); // 25 days of rising volume — well past the 20-day warm-up
        $this->assertGreaterThan(1.0, (float) $latest->volume_ratio); // today's volume is the highest yet
    }

    public function test_scrape_finished_is_recorded_in_the_scrape_log(): void
    {
        ScrapeFinished::dispatch(source: 'nepalstock.com', succeeded: false, recordsProcessed: 0, message: 'timeout');

        $log = ScrapeLog::sole();
        $this->assertSame('nepalstock.com', $log->source);
        $this->assertSame('failed', $log->status);
        $this->assertSame('timeout', $log->message);
    }

    public function test_csv_import_announces_the_stocks_it_touched(): void
    {
        Event::fake([StockPricesUpdated::class]);

        $csv = "symbol,date,open,high,low,close,volume\nABC,2024-01-01,10,11,9,10.5,100\nABC,2024-01-02,10.5,12,10,11,200\n";
        $file = UploadedFile::fake()->createWithContent('prices.csv', $csv);

        $result = app(CsvPriceImportService::class)->import($file);

        Event::assertDispatched(StockPricesUpdated::class, fn ($e) => $e->stockIds === $result['affected_stock_ids']
            && $e->source === 'csv-import'
            && count($e->stockIds) === 1);
    }
}
