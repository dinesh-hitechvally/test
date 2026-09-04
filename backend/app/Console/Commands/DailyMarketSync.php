<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Services\MarketData\NepalStockScraperService;
use App\Services\MarketData\RecalculationPipeline;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('market:sync')]
#[Description('Scrape today\'s prices from the official nepalstock.com API and recalculate indicators/signals/forecasts for every affected stock')]
class DailyMarketSync extends Command
{
    public function handle(NepalStockScraperService $scraper, RecalculationPipeline $pipeline): int
    {
        $this->info('Scraping latest prices from nepalstock.com (official)...');

        try {
            $result = $scraper->scrape();
        } catch (Throwable $e) {
            $this->error('Scrape failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%d stocks updated (%d new).',
            $result['updated_prices'],
            $result['created_stocks']
        ));

        $stocks = Stock::whereIn('id', $result['affected_stock_ids'])->get();
        $this->info("Recalculating indicators/signals/forecasts for {$stocks->count()} stock(s)...");

        $pipeline->runForMany($stocks);

        $this->info('Done.');

        return self::SUCCESS;
    }
}
