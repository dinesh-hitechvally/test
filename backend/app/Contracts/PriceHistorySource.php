<?php

namespace App\Contracts;

use App\Models\Stock;

/**
 * Fetches and persists one stock's historical daily prices from some
 * external source. Bound in AppServiceProvider to whichever source is the
 * app's primary one (currently ShareSansar — see SharesansarHistoryService
 * for why it isn't nepalstock.com's own history endpoint).
 */
interface PriceHistorySource
{
    /**
     * Throws on failure.
     *
     * @return array{rows_imported: int, oldest_date: ?string, newest_date: ?string}
     */
    public function fetchHistory(Stock $stock): array;
}
