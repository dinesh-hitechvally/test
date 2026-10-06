<?php

namespace App\Tasks\MarketData;

use App\Contracts\PriceHistorySource;
use App\Tasks\SingleStockTask;

/**
 * The one-stock counterpart to FetchHistoriesTask — same full-history fetch
 * as the "Fetch Full History" button on a stock's page, reachable by URL
 * (/cron/fetch/history/NABIL). Re-running re-fetches (it's an
 * upsert), and it isn't limited to stocks that never had history — this is
 * also how a stock flagged with a history error gets retried by hand.
 */
class FetchStockHistoryTask extends SingleStockTask
{
    public function __construct(private readonly PriceHistorySource $history) {}

    public function handle(): string
    {
        $result = $this->history->fetchHistory($this->stock());

        return "{$result['rows_imported']} rows imported ({$result['oldest_date']} to {$result['newest_date']}).";
    }
}
