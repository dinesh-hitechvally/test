<?php

namespace Tests\Feature\Cron;

use App\Contracts\PriceHistorySource;
use App\Http\Cron\CronSchedule;
use App\Models\DailyPrice;
use App\Models\Stock;
use App\Models\User;
use App\Services\Alerts\FailureAlertService;
use App\Services\DataSources\NepalStock\NepalStockScraperService;
use App\Tasks\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CronTasksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.cron.secret' => 'test-secret']);
    }

    public function test_recalculate_only_touches_stocks_priced_today_unless_all_is_passed(): void
    {
        $today = Stock::create(['symbol' => 'TODAY', 'company_name' => 'T', 'is_active' => true]);
        $old = Stock::create(['symbol' => 'OLD', 'company_name' => 'O', 'is_active' => true]);
        $this->price($today, now()->toDateString());
        $this->price($old, '2024-01-01');

        $this->get('/cron/reports/market-recalculate?key=test-secret')
            ->assertOk()
            ->assertSeeText('$ market:recalculate')
            ->assertSeeText('Recalculated indicators/signals for 1 stock(s).')
            ->assertSeeText('[ok]');

        $this->get('/cron/reports/market-recalculate?key=test-secret&all=1')
            ->assertOk()
            ->assertSeeText('$ market:recalculate --all')
            ->assertSeeText('Recalculated indicators/signals for 2 stock(s).');

        $this->assertSame(1, $old->technicalIndicators()->count());
    }

    public function test_every_registry_entry_is_a_routed_task(): void
    {
        foreach (CronSchedule::tasks() as $path => $entry) {
            $this->assertTrue(is_subclass_of($entry['task'], Task::class), $entry['task']);
            $this->get('/cron/'.str_replace('{symbol}', 'X', $path))->assertForbidden(); // routed + gated by the key
        }
    }

    public function test_fetch_history_for_one_symbol_uses_the_route_parameter(): void
    {
        $this->app->instance(PriceHistorySource::class, new class implements PriceHistorySource
        {
            public function fetchHistory(Stock $stock): array
            {
                return ['rows_imported' => 3, 'oldest_date' => '2024-01-01', 'newest_date' => '2024-01-03'];
            }
        });
        Stock::create(['symbol' => 'NABIL', 'company_name' => 'N', 'is_active' => true]);

        $this->get('/cron/scrape/fetch-history/nabil?key=test-secret')
            ->assertOk()
            ->assertSeeText('$ fetch-history NABIL')
            ->assertSeeText('3 rows imported (2024-01-01 to 2024-01-03).')
            ->assertSeeText('[ok]');

        $this->get('/cron/scrape/fetch-history/NOPE?key=test-secret')
            ->assertOk()
            ->assertSeeText('Failed: No stock found for symbol [NOPE].')
            ->assertSeeText('[failed]');
    }

    public function test_backtest_signals_runs_the_service_directly(): void
    {
        $this->get('/cron/reports/backtest-signals?key=test-secret')
            ->assertOk()
            ->assertSeeText('$ signals:backtest-accuracy')
            ->assertSeeText('Backtested over a 30-trading-day horizon')
            ->assertSeeText('[ok]');
    }

    public function test_a_failing_task_is_reported_and_alerted(): void
    {
        $this->mock(NepalStockScraperService::class)
            ->shouldReceive('scrape')->andThrow(new RuntimeException('nepalstock.com unreachable'));
        $this->mock(FailureAlertService::class)
            ->shouldReceive('notifyFailure')->once()->with('market:sync', 'nepalstock.com unreachable');

        $this->get('/cron/scrape/market-sync-stock?key=test-secret')
            ->assertOk()
            ->assertSeeText('Failed: nepalstock.com unreachable')
            ->assertSeeText('[failed]');
    }

    public function test_schedule_page_lists_tasks_with_their_descriptions(): void
    {
        $user = User::create(['name' => 'T', 'email' => 't@example.com', 'password' => 'password']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/schedule')
            ->assertOk()
            ->assertJsonPath('jobs.0.command', 'stocks:sync-list')
            ->assertJsonPath('jobs.1.command', 'market:sync')
            ->assertJsonPath('jobs.1.description', 'Scrape today\'s prices from the official nepalstock.com API');

        // Recalculation is event-driven now, not a scheduled job.
        $this->assertNotContains('market:recalculate', array_column($response->json('jobs'), 'command'));
    }

    private function price(Stock $stock, string $date): void
    {
        DailyPrice::create([
            'stock_id' => $stock->id, 'trade_date' => $date,
            'open_price' => 100, 'high_price' => 100, 'low_price' => 100, 'close_price' => 100, 'volume' => 1000,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
