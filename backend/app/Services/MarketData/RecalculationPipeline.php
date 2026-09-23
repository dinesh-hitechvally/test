<?php

namespace App\Services\MarketData;

use App\Models\Stock;
use Illuminate\Support\Collection;

/**
 * Runs indicators -> signals -> next-close estimate for one or more stocks,
 * in order, synchronously. Called right after a scrape or CSV import.
 */
class RecalculationPipeline
{
    public function __construct(
        private readonly IndicatorRecalculationService $indicators,
        private readonly SignalGeneratorService $signals,
        private readonly NextCloseEstimatorService $nextClose,
    ) {}

    public function runFor(Stock $stock): void
    {
        $this->indicators->recalculate($stock);
        $this->signals->generate($stock);
        $this->estimateNextClose($stock);
    }

    /**
     * Backfills a forecasts row for every one of the stock's trading days
     * that's still missing one, not just today's — see
     * NextCloseEstimatorService::backfillForStock() for why. Silently does
     * nothing where there isn't enough history for a read yet (a stock's
     * first ~50 days of trading).
     */
    private function estimateNextClose(Stock $stock): void
    {
        $this->nextClose->backfillForStock($stock);
    }

    /**
     * @param  Collection<int, Stock>  $stocks
     */
    public function runForMany(Collection $stocks): void
    {
        foreach ($stocks as $stock) {
            $this->runFor($stock);
        }
    }
}
