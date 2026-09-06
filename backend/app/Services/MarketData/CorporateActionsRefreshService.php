<?php

namespace App\Services\MarketData;

use App\Models\Stock;
use Throwable;

/**
 * Single entry point for refreshing one stock's dividend/right-share data —
 * tries ShareSansar first, and only if it comes back completely empty (its
 * dividend/right-share AJAX endpoints have been persistently blocked), falls
 * back to the official nepalstock.com dividend feed. Shared by the HTTP
 * "Refresh Dividend/Bonus Data" button and the bulk backfill command so the
 * fallback logic can't drift between the two call sites.
 */
class CorporateActionsRefreshService
{
    public function __construct(
        private readonly SharesansarHistoryService $sharesansar,
        private readonly NepalStockCorporateActionsService $nepse,
    ) {}

    /**
     * @return array{dividends: int, right_shares: int, sources: list<string>}
     */
    public function refresh(Stock $stock): array
    {
        $sources = [];

        try {
            $result = $this->sharesansar->fetchCorporateActions($stock);
            if ($result['dividends'] > 0 || $result['right_shares'] > 0) {
                $sources[] = 'sharesansar.com';
            }
        } catch (Throwable $e) {
            $result = ['dividends' => 0, 'right_shares' => 0];
        }

        if ($result['dividends'] === 0) {
            try {
                $nepse = $this->nepse->fetchDividends($stock);
                if ($nepse['dividends'] > 0) {
                    $result['dividends'] = $nepse['dividends'];
                    $sources[] = 'nepalstock.com';
                }
            } catch (Throwable $e) {
                // Both sources failing is reported via an empty $sources list.
            }
        }

        return [...$result, 'sources' => $sources];
    }
}
