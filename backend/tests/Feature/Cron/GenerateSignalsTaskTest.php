<?php

namespace Tests\Feature\Cron;

use App\Models\DailyPrice;
use App\Models\Signal;
use App\Models\Stock;
use App\Models\TechnicalIndicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** /cron/generate/signals: signals from the stored indicators, separate from generating the indicators. */
class GenerateSignalsTaskTest extends TestCase
{
    use RefreshDatabase;

    private Stock $withIndicators;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.cron.secret' => 'test-secret']);

        // AAA: three days of indicators (SMA 20 present, so the signal rules can run).
        $this->withIndicators = Stock::create(['symbol' => 'AAA', 'company_name' => 'A', 'is_active' => true]);
        foreach (['2026-10-01', '2026-10-02', '2026-10-03'] as $date) {
            DailyPrice::create(['stock_id' => $this->withIndicators->id, 'trade_date' => $date, 'open_price' => 100, 'high_price' => 100, 'low_price' => 100, 'close_price' => 100, 'volume' => 10]);
            TechnicalIndicator::create(['stock_id' => $this->withIndicators->id, 'trade_date' => $date, 'sma_20' => 100, 'sma_50' => 90, 'rsi_14' => 25, 'bb_lower' => 60, 'bb_upper' => 140]);
        }

        // BBB: a brand-new listing — indicators exist but no SMA 20 yet, so no signal can be made.
        $new = Stock::create(['symbol' => 'BBB', 'company_name' => 'B', 'is_active' => true]);
        TechnicalIndicator::create(['stock_id' => $new->id, 'trade_date' => '2026-10-03']);

        // CCC: no indicators at all.
        Stock::create(['symbol' => 'CCC', 'company_name' => 'C', 'is_active' => true]);
    }

    private function ping(string $query = ''): TestResponse
    {
        return $this->get('/cron/generate/signals?key=test-secret'.$query)->assertOk();
    }

    public function test_it_generates_signals_from_the_stored_indicators(): void
    {
        $this->ping()
            ->assertSeeText('$ generate-signals')
            ->assertSeeText('Generated signals for 1 stock(s) (3 daily signal rows written).')
            ->assertSeeText('[ok]');

        $this->assertSame(3, Signal::where('stock_id', $this->withIndicators->id)->count());
        $this->assertSame(1, Signal::distinct()->count('stock_id')); // BBB (too new) and CCC (no indicators) get none
    }

    public function test_a_second_run_has_nothing_to_do_until_the_indicators_change(): void
    {
        $this->ping();

        $this->ping()->assertSeeText('Signals are up to date — nothing to generate.');

        // The indicators are recalculated (their rows are rewritten): the signals are stale again.
        TechnicalIndicator::where('stock_id', $this->withIndicators->id)->update(['updated_at' => now()->addMinute()]);

        $this->ping()->assertSeeText('Generated signals for 1 stock(s)');
    }

    public function test_all_regenerates_every_stock_that_has_indicators(): void
    {
        $this->ping();

        $this->ping('&all=1')
            ->assertSeeText('$ generate-signals (all stocks)')
            ->assertSeeText('Generated signals for 1 stock(s)');
    }

    public function test_it_says_so_when_there_are_no_indicators_to_work_from(): void
    {
        TechnicalIndicator::query()->delete();

        $this->ping('&all=1')->assertSeeText('No indicators yet — run generate/indicators first.');
        $this->ping()->assertSeeText('Signals are up to date — nothing to generate.');
    }

    public function test_the_indicators_cron_no_longer_creates_signals(): void
    {
        $stock = Stock::create(['symbol' => 'DDD', 'company_name' => 'D', 'is_active' => true]);
        DailyPrice::create(['stock_id' => $stock->id, 'trade_date' => '2026-10-03', 'open_price' => 100, 'high_price' => 100, 'low_price' => 100, 'close_price' => 100, 'volume' => 10]);

        $this->get('/cron/generate/indicators?key=test-secret')->assertOk()->assertSeeText('Recalculated indicators for');

        $this->assertGreaterThan(0, TechnicalIndicator::where('stock_id', $stock->id)->count());
        $this->assertSame(0, Signal::where('stock_id', $stock->id)->count());
    }

    public function test_the_route_needs_the_cron_key(): void
    {
        $this->get('/cron/generate/signals')->assertForbidden();
    }
}
