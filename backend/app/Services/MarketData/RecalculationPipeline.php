<?php

namespace App\Services\MarketData;

use App\Models\Stock;
use Illuminate\Support\Collection;

/**
 * Runs indicators -> signals for one or more stocks, in order, synchronously.
 * Called right after a scrape or CSV import.
 */
class RecalculationPipeline
{
    public function __construct(
        private readonly IndicatorRecalculationService $indicators,
        private readonly SignalGeneratorService $signals,
    ) {}

    public function runFor(Stock $stock): void
    {
        $this->indicators->recalculate($stock);
        $this->signals->generate($stock);
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
