<?php

namespace App\Listeners;

use App\Events\StockPricesUpdated;
use App\Models\Stock;
use App\Services\MarketData\RecalculationPipeline;

/**
 * Keeps indicators → signals → next-close in step with prices: runs the
 * pipeline for exactly the stocks whose prices just changed. This is what
 * chains market sync → recalculation now, instead of a separate cron ping
 * ten minutes later.
 */
class RecalculateUpdatedStocks
{
    public function __construct(private readonly RecalculationPipeline $pipeline) {}

    public function handle(StockPricesUpdated $event): void
    {
        if ($event->stockIds === []) {
            return;
        }

        set_time_limit(0); // a full market sync recalculates a few hundred stocks inline

        $this->pipeline->runForMany(Stock::whereIn('id', $event->stockIds)->get());
    }
}
