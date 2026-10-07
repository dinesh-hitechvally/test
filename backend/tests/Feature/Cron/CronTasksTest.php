<?php

namespace Tests\Feature\Cron;

use App\Contracts\PriceHistorySource;
use App\Models\DailyPrice;
use App\Models\Stock;
use App\Models\User;
use App\Services\Alerts\FailureAlertService;
use App\Services\DataSources\DailyPriceSyncService;
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

    public function test_recalculate_picks_up_only_stocks_with_new_or_changed_prices_unless_all_is_passed(): void
    {
        $a = Stock::create(['symbol' => 'AAA', 'company_name' => 'A', 'is_active' => true]);
        $b = Stock::create(['symbol' => 'BBB', 'company_name' => 'B', 'is_active' => true]);
        $this->price($a, now()->toDateString());
        $this->price($b, '2024-01-01');

        $this->get('/cron/generate/indicators?key=test-secret')
            ->assertOk()
            ->assertSeeText('$ recalculate-market')
            ->assertSeeText('Recalculated indicators for 2 stock(s).')
            ->assertSeeText('[ok]');

        $this->get('/cron/generate/indicators?key=test-secret')
            ->assertOk()
            ->assertSeeText('nothing to recalculate');

        $this->get('/cron/generate/indicators?key=test-secret&all=1')
            ->assertOk()
            ->assertSeeText('$ recalculate-market (all stocks)')
            ->assertSeeText('Recalculated indicators for 2 stock(s).');

        $this->assertSame(1, $b->technicalIndicators()->count());
    }

    public function test_every_cron_route_runs_a_task_and_needs_the_key(): void
    {
        $cronRoutes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'cron/'));

        $this->assertCount(18, $cronRoutes);

        foreach ($cronRoutes as $route) {
            $this->assertTrue(is_subclass_of($route->defaults['task'] ?? '', Task::class), $route->uri());
            $this->get('/'.str_replace('{symbol}', 'X', $route->uri()))->assertForbidden();
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

        $this->get('/cron/fetch/history/nabil?key=test-secret')
            ->assertOk()
            ->assertSeeText('$ fetch-stock-history NABIL')
            ->assertSeeText('3 rows imported (2024-01-01 to 2024-01-03).')
            ->assertSeeText('[ok]');

        $this->get('/cron/fetch/history/NOPE?key=test-secret')
            ->assertOk()
            ->assertSeeText('Failed: No stock found for symbol [NOPE].')
            ->assertSeeText('[failed]');
    }

    public function test_backtest_signals_runs_the_service_directly(): void
    {
        $this->get('/cron/backtest/signals?key=test-secret')
            ->assertOk()
            ->assertSeeText('$ backtest-signals')
            ->assertSeeText('Backtested over a 30-trading-day horizon')
            ->assertSeeText('[ok]');
    }

    public function test_a_failing_task_is_reported_and_alerted(): void
    {
        $this->mock(DailyPriceSyncService::class)
            ->shouldReceive('sync')->andThrow(new RuntimeException('nepalstock.com unreachable'));
        $this->mock(FailureAlertService::class)
            ->shouldReceive('notifyFailure')->once()->with('market-sync', 'nepalstock.com unreachable');

        $this->get('/cron/fetch/prices?key=test-secret')
            ->assertOk()
            ->assertSeeText('Failed: nepalstock.com unreachable')
            ->assertSeeText('[failed]');
    }

    public function test_settings_page_reports_the_secret_and_flagged_stocks(): void
    {
        Sanctum::actingAs(User::create(['name' => 'T', 'email' => 't@example.com', 'password' => 'password']));

        $this->graphQL('{ dataSourceStatus { secret_configured flagged_stocks { symbol } } }')
            ->assertExactJson(['data' => ['dataSourceStatus' => ['secret_configured' => true, 'flagged_stocks' => []]]]);
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
