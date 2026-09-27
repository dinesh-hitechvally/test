<?php

namespace App\Tasks\MarketData;

use App\Services\DataSources\NepalStock\NepalStockIndexService;
use App\Tasks\Task;

class MarketSyncIndexTask extends Task
{
    public function __construct(private readonly NepalStockIndexService $indices) {}

    public function name(): string
    {
        return 'market:sync-index';
    }

    public function description(): string
    {
        return 'Snapshot the NEPSE Index and sub-indices from the official nepalstock.com API';
    }

    public function logFile(): string
    {
        return 'market-sync-index.log';
    }

    public function handle(): string
    {
        $result = $this->indices->sync();

        if (! $result['market_open']) {
            return 'Market is closed today — nothing synced.';
        }

        return "{$result['indices_updated']} index snapshot(s) updated.";
    }
}
