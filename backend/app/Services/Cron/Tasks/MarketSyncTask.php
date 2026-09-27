<?php

namespace App\Services\Cron\Tasks;

use App\Services\DataSources\NepalStock\NepalStockScraperService;

class MarketSyncTask extends CronTask
{
    public function __construct(private readonly NepalStockScraperService $scraper) {}

    public function name(): string
    {
        return 'market:sync';
    }

    public function description(): string
    {
        return 'Scrape today\'s prices from the official nepalstock.com API';
    }

    public function logFile(): string
    {
        return 'market-sync.log';
    }

    public function handle(): string
    {
        $result = $this->scraper->scrape();

        if (! $result['market_open']) {
            return 'Market is closed today — nothing synced.';
        }

        return "{$result['updated_prices']} stocks updated ({$result['created_stocks']} new). market:recalculate updates indicators/signals next.";
    }
}
