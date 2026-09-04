<?php

namespace App\Console\Commands;

use App\Services\MarketData\ShareSansarNewsService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('market:sync-news')]
#[Description('Scrape the latest market news headlines from ShareSansar')]
class SyncMarketNews extends Command
{
    public function handle(ShareSansarNewsService $service): int
    {
        try {
            $result = $service->scrape();
        } catch (Throwable $e) {
            $this->error('News sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$result['articles']} article(s) found.");

        return self::SUCCESS;
    }
}
