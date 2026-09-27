<?php

namespace App\Services\Cron\Tasks\Scrape;

use App\Contracts\PriceHistorySource;
use App\Models\Stock;
use App\Services\Cron\CronTask;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The one-stock counterpart to FetchHistoriesTask — same full-history fetch
 * as the "Fetch Full History" button on a stock's page, reachable by URL
 * (/cron/scrape/fetch-history/NABIL). Re-running re-fetches (it's an
 * upsert), and it isn't limited to stocks that never had history — this is
 * also how a stock flagged with a history error gets retried by hand.
 */
class FetchStockHistoryTask extends CronTask
{
    private string $symbol = '';

    public function __construct(private readonly PriceHistorySource $history) {}

    public function withRequest(Request $request): static
    {
        $this->symbol = strtoupper((string) $request->route('symbol'));

        return $this;
    }

    public function name(): string
    {
        return "fetch-history {$this->symbol}";
    }

    public function description(): string
    {
        return 'Fetch (or re-fetch) full price history for one stock';
    }

    public function logFile(): string
    {
        return 'fetch-history.log';
    }

    public function handle(): string
    {
        $stock = Stock::where('symbol', $this->symbol)->first()
            ?? throw new RuntimeException("No stock found for symbol [{$this->symbol}].");

        $result = $this->history->fetchHistory($stock);

        return "{$result['rows_imported']} rows imported ({$result['oldest_date']} to {$result['newest_date']}).";
    }
}
