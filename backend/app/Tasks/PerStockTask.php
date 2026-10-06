<?php

namespace App\Tasks;

use App\Models\Stock;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * A Task that works through EVERY stock still pending for it, one at a
 * time, in a single run — no batch size, no re-pinging to continue.
 *
 * Each stock is saved (or marked failed) as soon as it's done, so if the
 * host kills a long-running request partway, nothing is lost: the next run
 * starts from whatever is still pending. The list is snapshotted once at the
 * start, so a stock that fails can't loop forever within one run.
 *
 * A subclass only says what "pending" means and what to do with one stock.
 */
abstract class PerStockTask extends Task
{
    /** Stocks still needing this task. */
    abstract protected function pending(): Builder;

    /** Processes one stock and returns its log line. Throwing hands the stock to failed(). */
    abstract protected function process(Stock $stock): string;

    abstract protected function nothingPendingMessage(): string;

    /**
     * Most stocks one run may handle; null = every pending stock. A task whose per-stock work is
     * slow (full price history: about a minute each) caps it so a single ping stays short and
     * can't tie up the server for hours. Ping again for the next one.
     */
    protected function perRunLimit(): ?int
    {
        return null;
    }

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

        $query = $this->pending()->orderBy('id');

        if ($limit = $this->perRunLimit()) {
            $query->limit($limit);
        }

        $stocks = $query->get();

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

        if ($this->perRunLimit() !== null && ($left = $this->pending()->reorder()->count()) > 0) {
            $lines[] = sprintf('%d stock(s) still pending — run it again for the next.', $left);
        }

        return implode("\n", $lines);
    }
}
