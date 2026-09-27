<?php

namespace App\Services\Cron;

use App\Models\Stock;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * One batched, re-pingable per-stock cron job — the shape every
 * /cron/scrape/* batch endpoint shares: pick up to ?limit= pending stocks,
 * process each one, report how many are still pending. Deliberately
 * batched small: a web request/reverse-proxy timeout would otherwise kill
 * a run partway through, which is harmless here because each job marks a
 * stock done (or failed) as it goes, so an interrupted run just picks up
 * where it left off on the next ping.
 *
 * StockBatchRunner owns the loop; a subclass only says what "pending"
 * means and what to do with one stock. Adding a new batch job is a new
 * subclass, not another copy of the loop in CronController.
 */
abstract class StockBatchJob
{
    /** Stocks still needing this job — re-evaluated after the batch for the "N remaining" line. */
    abstract public function pending(): Builder;

    /** Processes one stock and returns its log line. Throwing hands the stock to failed(). */
    abstract public function process(Stock $stock): string;

    abstract public function emptyMessage(): string;

    abstract public function remainingMessage(int $remaining): string;

    public function defaultLimit(): int
    {
        return 5;
    }

    /** Polite pause between stocks within one batch, in microseconds. */
    public function pauseMicroseconds(): int
    {
        return 0;
    }

    /** Non-null when the job can't run at all on this instance (e.g. missing API key). */
    public function unavailableReason(): ?string
    {
        return null;
    }

    /** Log line for a stock whose process() threw — override to also record the failure. */
    public function failed(Stock $stock, Throwable $e): string
    {
        return "{$stock->symbol}: failed — {$e->getMessage()}";
    }
}
