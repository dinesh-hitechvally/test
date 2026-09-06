<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Services\MarketData\CorporateActionsRefreshService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('stocks:backfill-corporate-actions {--all : Re-fetch every stock, not just ones with zero dividend rows}')]
#[Description('Backfill dividend/bonus-share and right-share history for stocks that don\'t have any yet — ShareSansar first, falling back to the official nepalstock.com dividend feed if that comes back empty')]
class BackfillCorporateActions extends Command
{
    public function handle(CorporateActionsRefreshService $refresher): int
    {
        $stocks = $this->option('all')
            ? Stock::all()
            : Stock::whereDoesntHave('dividends')->get();

        $this->info("Fetching corporate actions for {$stocks->count()} stock(s)...");

        $ok = 0;
        $failed = 0;

        foreach ($stocks as $stock) {
            try {
                $result = $refresher->refresh($stock);

                if ($result['sources'] === []) {
                    $this->warn("  {$stock->symbol}: no data from either source.");
                    $failed++;

                    continue;
                }

                $this->line(sprintf(
                    '  %s: %d dividend row(s), %d right-share row(s) — via %s.',
                    $stock->symbol,
                    $result['dividends'],
                    $result['right_shares'],
                    implode(' + ', $result['sources'])
                ));
                $ok++;
            } catch (Throwable $e) {
                $this->warn("  {$stock->symbol}: failed — {$e->getMessage()}");
                $failed++;
            }

            usleep(500_000); // polite pacing — this hits the same sources as the price-history scrapers
        }

        $this->info("Done — {$ok} succeeded, {$failed} failed.");

        return self::SUCCESS;
    }
}
