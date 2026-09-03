<?php

namespace App\Console\Commands;

use App\Jobs\FetchStockFullHistoryJob;
use App\Models\Stock;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('stocks:queue-missing-history')]
#[Description('Queue a background full-history fetch for every stock that has never had one (e.g. newly discovered by a scrape)')]
class QueueMissingHistory extends Command
{
    public function handle(): int
    {
        $stocks = Stock::whereNull('history_fetched_at')->get(['id', 'symbol']);

        if ($stocks->isEmpty()) {
            $this->info('No stocks are missing full history.');

            return self::SUCCESS;
        }

        foreach ($stocks as $stock) {
            FetchStockFullHistoryJob::dispatch($stock->id);
        }

        $this->info("Queued full-history fetch for {$stocks->count()} stock(s): {$stocks->pluck('symbol')->implode(', ')}");

        return self::SUCCESS;
    }
}
