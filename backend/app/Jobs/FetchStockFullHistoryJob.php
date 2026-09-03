<?php

namespace App\Jobs;

use App\Models\Stock;
use App\Services\MarketData\SharesansarHistoryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Background counterpart to StockController::fetchFullHistory() — same
 * service, just dispatched onto the queue instead of a user click. Enqueued
 * by stocks:queue-missing-history for any stock that has never had a
 * successful full-history fetch, so new stocks (discovered by daily scrapes)
 * get their history filled in automatically without per-stock manual action.
 */
class FetchStockFullHistoryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 60;

    public int $timeout = 300;

    public function __construct(public readonly int $stockId) {}

    public function handle(SharesansarHistoryService $history): void
    {
        $stock = Stock::find($this->stockId);

        if ($stock === null || $stock->history_fetched_at !== null) {
            return; // deleted, or already fetched (e.g. manually) since this job was queued
        }

        try {
            $history->fetchFullHistory($stock);
        } catch (Throwable $e) {
            // Already logged + ScrapeLog'd inside the service; let the queue's
            // retry/backoff handle transient failures without crashing the worker.
            Log::warning('Queued full-history fetch failed', ['stock_id' => $this->stockId, 'error' => $e->getMessage()]);

            throw $e;
        }
    }
}
