<?php

namespace App\Services\Cron;

use App\Events\CronTaskFailed;
use Throwable;

/**
 * Runs one CronTask for a URL-triggered cron route: appends its output to
 * the task's log file and announces a failure (CronTaskFailed — alerting
 * is AlertCronFailure's job).
 */
class CronTaskRunner
{
    /** Returns the plain-text log for the caller. */
    public function run(CronTask $task): string
    {
        set_time_limit(0);

        try {
            $output = $task->handle();
            $status = 'ok';
        } catch (Throwable $e) {
            $output = "Failed: {$e->getMessage()}";
            $status = 'failed';
            CronTaskFailed::dispatch($task->name(), $e->getMessage());
        }

        file_put_contents(
            storage_path('logs/'.$task->logFile()),
            '['.now()->toDateTimeString()."] [{$status}] {$output}\n",
            FILE_APPEND
        );

        return "\$ {$task->name()}\n{$output}\n[{$status}]";
    }
}
