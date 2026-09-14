<?php

namespace App\Services\MarketData;

use App\Models\Stock;
use Throwable;

/**
 * Single entry point for refreshing one stock's dividend data — shared by the
 * HTTP "Refresh Dividend/Bonus Data" button and the bulk backfill command.
 *
 * Dividends come from nepalstock.com's official dividend-application feed —
 * the app's one and only data source. `right_shares` stays in the return
 * shape (both call sites already expect it) but is always 0: NEPSE has no
 * official right-share endpoint, and ShareSansar (the only place that data
 * was ever available) is intentionally not used here.
 */
class CorporateActionsRefreshService
{
    public function __construct(
        private readonly NepalStockCorporateActionsService $nepse,
    ) {}

    /**
     * @return array{dividends: int, right_shares: int, sources: list<string>}
     */
    public function refresh(Stock $stock): array
    {
        try {
            $result = $this->nepse->fetchDividends($stock);

            return [
                'dividends' => $result['dividends'],
                'right_shares' => 0,
                'sources' => $result['dividends'] > 0 ? ['nepalstock.com'] : [],
            ];
        } catch (Throwable $e) {
            return ['dividends' => 0, 'right_shares' => 0, 'sources' => []];
        }
    }
}
