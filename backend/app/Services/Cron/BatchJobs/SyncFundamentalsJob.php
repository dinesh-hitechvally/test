<?php

namespace App\Services\Cron\BatchJobs;

use App\Models\Stock;
use App\Services\DataSources\MeroLagani\MeroLaganiFundamentalsService;
use Illuminate\Database\Eloquent\Builder;

/**
 * EPS/P/E/book value only move quarterly (or drift slowly with price), so
 * a week-old row is still fine to show — this just keeps every stock from
 * going more than ~7 days stale.
 */
class SyncFundamentalsJob extends StockBatchJob
{
    public function __construct(private readonly MeroLaganiFundamentalsService $fundamentals) {}

    public function pending(): Builder
    {
        $staleBefore = now()->subDays(7);

        return Stock::whereDoesntHave('fundamental', function ($q) use ($staleBefore) {
            $q->where('fetched_at', '>=', $staleBefore);
        });
    }

    public function defaultLimit(): int
    {
        return 20;
    }

    public function pauseMicroseconds(): int
    {
        return 500_000;
    }

    public function process(Stock $stock): string
    {
        $data = $this->fundamentals->syncOne($stock);

        return "{$stock->symbol}: EPS={$data->eps} PE={$data->pe_ratio} BookValue={$data->book_value}";
    }

    public function emptyMessage(): string
    {
        return "No stocks are due for a fundamentals refresh.\n";
    }

    public function remainingMessage(int $remaining): string
    {
        return "{$remaining} stock(s) still due for a fundamentals refresh — re-ping this URL to continue.";
    }
}
