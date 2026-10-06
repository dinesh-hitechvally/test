<?php

namespace App\Tasks\MarketData;

use App\Models\Stock;
use App\Models\StockScrapeStatus;
use App\Services\DataSources\NepalStock\NepalStockCorporateActionsService;
use App\Tasks\PerStockTask;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Dividend/bonus data (nepalstock.com's only source for it), ONE stock per run — each stock costs a token
 * request plus a dividend request, so a run is kept short instead of walking every stock in one long
 * request. Ping again for the next; the response says how many are left.
 *
 * Which stock is next: ones never tried come first (by id); a stock whose last try failed goes to the back
 * of the queue, so one bad connection or one persistently failing stock can't hold up the rest.
 *
 * Pending is dividend_fetched_at IS NULL, NOT "zero dividend rows" — a
 * stock can genuinely have never declared a dividend, which is a
 * successful fetch, not a pending one. To refresh a stock that already
 * succeeded (e.g. a newly-declared dividend), use its "Refresh
 * Dividend/Bonus Data" button (the refreshCorporateActions mutation).
 */
class SyncDividendsTask extends PerStockTask
{
    /** Stocks handled per run. */
    private const PER_RUN = 1;

    public function __construct(private readonly NepalStockCorporateActionsService $dividends) {}

    protected function perRunLimit(): ?int
    {
        return self::PER_RUN;
    }

    protected function pending(): Builder
    {
        return Stock::whereDoesntHave('scrapeStatus', function ($q) {
            $q->whereNotNull('dividend_fetched_at');
        })
            // Never-tried stocks (no failure time) first, then the longest-ago failures.
            ->orderBy(StockScrapeStatus::select('dividend_error_at')->whereColumn('stock_id', 'stocks.id')->limit(1));
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
