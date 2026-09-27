<?php

namespace App\Services\DataSources;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Which stocks are currently flagged with a failed per-stock fetch
 * (history, sector or dividend — see StockScrapeStatus). Not a retry
 * mechanism, just visibility instead of a failure sitting silently in a log
 * file. A flag clears itself the next time that same kind of fetch succeeds.
 */
class ScrapeHealthService
{
    private const SOURCES = ['history', 'sector', 'dividend'];

    /**
     * Each source has its own error column, so a stock failing on two
     * sources appears as two rows — unioned into one flat shape, newest first.
     */
    public function flaggedStocks(): Collection
    {
        $query = null;

        foreach (self::SOURCES as $source) {
            $bySource = DB::table('stock_scrape_statuses')
                ->join('stocks', 'stocks.id', '=', 'stock_scrape_statuses.stock_id')
                ->whereNotNull("stock_scrape_statuses.{$source}_error")
                ->select(
                    'stocks.id',
                    'stocks.symbol',
                    'stocks.company_name',
                    DB::raw("'{$source}' as scrape_error_source"),
                    "stock_scrape_statuses.{$source}_error as scrape_error",
                    "stock_scrape_statuses.{$source}_error_at as scrape_error_at"
                );

            $query = $query ? $query->unionAll($bySource) : $bySource;
        }

        return $query->orderByDesc('scrape_error_at')->get();
    }
}
