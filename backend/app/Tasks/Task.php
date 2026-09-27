<?php

namespace App\Tasks;

use Illuminate\Http\Request;

/**
 * One background unit of work (sync market prices, recalculate, train the
 * model, run a backtest...), grouped by domain in app/Tasks the same way
 * app/Services is. A task calls its services and returns a plain-text
 * summary; throwing from handle() is how it reports failure.
 *
 * Tasks don't know how they're triggered. Run one through TaskRunner (adds
 * the log file + failure alert) from anywhere — today that's the /cron/*
 * URLs (config/cron.php), but a controller, a test or a queued job can use
 * the same class: app(MarketSyncTask::class)->handle().
 *
 * Tasks that work through every pending stock one at a time extend
 * PerStockTask instead of this directly.
 */
abstract class Task
{
    /**
     * Picks up input when a task is run from an HTTP request (a route
     * parameter or a ?query flag). Most tasks need nothing, so the default
     * is a no-op.
     */
    public function withRequest(Request $request): static
    {
        return $this;
    }

    /** Short identifier shown in logs, alerts and the schedule page (e.g. "market:sync"). */
    abstract public function name(): string;

    /** One line for the schedule page's "What it does" column. */
    abstract public function description(): string;

    /** File under storage/logs/ that each run's output is appended to. */
    abstract public function logFile(): string;

    /** Runs the step and returns its summary. Throws on failure. */
    abstract public function handle(): string;
}
