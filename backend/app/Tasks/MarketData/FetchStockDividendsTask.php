<?php

namespace App\Tasks\MarketData;

use App\Services\DataSources\NepalStock\NepalStockCorporateActionsService;
use App\Tasks\SingleStockTask;
use Throwable;

/**
 * The one-stock counterpart to SyncDividendsTask: fetches one stock's dividend / bonus data from
 * nepalstock.com (/cron/fetch/dividends/NABIL). Not limited to stocks that were never fetched — this
 * is how a stock whose dividend data is already in gets refreshed (a newly declared dividend), or one
 * flagged with an error gets retried. Same bookkeeping as the all-stocks job: success marks it fetched,
 * failure flags it.
 */
class FetchStockDividendsTask extends SingleStockTask
{
    public function __construct(private readonly NepalStockCorporateActionsService $dividends) {}

    public function handle(): string
    {
        $stock = $this->stock();

        try {
            $result = $this->dividends->fetchDividends($stock);
        } catch (Throwable $e) {
            $stock->ensureScrapeStatus()->flagDividendError($e->getMessage());

            throw $e;
        }

        $stock->ensureScrapeStatus()->markDividendFetched();

        return "{$stock->symbol}: {$result['dividends']} dividend row(s) imported.";
    }
}
