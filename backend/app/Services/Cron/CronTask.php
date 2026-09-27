<?php

namespace App\Services\Cron;

use Illuminate\Http\Request;

/**
 * One whole-market pipeline step triggered by a /cron/* URL (market sync,
 * recalculation, ML training, backtests...). A task calls its service
 * directly and returns a plain-text summary; CronTaskRunner owns the
 * shared plumbing (log file, failure alert, response text). Throwing from
 * handle() is how a task reports failure.
 *
 * Tasks that work through every pending stock one at a time extend
 * PerStockTask instead of this directly. Every task is listed, with its
 * URL and schedule, in CronSchedule::TASKS.
 */
abstract class CronTask
{
    /**
     * Picks up anything the task needs from its URL (a route parameter or a
     * ?query flag). Most tasks need nothing, so the default is a no-op.
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
