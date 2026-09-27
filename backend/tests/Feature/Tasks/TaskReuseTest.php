<?php

namespace Tests\Feature\Tasks;

use App\Events\TaskFailed;
use App\Tasks\Analysis\BacktestSignalsTask;
use App\Tasks\MarketData\FetchStockHistoryTask;
use App\Tasks\TaskRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** Tasks aren't tied to cron: any code can run them, with or without the runner. */
class TaskReuseTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_task_can_be_run_directly_from_code(): void
    {
        $summary = app(BacktestSignalsTask::class)->handle();

        $this->assertStringContainsString('Backtested over a 30-trading-day horizon', $summary);
    }

    public function test_the_runner_adds_logging_and_failure_events_outside_cron_too(): void
    {
        Event::fake([TaskFailed::class]);

        // No HTTP request involved — withRequest() is only for URL input.
        $output = app(TaskRunner::class)->run(app(FetchStockHistoryTask::class));

        $this->assertStringContainsString('[failed]', $output);
        Event::assertDispatched(TaskFailed::class);
    }
}
