<?php

namespace Tests\Feature\Cron;

use App\Models\Stock;
use App\Models\StockFundamental;
use App\Services\DataSources\MeroLagani\MeroLaganiFundamentalsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class SyncFundamentalsTaskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.cron.secret' => 'test-secret']);
        Cache::flush();

        // merolagani.com stand-in: BBB's page fails, everyone else has figures.
        $this->app->instance(MeroLaganiFundamentalsService::class, new class extends MeroLaganiFundamentalsService
        {
            public function syncOne(Stock $stock): StockFundamental
            {
                if ($stock->symbol === 'BBB') {
                    throw new RuntimeException('No merolagani.com page found for symbol [BBB].');
                }

                return StockFundamental::updateOrCreate(['stock_id' => $stock->id], ['eps' => 10, 'pe_ratio' => 20, 'book_value' => 100, 'fetched_at' => now()]);
            }
        });

        foreach (['AAA', 'BBB', 'CCC'] as $symbol) {
            Stock::create(['symbol' => $symbol, 'company_name' => $symbol, 'is_active' => true]);
        }
    }

    private function ping(): TestResponse
    {
        return $this->get('/cron/fetch/fundamentals?key=test-secret')->assertOk();
    }

    public function test_it_fetches_one_stock_per_run_and_says_how_many_are_due(): void
    {
        $this->ping()
            ->assertSeeText('AAA: EPS=10.0000')
            ->assertDontSeeText('BBB')
            ->assertSeeText('Done — 1 stock(s) processed, 0 failed.')
            ->assertSeeText('2 stock(s) still pending — run it again for the next.');

        $this->assertNull(Stock::firstWhere('symbol', 'CCC')->fundamental); // not touched yet
    }

    public function test_a_failing_stock_is_held_back_so_it_cannot_block_the_rest(): void
    {
        $this->ping(); // AAA

        $this->ping() // BBB fails...
            ->assertSeeText('BBB: failed — No merolagani.com page found')
            ->assertSeeText('1 stock(s) still pending');

        // ...so the next run moves on to CCC rather than picking BBB again.
        $this->ping()->assertSeeText('CCC: EPS=10.0000')->assertDontSeeText('BBB');

        // Only BBB is left, but it is on hold for a few hours.
        $this->ping()->assertSeeText('No stocks are due for a fundamentals refresh.');

        // After the hold-off it is tried again.
        $this->travel(7)->hours();
        $this->ping()->assertSeeText('BBB: failed');
    }

    public function test_debentures_preference_shares_and_mutual_funds_are_skipped(): void
    {
        foreach (['DEB' => 'Non-Convertible Debentures', 'PREF' => 'Preference Shares', 'FUND' => 'Mutual Funds', 'EQ' => 'Equity'] as $symbol => $type) {
            Stock::create(['symbol' => $symbol, 'company_name' => $symbol, 'is_active' => true, 'instrument_type' => $type]);
        }
        // AAA / BBB / CCC from setUp have no instrument type yet, so they still count (untyped stocks are fetched).
        $fetched = [];
        foreach (range(1, 8) as $i) {
            $text = $this->ping()->getContent();
            if (preg_match('/^(\w+): (EPS|failed)/m', $text, $m)) {
                $fetched[] = $m[1];
            }
        }

        $this->assertEqualsCanonicalizing(['AAA', 'BBB', 'CCC', 'EQ'], array_unique($fetched));
        $this->assertNull(Stock::firstWhere('symbol', 'DEB')->fundamental);
        $this->assertNull(Stock::firstWhere('symbol', 'PREF')->fundamental);
        $this->assertNull(Stock::firstWhere('symbol', 'FUND')->fundamental);
        $this->assertNotNull(Stock::firstWhere('symbol', 'EQ')->fundamental);
    }

    public function test_a_skipped_kind_can_still_be_fetched_on_request_by_symbol(): void
    {
        Stock::create(['symbol' => 'FUND', 'company_name' => 'A fund', 'is_active' => true, 'instrument_type' => 'Mutual Funds']);

        $this->get('/cron/fetch/fundamentals/FUND?key=test-secret')->assertSeeText('FUND: EPS=10.0000');
    }

    public function test_the_stalest_stock_goes_first_and_fresh_ones_are_left_alone(): void
    {
        $now = now();
        StockFundamental::create(['stock_id' => Stock::firstWhere('symbol', 'AAA')->id, 'eps' => 1, 'fetched_at' => $now->copy()->subDays(30)]); // very stale
        StockFundamental::create(['stock_id' => Stock::firstWhere('symbol', 'CCC')->id, 'eps' => 1, 'fetched_at' => $now->copy()->subDays(2)]);  // fresh: skipped

        // BBB has never been fetched and so is first in line, ahead of the stale AAA; it fails and is held back.
        $this->ping()->assertSeeText('BBB: failed');
        $this->ping()->assertSeeText('AAA: EPS=10.0000')->assertDontSeeText('CCC');
        $this->ping()->assertSeeText('No stocks are due');
    }
}
