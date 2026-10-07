<?php

namespace App\Tasks;

use App\Events\TaskFailed;
use App\Services\DataSources\SourceBlockedException;
use Throwable;

/**
 * Runs one Task for a URL-triggered cron route: appends its output to
 * the task's log file and announces a failure (TaskFailed — alerting
 * is AlertTaskFailure's job).
 */
class TaskRunner
{
    /** Returns the plain-text log for the caller. */
    public function run(Task $task): string
    {
        set_time_limit(0);
        // Listeners run synchronously, so a task can outlast the pinger's
        // own timeout (~30s on cron-job.org's free plan). Keep going if it
        // disconnects rather than risk stopping halfway through a write.
        ignore_user_abort(true);

        try {
            $output = $task->handle();
            $status = 'ok';
        } catch (Throwable $e) {
            if (($blocked = SourceBlockedException::in($e)) !== null) {
                // The website blocking us was not even asked (it is paused — see SourceBlockRegistry): nothing new
                // went wrong, so this run is skipped, not failed, and nobody is alerted again.
                $output = "Skipped: {$blocked->getMessage()}";
                $status = 'skipped';
            } else {
                $output = "Failed: {$e->getMessage()}";
                $status = 'failed';
                TaskFailed::dispatch($task->name(), $e->getMessage());
            }
        }

        file_put_contents(
            storage_path('logs/'.$task->logFile()),
            '['.now()->toDateTimeString()."] [{$status}] {$output}\n",
            FILE_APPEND
        );

        return "\$ {$task->name()}\n{$output}\n[{$status}]";
    }
}
