<?php

namespace App\Listeners;

use App\Events\StockPricesUpdated;
use App\Models\Stock;
use App\Services\DataQuality\DataQualityService;

/**
 * Runs the cheap, per-row data-quality checks (invalid OHLC, an abnormal
 * change, missing volume) on whatever just landed. Only looks at each
 * stock's newest row — the common case (a daily sync touching today) is
 * covered inline and for free; a bulk history backfill's older rows are
 * still caught by ScanDataQualityTask's daily sweep.
 */
class FlagPriceQualityIssues
{
    public function __construct(private readonly DataQualityService $quality) {}

    public function handle(StockPricesUpdated $event): void
    {
        if ($event->stockIds === []) {
            return;
        }

        Stock::whereIn('id', $event->stockIds)->get()->each(function (Stock $stock) {
            $latestTwo = $stock->dailyPrices()->orderByDesc('trade_date')->limit(2)->get()->reverse()->values();

            if ($latestTwo->isEmpty()) {
                return;
            }

            $previous = $latestTwo->count() > 1 ? $latestTwo->first() : null;
            $latest = $latestTwo->last();

            $this->quality->checkOhlc($latest);
            $this->quality->checkAbnormalChange($latest, $previous);
            $this->quality->checkMissingVolume($latest, $previous);
        });
    }
}
