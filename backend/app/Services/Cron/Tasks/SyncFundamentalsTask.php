<?php

namespace App\Services\Cron\Tasks;

use App\Models\Stock;
use App\Services\DataSources\MeroLagani\MeroLaganiFundamentalsService;
use Illuminate\Database\Eloquent\Builder;

/**
 * EPS/P/E/book value only move quarterly (or drift slowly with price), so
 * a week-old row is still fine to show — this just keeps every stock from
 * going more than ~7 days stale.
 */
class SyncFundamentalsTask extends PerStockTask
{
    public function __construct(private readonly MeroLaganiFundamentalsService $fundamentals) {}

    public function name(): string
    {
        return 'fundamentals';
    }

    public function description(): string
    {
        return 'Refresh EPS / P/E / book value from merolagani.com for stocks missing them or 7+ days stale';
    }

    public function logFile(): string
    {
        return 'fundamentals.log';
    }

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
