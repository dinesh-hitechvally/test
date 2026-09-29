<?php

namespace App\Tasks\MarketData;

use App\Services\DataSources\NepalStock\NepalStockIndexService;
use App\Tasks\Task;

class MarketSyncIndexTask extends Task
{
    public function __construct(private readonly NepalStockIndexService $indices) {}

    public function handle(): string
    {
        $result = $this->indices->sync();

        if (! $result['market_open']) {
            return 'Market is closed today — nothing synced.';
        }

        return "{$result['indices_updated']} index snapshot(s) updated.";
    }
}
