<?php

namespace App\Console\Commands;

use App\Services\MarketData\NepalStockIndexService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('market:sync-index')]
#[Description('Snapshot the NEPSE Index and sub-indices from the official nepalstock.com API')]
class SyncNepseIndex extends Command
{
    public function handle(NepalStockIndexService $service): int
    {
        try {
            $result = $service->sync();
        } catch (Throwable $e) {
            $this->error('Index sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $result['market_open']) {
            $this->info('Market is closed today — nothing synced.');

            return self::SUCCESS;
        }

        $this->info("{$result['indices_updated']} index snapshot(s) updated.");

        return self::SUCCESS;
    }
}
