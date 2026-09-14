<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Services\MarketData\RecalculationPipeline;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recalculates indicators/signals/ML/forecasts — split out of market:sync so
 * a fetch failure can't silently skip recalculation (and vice versa), and so
 * either half can be re-run on its own without re-hitting nepalstock.com.
 *
 * Scoped to stocks with a price row dated today rather than being handed a
 * stock list in-process (the two commands run as separate scheduled steps,
 * possibly minutes apart, so there's no shared memory to pass that through)
 * — {--all} re-scores every stock regardless, for a manual full refresh.
 */
#[Signature('market:recalculate {--all : Recalculate every stock, not just ones with a price row from today}')]
#[Description('Recompute indicators/signals/ML predictions/forecasts for stocks priced today')]
class RecalculateMarketData extends Command
{
    public function handle(RecalculationPipeline $pipeline): int
    {
        $stocks = $this->option('all')
            ? Stock::all()
            : Stock::whereIn('id', DB::table('daily_prices')->whereDate('trade_date', today())->pluck('stock_id'))->get();

        if ($stocks->isEmpty()) {
            $this->info('No stocks have a price row from today — nothing to recalculate (market closed, or market:sync hasn\'t run yet).');

            return self::SUCCESS;
        }

        $this->info("Recalculating indicators/signals/forecasts for {$stocks->count()} stock(s)...");

        $pipeline->runForMany($stocks);

        $this->info('Done.');

        return self::SUCCESS;
    }
}
