<?php

namespace App\Tasks;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * One background unit of work (sync market prices, recalculate, train the
 * model, run a backtest...), grouped by domain in app/Tasks the same way
 * app/Services is. A task calls its services and returns a plain-text
 * summary; throwing from handle() is how it reports failure.
 *
 * Tasks don't know how they're triggered. Run one through TaskRunner (adds
 * the log file + failure alert) from anywhere — today that's the /cron/*
 * URLs (routes/web.php), but a controller, a test or a queued job can use
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

    /**
     * Label shown in the run output and failure alerts, taken from the class
     * name: MarketSyncTask → "market-sync". Override only to add detail
     * (e.g. which stock).
     */
    public function name(): string
    {
        return Str::kebab(Str::beforeLast(class_basename($this), 'Task'));
    }

    /** File under storage/logs/ each run is appended to: "market-sync.log", from the class name. */
    public function logFile(): string
    {
        return Str::kebab(Str::beforeLast(class_basename($this), 'Task')).'.log';
    }

    /** Runs the step and returns its summary. Throws on failure. */
    abstract public function handle(): string;
}
