<?php

namespace Tests\Feature\Cron;

use App\Models\DailyPrice;
use App\Models\Stock;
use App\Models\User;
use App\Services\Cron\CronAlertService;
use App\Services\DataSources\NepalStock\NepalStockScraperService;
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
        $this->mock(CronAlertService::class)
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
