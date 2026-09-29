<?php

namespace App\Tasks\MarketData;

use App\Models\Stock;
use App\Services\DataSources\MeroLagani\MeroLaganiFundamentalsService;
use App\Tasks\PerStockTask;
use Illuminate\Database\Eloquent\Builder;

/**
 * EPS/P/E/book value only move quarterly (or drift slowly with price), so
 * a week-old row is still fine to show — this just keeps every stock from
 * going more than ~7 days stale.
 */
class SyncFundamentalsTask extends PerStockTask
{
    public function __construct(private readonly MeroLaganiFundamentalsService $fundamentals) {}

    protected function pending(): Builder
    {
        $staleBefore = now()->subDays(7);

        return Stock::whereDoesntHave('fundamental', function ($q) use ($staleBefore) {
            $q->where('fetched_at', '>=', $staleBefore);
        });
    }

    protected function pauseMicroseconds(): int
    {
        return 500_000;
    }

    protected function process(Stock $stock): string
    {
        $data = $this->fundamentals->syncOne($stock);

        return "{$stock->symbol}: EPS={$data->eps} PE={$data->pe_ratio} BookValue={$data->book_value}";
    }

    protected function nothingPendingMessage(): string
    {
        return 'No stocks are due for a fundamentals refresh.';
    }
}
