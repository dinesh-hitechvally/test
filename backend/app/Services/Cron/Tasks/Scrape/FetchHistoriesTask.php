<?php

namespace App\Services\Cron\Tasks\Scrape;

use App\Services\Cron\PerStockTask;

use App\Contracts\PriceHistorySource;
use App\Models\Stock;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fetches full history for every stock missing it. fetchHistory() sets
 * history_fetched_at (on stock_scrape_statuses) as each stock finishes.
 *
 * A stock whose last attempt failed (history_error set) is skipped on
 * purpose: a permanently-failing stock (bad symbol, delisted, source layout
 * changed) would otherwise be retried on every run forever. It stays visible
 * on the Data Source Settings page, and clears itself the next time
 * /fetch-history/{symbol} is run for it manually and succeeds.
 */
class FetchHistoriesTask extends PerStockTask
{
    public function __construct(private readonly PriceHistorySource $history) {}

    public function name(): string
    {
        return 'fetch-histories';
    }

    public function description(): string
    {
        return 'Fetch full price history for every stock that doesn\'t have it yet';
    }

    public function logFile(): string
    {
        return 'fetch-histories.log';
    }

    protected function pending(): Builder
    {
        return Stock::whereDoesntHave('scrapeStatus', function ($q) {
            $q->whereNotNull('history_fetched_at')->orWhereNotNull('history_error');
        });
    }

    protected function process(Stock $stock): string
    {
        $result = $this->history->fetchHistory($stock);

        return "{$stock->symbol}: {$result['rows_imported']} rows imported ({$result['oldest_date']} to {$result['newest_date']}).";
    }

    protected function nothingPendingMessage(): string
    {
        return 'No stocks are missing full history.';
    }
}
