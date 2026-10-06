<?php

namespace App\Tasks\Analysis;

use App\Models\Stock;
use App\Services\Analysis\RecalculationPipeline;
use App\Tasks\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one cron that generates technical indicators (and the next-close estimate built on them). Price syncs
 * only write prices; this picks up every stock with a price row that has no indicator row yet, or was written
 * after its indicator row, so it is safe to run as often as wanted. ?all=1 forces every stock, e.g. after
 * changing the indicator maths. Buy / sell / hold signals are NOT made here: run generate/signals afterwards.
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
        set_time_limit(0); // a full market recalculation runs a few hundred stocks inline

        $stocks = $this->all ? Stock::all() : Stock::whereIn('id', $this->staleStockIds())->get();

        if ($stocks->isEmpty()) {
            return 'Indicators are up to date — nothing to recalculate.';
        }

        $this->pipeline->runForMany($stocks);

        return "Recalculated indicators for {$stocks->count()} stock(s).";
    }

    /**
     * Stocks with a price row that has no indicator row at least as new as it.
     *
     * @return Collection<int, int>
     */
    private function staleStockIds(): Collection
    {
        return DB::table('daily_prices as dp')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('technical_indicators as ti')
                    ->whereColumn('ti.stock_id', 'dp.stock_id')
                    ->whereColumn('ti.trade_date', 'dp.trade_date')
                    ->whereColumn('ti.updated_at', '>=', 'dp.updated_at');
            })
            ->distinct()
            ->pluck('dp.stock_id');
    }
}
