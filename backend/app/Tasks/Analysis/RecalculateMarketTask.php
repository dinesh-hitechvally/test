<?php

namespace App\Tasks\Analysis;

use App\Models\Stock;
use App\Services\Analysis\RecalculationPipeline;
use App\Tasks\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Manual recalculation. The routine one no longer needs this — every price
 * update fires StockPricesUpdated and RecalculateUpdatedStocks handles it
 * in the same request. This is for re-running by hand: stocks priced
 * today by default, or every stock with ?all=1 after changing indicator
 * or signal rules.
 */
class RecalculateMarketTask extends Task
{
    private bool $all = false;

    public function __construct(private readonly RecalculationPipeline $pipeline) {}

    public function withRequest(Request $request): static
    {
        $this->all = $request->boolean('all');

        return $this;
    }

    public function name(): string
    {
        return $this->all ? 'market:recalculate --all' : 'market:recalculate';
    }

    public function description(): string
    {
        return 'Manually recompute indicators/signals (today\'s stocks, or ?all=1 for every stock) — routine recalculation follows each price update automatically';
    }

    public function logFile(): string
    {
        return 'market-recalculate.log';
    }

    public function handle(): string
    {
        $stocks = $this->all
            ? Stock::all()
            : Stock::whereIn('id', DB::table('daily_prices')->whereDate('trade_date', today())->pluck('stock_id'))->get();

        if ($stocks->isEmpty()) {
            return 'No stocks have a price row from today — nothing to recalculate (market closed, or market:sync hasn\'t run yet).';
        }

        $this->pipeline->runForMany($stocks);

        return "Recalculated indicators/signals for {$stocks->count()} stock(s).";
    }
}
