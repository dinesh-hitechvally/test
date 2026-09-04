<?php

namespace App\Console\Commands;

use App\Services\MarketData\ShareSansarIpoService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('market:sync-ipo')]
#[Description('Scrape current and upcoming IPO/issue listings from ShareSansar')]
class SyncIpoListings extends Command
{
    public function handle(ShareSansarIpoService $service): int
    {
        try {
            $result = $service->scrape();
        } catch (Throwable $e) {
            $this->error('IPO sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$result['open']} open issue(s), {$result['upcoming']} upcoming issue(s).");

        return self::SUCCESS;
    }
}
