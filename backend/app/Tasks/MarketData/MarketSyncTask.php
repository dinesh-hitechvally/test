<?php

namespace App\Tasks\MarketData;

use App\Services\DataSources\DailyPriceSyncService;
use App\Services\DataSources\SourceFailover;
use App\Tasks\Task;

/**
 * Market open → the live prices; market closed → the final prices for the latest trading date. nepalstock.com
 * first, then ShareSansar, then MeroLagani if it can't be reached. See DailyPriceSyncService.
 */
class MarketSyncTask extends Task
{
    public function __construct(private readonly DailyPriceSyncService $prices) {}

    public function handle(): string
    {
        $r = $this->prices->sync();
        $state = $r['market_open'] ? 'market open, live prices' : 'market closed, final prices';

        if ($r['fetched'] === 0) {
            return "{$r['trade_date']} ({$state}): no prices available yet — nothing to update.";
        }

        return "{$r['trade_date']} ({$state} from {$r['source']}): {$r['fetched']} fetched — "
            ."{$r['inserted']} added, {$r['updated']} corrected, {$r['unchanged']} already up to date"
            .($r['skipped'] ? ", {$r['skipped']} unknown symbol(s) skipped" : '')
            .($r['created_stocks'] ? ", {$r['created_stocks']} new stock(s)" : '')
            .'.'
            .SourceFailover::note($r['failed_sources']);
    }
}
