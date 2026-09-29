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
 * in the same request. This is for re-running by hand: stocks priced on
 * the latest trading date by default, or every stock with ?all=1 after changing indicator
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
        return $this->all ? parent::name().' (all stocks)' : parent::name();
    }

    public function handle(): string
    {
        $stocks = $this->all
            ? Stock::all()
            : Stock::whereIn('id', DB::table('daily_prices')->where('trade_date', DB::table('daily_prices')->max('trade_date'))->pluck('stock_id'))->get();

        if ($stocks->isEmpty()) {
            return 'No price rows yet — nothing to recalculate.';
        }

        $this->pipeline->runForMany($stocks);

        return "Recalculated indicators/signals for {$stocks->count()} stock(s).";
    }
}
