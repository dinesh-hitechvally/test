<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Services\MarketData\SharesansarHistoryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('stocks:backfill-corporate-actions {--all : Re-fetch every stock, not just ones with zero dividend rows}')]
#[Description('Backfill dividend/bonus-share and right-share history for stocks that don\'t have any yet')]
class BackfillCorporateActions extends Command
{
    public function handle(SharesansarHistoryService $history): int
    {
        $stocks = $this->option('all')
            ? Stock::all()
            : Stock::whereDoesntHave('dividends')->get();

        $this->info("Fetching corporate actions for {$stocks->count()} stock(s)...");

        $ok = 0;
        $failed = 0;

        foreach ($stocks as $stock) {
            try {
                $result = $history->fetchCorporateActions($stock);
                $this->line("  {$stock->symbol}: {$result['dividends']} dividend row(s), {$result['right_shares']} right-share row(s).");
                $ok++;
            } catch (Throwable $e) {
                $this->warn("  {$stock->symbol}: failed — {$e->getMessage()}");
                $failed++;
            }

            usleep(500_000); // polite pacing — this hits the same source as the price-history scraper
        }

        $this->info("Done — {$ok} succeeded, {$failed} failed.");

        return self::SUCCESS;
    }
}
