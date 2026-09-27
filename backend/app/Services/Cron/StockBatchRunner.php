<?php

namespace App\Services\Cron;

use Throwable;

class StockBatchRunner
{
    /** Runs one batch of $job and returns the plain-text log for the caller. */
    public function run(StockBatchJob $job, ?int $limit = null): string
    {
        set_time_limit(0);

        if (($reason = $job->unavailableReason()) !== null) {
            return $reason;
        }

        $limit = max(1, $limit ?? $job->defaultLimit());
        $stocks = $job->pending()->orderBy('id')->limit($limit)->get();

        if ($stocks->isEmpty()) {
            return $job->emptyMessage();
        }

        $lines = [];

        foreach ($stocks as $stock) {
            try {
                $lines[] = $job->process($stock);
            } catch (Throwable $e) {
                $lines[] = $job->failed($stock, $e);
            }

            if ($job->pauseMicroseconds() > 0) {
                usleep($job->pauseMicroseconds());
            }
        }

        $lines[] = $job->remainingMessage($job->pending()->count());

        return implode("\n", $lines);
    }
}
