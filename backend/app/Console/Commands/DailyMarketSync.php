<?php

namespace App\Console\Commands;

use App\Services\MarketData\NepalStockScraperService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Fetch-only — deliberately does not touch indicators/signals.
 * That recalculation is `market:recalculate`, run as its own scheduled step
 * right after this one, so a slow or failing recalculation can never block
 * (or be blamed on) today's price fetch, and either half can be re-run alone.
 */
#[Signature('market:sync')]
#[Description('Scrape today\'s prices from the official nepalstock.com API — the only data source this app uses')]
class DailyMarketSync extends Command
{
    public function handle(NepalStockScraperService $scraper): int
    {
        $this->info('Scraping latest prices from nepalstock.com (official)...');

        try {
            $result = $scraper->scrape();
        } catch (Throwable $e) {
            $this->error('Scrape failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $result['market_open']) {
            $this->info('Market is closed today — nothing synced.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d stocks updated (%d new).',
            $result['updated_prices'],
            $result['created_stocks']
        ));

        $this->info('Done. Run `market:recalculate` to update indicators/signals.');

        return self::SUCCESS;
    }
}
