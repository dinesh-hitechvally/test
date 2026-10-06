<?php

namespace App\Services\Analysis;

use App\Models\Stock;
use App\Services\Analysis\Forecasting\NextCloseEstimatorService;
use App\Services\Analysis\Indicators\IndicatorRecalculationService;
use Illuminate\Support\Collection;

/**
 * Runs indicators -> next-close estimate for one or more stocks, in order, synchronously (the
 * generate/indicators cron). Signals are a separate step with their own cron (generate/signals): they read
 * the indicators stored here.
 */
class RecalculationPipeline
{
    public function __construct(
        private readonly IndicatorRecalculationService $indicators,
        private readonly NextCloseEstimatorService $nextClose,
    ) {}

    public function runFor(Stock $stock): void
    {
        $this->indicators->recalculate($stock);
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
