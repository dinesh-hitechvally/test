<?php

namespace App\Services\Cron;

use App\Models\Stock;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * A CronTask that works through EVERY stock still pending for it, one at a
 * time, in a single run — no batch size, no re-pinging to continue.
 *
 * Each stock is saved (or marked failed) as soon as it's done, so if the
 * host kills a long-running request partway, nothing is lost: the next run
 * starts from whatever is still pending. The list is snapshotted once at the
 * start, so a stock that fails can't loop forever within one run.
 *
 * A subclass only says what "pending" means and what to do with one stock.
 */
abstract class PerStockTask extends CronTask
{
    /** Stocks still needing this task. */
    abstract protected function pending(): Builder;

    /** Processes one stock and returns its log line. Throwing hands the stock to failed(). */
    abstract protected function process(Stock $stock): string;

    abstract protected function nothingPendingMessage(): string;

    /** Polite pause between stocks, in microseconds. */
    protected function pauseMicroseconds(): int
    {
        return 0;
    }

    /** Non-null when the task can't run at all on this instance (e.g. missing API key). */
    protected function unavailableReason(): ?string
    {
        return null;
    }

    /** Log line for a stock whose process() threw — override to also record the failure. */
    protected function failed(Stock $stock, Throwable $e): string
    {
        return "{$stock->symbol}: failed — {$e->getMessage()}";
    }

    public function handle(): string
    {
        if (($reason = $this->unavailableReason()) !== null) {
            return $reason;
        }

        $stocks = $this->pending()->orderBy('id')->get();

        if ($stocks->isEmpty()) {
            return $this->nothingPendingMessage();
        }

        $lines = [];
        $failures = 0;

        foreach ($stocks as $stock) {
            try {
                $lines[] = $this->process($stock);
            } catch (Throwable $e) {
                $lines[] = $this->failed($stock, $e);
                $failures++;
            }

            if ($this->pauseMicroseconds() > 0) {
                usleep($this->pauseMicroseconds());
            }
        }

        $lines[] = sprintf('Done — %d stock(s) processed, %d failed.', $stocks->count() - $failures, $failures);

        return implode("\n", $lines);
    }
}
