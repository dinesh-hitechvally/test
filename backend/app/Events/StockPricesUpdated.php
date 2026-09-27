<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * New or changed daily_prices rows landed for these stocks — from the daily
 * market sync, a full-history fetch, or a CSV import. Everything derived
 * from prices (indicators, signals, next-close estimate) is stale for them
 * until a listener recomputes it; see RecalculateUpdatedStocks.
 *
 * Listeners run synchronously (no queue worker on this hosting), so
 * whatever fired this waits for them to finish.
 */
class StockPricesUpdated
{
    use Dispatchable;

    /**
     * @param  int[]  $stockIds
     * @param  string  $source  where the prices came from, for logs (e.g. "nepalstock.com/live-market")
     */
    public function __construct(
        public readonly array $stockIds,
        public readonly string $source,
    ) {}
}
