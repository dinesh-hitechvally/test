<?php

namespace App\Services\Cron\Jobs;

use App\Contracts\PriceHistorySource;
use App\Models\Stock;
use App\Services\Cron\StockBatchJob;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fetches full history for stocks missing it. fetchHistory() sets
 * history_fetched_at (on stock_scrape_statuses) as each stock finishes.
 *
 * A stock whose last attempt failed (history_error set) is skipped on
 * purpose: without that, a permanently-failing stock (bad symbol,
 * delisted, source layout changed) would get retried on every single ping
 * forever. It stays visible on the Data Source Settings page either way,
 * and clears itself the next time /fetch-history/{symbol} is run for it
 * manually and succeeds.
 */
class FetchHistoriesJob extends StockBatchJob
{
    public function __construct(private readonly PriceHistorySource $history) {}

    public function pending(): Builder
    {
        return Stock::whereDoesntHave('scrapeStatus', function ($q) {
            $q->whereNotNull('history_fetched_at')->orWhereNotNull('history_error');
        });
    }

    public function defaultLimit(): int
    {
        return 1;
    }

    public function process(Stock $stock): string
    {
        $result = $this->history->fetchHistory($stock);

        return "{$stock->symbol}: {$result['rows_imported']} rows imported ({$result['oldest_date']} to {$result['newest_date']}).";
    }

    public function emptyMessage(): string
    {
        return "No stocks are missing full history.\n";
    }

    public function remainingMessage(int $remaining): string
    {
        return "{$remaining} stock(s) still missing history — re-ping this URL to continue.";
    }
}
