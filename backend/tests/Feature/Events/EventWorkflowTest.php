<?php

namespace Tests\Feature\Events;

use App\Events\ScrapeFinished;
use App\Events\StockPricesUpdated;
use App\Events\TaskFailed;
use App\Events\UserLoggedIn;
use App\Listeners\AlertTaskFailure;
use App\Listeners\RecalculateUpdatedStocks;
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

        Event::assertListening(StockPricesUpdated::class, RecalculateUpdatedStocks::class);
        Event::assertListening(ScrapeFinished::class, RecordScrapeLog::class);
        Event::assertListening(TaskFailed::class, AlertTaskFailure::class);
        Event::assertListening(UserLoggedIn::class, RecordLoginHistory::class);
    }

    public function test_a_price_update_recalculates_exactly_those_stocks(): void
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

        $this->assertSame(1, $updated->technicalIndicators()->count());
        $this->assertSame(0, $untouched->technicalIndicators()->count());
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
