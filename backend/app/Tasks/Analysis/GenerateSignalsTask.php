<?php

namespace App\Tasks\Analysis;

use App\Models\Stock;
use App\Services\Analysis\Signals\SignalGeneratorService;
use App\Tasks\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one cron that generates buy / sell / hold signals. It reads the technical indicators already stored by
 * generate/indicators and writes the signals table — it never recomputes an indicator, so run it after that
 * one. It picks up every stock that has an indicator row with no signal yet, or one written after its signal,
 * so it is safe to run as often as wanted. ?all=1 regenerates every stock that has indicators, e.g. after
 * changing the signal rules.
 */
class GenerateSignalsTask extends Task
{
    private bool $all = false;

    public function __construct(private readonly SignalGeneratorService $signals) {}

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
        set_time_limit(0); // a full regeneration walks every stock's whole history

        $stocks = Stock::whereIn('id', $this->all ? $this->stockIdsWithIndicators() : $this->staleStockIds())->orderBy('symbol')->get();

        if ($stocks->isEmpty()) {
            return $this->all
                ? 'No indicators yet — run generate/indicators first.'
                : 'Signals are up to date — nothing to generate.';
        }

        $written = 0;

        foreach ($stocks as $stock) {
            $written += $this->signals->generate($stock);
        }

        return "Generated signals for {$stocks->count()} stock(s) ({$written} daily signal rows written).";
    }

    /**
     * Stocks with an indicator row that has no signal at least as new as it. Only rows with an SMA 20 count:
     * the generator makes no signal before that, so a stock with under 20 days of history would otherwise look
     * pending forever.
     *
     * @return Collection<int, int>
     */
    private function staleStockIds(): Collection
    {
        return DB::table('technical_indicators as ti')
            ->whereNotNull('ti.sma_20')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('signals as sg')
                    ->whereColumn('sg.stock_id', 'ti.stock_id')
                    ->whereColumn('sg.trade_date', 'ti.trade_date')
                    ->whereColumn('sg.updated_at', '>=', 'ti.updated_at');
            })
            ->distinct()
            ->pluck('ti.stock_id');
    }

    /** @return Collection<int, int> */
    private function stockIdsWithIndicators(): Collection
    {
        return DB::table('technical_indicators')->whereNotNull('sma_20')->distinct()->pluck('stock_id');
    }
}
