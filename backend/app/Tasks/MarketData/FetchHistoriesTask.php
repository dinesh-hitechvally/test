<?php

namespace App\Tasks\MarketData;

use App\Contracts\PriceHistorySource;
use App\Models\Stock;
use App\Tasks\PerStockTask;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fetches the full history of ONE stock missing it per run (the next pending one, by id) and
 * says how many are still waiting — a stock's history is dozens of paginated requests
 * (about a minute for a long-listed one), so a run is kept short. Ping again for the next.
 * fetchHistory() sets history_fetched_at (on stock_scrape_statuses) as the stock finishes.
 *
 * A stock whose last attempt failed (history_error set) is skipped on
 * purpose: a permanently-failing stock (bad symbol, delisted, source layout
 * changed) would otherwise be retried on every run forever. It stays visible
 * on the Data Source Settings page, and clears itself the next time
 * /fetch-history/{symbol} is run for it manually and succeeds.
 */
class FetchHistoriesTask extends PerStockTask
{
    /** Stocks fetched per run. */
    private const PER_RUN = 1;

    public function __construct(private readonly PriceHistorySource $history) {}

    protected function perRunLimit(): ?int
    {
        return self::PER_RUN;
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
