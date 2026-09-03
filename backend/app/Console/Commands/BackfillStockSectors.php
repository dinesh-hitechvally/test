<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Services\MarketData\SharesansarHistoryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('stocks:backfill-sectors {--all : Re-fetch every stock, not just ones missing a sector}')]
#[Description('Fetch and store each stock\'s sector from its ShareSansar company page')]
class BackfillStockSectors extends Command
{
    public function handle(SharesansarHistoryService $history): int
    {
        $stocks = $this->option('all')
            ? Stock::orderBy('symbol')->get()
            : Stock::whereNull('sector')->orderBy('symbol')->get();

        if ($stocks->isEmpty()) {
            $this->info('Every stock already has a sector — nothing to do (use --all to re-fetch anyway).');

            return self::SUCCESS;
        }

        $this->info("Backfilling sector for {$stocks->count()} stock(s)...");
        $bar = $this->output->createProgressBar($stocks->count());
        $bar->start();

        $found = 0;
        $missing = [];

        foreach ($stocks as $stock) {
            try {
                $sector = $history->fetchSector($stock);
                $sector ? $found++ : $missing[] = $stock->symbol;
            } catch (Throwable $e) {
                $missing[] = $stock->symbol;
            }

            usleep(300_000); // same polite pacing as fetchFullHistory()
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Done — {$found} sector(s) set.");

        if ($missing !== []) {
            $this->warn(count($missing).' stock(s) had no sector found: '.implode(', ', $missing));
        }

        return self::SUCCESS;
    }
}
