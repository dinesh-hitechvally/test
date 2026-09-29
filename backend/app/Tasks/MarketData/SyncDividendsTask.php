<?php

namespace App\Tasks\MarketData;

use App\Models\Stock;
use App\Services\DataSources\NepalStock\NepalStockCorporateActionsService;
use App\Tasks\PerStockTask;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Dividend/bonus data (nepalstock.com's only source for it).
 *
 * Pending is dividend_fetched_at IS NULL, NOT "zero dividend rows" — a
 * stock can genuinely have never declared a dividend, which is a
 * successful fetch, not a pending one. To refresh a stock that already
 * succeeded (e.g. a newly-declared dividend), use its "Refresh
 * Dividend/Bonus Data" button (StockController::fetchCorporateActions).
 */
class SyncDividendsTask extends PerStockTask
{
    public function __construct(private readonly NepalStockCorporateActionsService $dividends) {}

    protected function pending(): Builder
    {
        return Stock::whereDoesntHave('scrapeStatus', function ($q) {
            $q->whereNotNull('dividend_fetched_at');
        });
    }

    protected function pauseMicroseconds(): int
    {
        return 500_000; // polite pacing — same sources as the price-history scrapers
    }

    protected function process(Stock $stock): string
    {
        $result = $this->dividends->fetchDividends($stock);
        $stock->ensureScrapeStatus()->markDividendFetched();

        return "{$stock->symbol}: {$result['dividends']} dividend row(s) imported.";
    }

    protected function failed(Stock $stock, Throwable $e): string
    {
        $stock->ensureScrapeStatus()->flagDividendError($e->getMessage());

        return parent::failed($stock, $e);
    }

    protected function nothingPendingMessage(): string
    {
        return 'No stocks are missing dividend data.';
    }
}
