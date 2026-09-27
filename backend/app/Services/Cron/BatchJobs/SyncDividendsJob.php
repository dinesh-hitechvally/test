<?php

namespace App\Services\Cron\BatchJobs;

use App\Models\Stock;
use App\Services\DataSources\NepalStock\NepalStockCorporateActionsService;
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
class SyncDividendsJob extends StockBatchJob
{
    public function __construct(private readonly NepalStockCorporateActionsService $dividends) {}

    public function pending(): Builder
    {
        return Stock::whereDoesntHave('scrapeStatus', function ($q) {
            $q->whereNotNull('dividend_fetched_at');
        });
    }

    public function pauseMicroseconds(): int
    {
        return 500_000; // polite pacing — same sources as the price-history scrapers
    }

    public function process(Stock $stock): string
    {
        $result = $this->dividends->fetchDividends($stock);
        $stock->ensureScrapeStatus()->markDividendFetched();

        return "{$stock->symbol}: {$result['dividends']} dividend row(s) imported.";
    }

    public function failed(Stock $stock, Throwable $e): string
    {
        $stock->ensureScrapeStatus()->flagDividendError($e->getMessage());

        return parent::failed($stock, $e);
    }

    public function emptyMessage(): string
    {
        return "No stocks are missing dividend data.\n";
    }

    public function remainingMessage(int $remaining): string
    {
        return "{$remaining} stock(s) still missing dividend data — re-ping this URL to continue.";
    }
}
