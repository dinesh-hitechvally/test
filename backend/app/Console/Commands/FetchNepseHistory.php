<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Services\MarketData\NepalStockHistoryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('stocks:fetch-nepse-history {symbol}')]
#[Description('Fetch one stock\'s price history (~1 year) from the official nepalstock.com API')]
class FetchNepseHistory extends Command
{
    public function handle(NepalStockHistoryService $history): int
    {
        $symbol = strtoupper((string) $this->argument('symbol'));
        $stock = Stock::where('symbol', $symbol)->first();

        if (! $stock) {
            $this->error("No stock found with symbol [{$symbol}].");

            return self::FAILURE;
        }

        $this->info("Fetching {$symbol}'s history from nepalstock.com (official)...");

        try {
            $result = $history->fetchHistory($stock);
        } catch (Throwable $e) {
            $this->error('Fetch failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%d rows imported (%s to %s).',
            $result['rows_imported'],
            $result['oldest_date'],
            $result['newest_date']
        ));

        return self::SUCCESS;
    }
}
