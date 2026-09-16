<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SignalAccuracyStat;
use App\Models\Stock;
use App\Services\MarketData\MarketReportService;
use App\Services\MarketData\MlDirectionPredictorService;
use App\Services\MarketData\SignalRules;
use App\Services\MarketData\TechnicalAnalysisReportService;
use Illuminate\Http\Request;
use Throwable;

class ReportController extends Controller
{
    public function dashboard(MarketReportService $reports)
    {
        return response()->json($reports->dashboardSummary());
    }

    public function market(MarketReportService $reports)
    {
        $summary = $reports->dashboardSummary();

        return response()->json([
            'totals' => $summary['totals'],
            'signal_counts' => $summary['signal_counts'],
            'breadth' => $summary['breadth'],
            'movers' => $summary['movers'],
            'sector_performance' => $reports->sectorPerformance(),
            'trend' => $reports->trend(30),
        ]);
    }

    public function sectors()
    {
        $sectors = Stock::whereNotNull('sector')
            ->where('sector', '!=', '')
            ->groupBy('sector')
            ->selectRaw('sector, COUNT(*) as stock_count')
            ->orderByDesc('stock_count')
            ->get();

        return response()->json($sectors);
    }

    public function sector(Request $request, MarketReportService $reports)
    {
        $sector = $request->query('name');

        if (! $sector) {
            return response()->json(['message' => 'A sector name is required.'], 422);
        }

        $stocks = Stock::where('sector', $sector)
            ->with(['latestPrice', 'latestSignal'])
            ->orderBy('symbol')
            ->get();

        if ($stocks->isEmpty()) {
            return response()->json(['message' => "No stocks found for sector [{$sector}]."], 404);
        }

        $changes = $reports->priceChanges();

        $stocks->each(function ($stock) use ($changes) {
            $stock->change_pct = $changes->get($stock->id)['change_pct'] ?? null;
        });

        $withPct = $stocks->filter(fn ($s) => $s->change_pct !== null);

        $signalCounts = ['strong_buy' => 0, 'buy' => 0, 'hold' => 0, 'sell' => 0, 'strong_sell' => 0];
        foreach ($stocks as $stock) {
            if ($stock->latestSignal) {
                $signalCounts[$stock->latestSignal->signal]++;
            }
        }

        $ranked = $withPct->sortByDesc('change_pct')->values();

        return response()->json([
            'sector' => $sector,
            'totals' => [
                'stock_count' => $stocks->count(),
                'advancing' => $withPct->where('change_pct', '>', 0)->count(),
                'declining' => $withPct->where('change_pct', '<', 0)->count(),
            ],
            'signal_counts' => $signalCounts,
            'avg_change_pct' => $withPct->isNotEmpty() ? round($withPct->avg('change_pct'), 2) : null,
            'stocks' => $stocks->values(),
            'top_gainers' => $ranked->take(5)->values(),
            'top_losers' => $ranked->reverse()->take(5)->values(),
            'trend' => $reports->trend(30, $sector),
        ]);
    }

    public function stock(string $symbol, MarketReportService $reports)
    {
        $stock = Stock::with(['latestPrice', 'latestSignal'])
            ->where('symbol', strtoupper($symbol))
            ->firstOrFail();

        $signalCounts90d = $stock->signals()
            ->where('trade_date', '>=', now()->subDays(90))
            ->selectRaw('`signal`, COUNT(*) as count') // `signal` is a reserved word in MySQL (stored-procedure SIGNAL statement)
            ->groupBy('signal')
            ->pluck('count', 'signal');

        $change = $reports->priceChanges()->get($stock->id);

        return response()->json([
            'stock' => [
                'symbol' => $stock->symbol,
                'company_name' => $stock->company_name,
                'sector' => $stock->sector,
            ],
            'latest_price' => $stock->latestPrice,
            'latest_signal' => $stock->latestSignal,
            'change_pct' => $change['change_pct'] ?? null,
            'returns' => $reports->stockReturns($stock),
            'signal_counts_90d' => $signalCounts90d,
        ]);
    }

    public function dividends(Request $request, MarketReportService $reports)
    {
        return response()->json($reports->dividendReport($request->query('sector')));
    }

    public function longTerm(Request $request, MarketReportService $reports)
    {
        return response()->json([
            'candidates' => $reports->rankLongTermCandidates($request->query('sector')),
        ]);
    }

    public function midTerm(Request $request, MarketReportService $reports)
    {
        return response()->json([
            'candidates' => $reports->rankMidTermCandidates($request->query('sector')),
        ]);
    }

    public function shortTerm(Request $request, MarketReportService $reports)
    {
        return response()->json([
            'candidates' => $reports->rankShortTermCandidates($request->query('sector')),
        ]);
    }

    public function technical(string $symbol, TechnicalAnalysisReportService $reports)
    {
        $stock = Stock::where('symbol', strtoupper($symbol))->firstOrFail();

        return response()->json([
            'stock' => [
                'symbol' => $stock->symbol,
                'company_name' => $stock->company_name,
                'sector' => $stock->sector,
            ],
            'report' => $reports->build($stock),
        ]);
    }

    /**
     * The "Analyst Report" — one stock, every lens the app has on it (price
     * performance, technical read, dividend history, rule-based signal with
     * its own honest backtest context, ML direction call) assembled in one
     * response. No new computation happens here; it's a merge of what
     * TechnicalAnalysisReportService, MarketReportService,
     * MlDirectionPredictorService and the signal tables already produce
     * elsewhere, so nothing here can drift from those other pages.
     */
    public function analyst(string $symbol, MarketReportService $reports, TechnicalAnalysisReportService $technical, MlDirectionPredictorService $predictor)
    {
        $stock = Stock::with(['latestPrice', 'latestSignal'])->where('symbol', strtoupper($symbol))->firstOrFail();

        $change = $reports->priceChanges()->get($stock->id);

        $signalAccuracy = $stock->latestSignal
            ? SignalAccuracyStat::where('signal_type', $stock->latestSignal->signal)
                ->where('computed_at', SignalAccuracyStat::max('computed_at'))
                ->first()
            : null;

        $mlModel = $predictor->latestMetrics();
        try {
            $mlPrediction = $mlModel ? $predictor->predict($stock) : null;
        } catch (Throwable $e) {
            // The saved model file and MlFeatureBuilder's feature set can briefly
            // disagree right after a feature-set change and before the next
            // retrain finishes — degrade to "no prediction" rather than
            // failing the whole report over one section.
            $mlPrediction = null;
        }

        return response()->json([
            'stock' => [
                'symbol' => $stock->symbol,
                'company_name' => $stock->company_name,
                'sector' => $stock->sector,
            ],
            'latest_price' => $stock->latestPrice,
            'change_pct' => $change['change_pct'] ?? null,
            'returns' => $reports->stockReturns($stock),
            'technical' => $technical->build($stock),
            'dividend' => $reports->stockDividendSummary($stock),
            'signal' => $stock->latestSignal ? [
                'signal' => $stock->latestSignal->signal,
                'score' => (float) $stock->latestSignal->score,
                'reasons' => $stock->latestSignal->reasons,
                'trade_date' => $stock->latestSignal->trade_date,
                'accuracy' => $signalAccuracy ? [
                    'sample_size' => $signalAccuracy->sample_size,
                    'win_rate' => (float) $signalAccuracy->win_rate,
                    'baseline_win_rate' => (float) $signalAccuracy->baseline_win_rate,
                    'horizon_days' => $signalAccuracy->horizon_days,
                ] : null,
            ] : null,
            'ml_prediction' => $mlPrediction ? [
                'direction' => $mlPrediction['direction'],
                'probability' => $mlPrediction['probability'],
                'as_of_date' => $mlPrediction['as_of_date'],
                'horizon_days' => $mlModel->horizon_days,
                'model_accuracy' => (float) $mlModel->accuracy,
                'model_baseline_accuracy' => (float) $mlModel->baseline_accuracy,
                'beats_baseline' => $mlModel->beatsBaseline(),
            ] : null,
        ]);
    }

    public function rules()
    {
        $rules = collect(SignalRules::RULES)->map(fn ($r, $key) => [
            'key' => $key,
            'label' => $r['label'],
            'direction' => $r['direction'],
        ])->values();

        return response()->json($rules);
    }

    /**
     * Filters every stock's latest signal down to the ones that fired at
     * least one (mode=any) or all (mode=all) of the requested rule keys.
     * Reads already-stored rule_keys — nothing is recomputed live.
     */
    public function ruleScan(Request $request, MarketReportService $reports)
    {
        $validated = $request->validate([
            'rules' => ['required', 'array', 'min:1'],
            'rules.*' => ['string'],
            'mode' => ['nullable', 'in:any,all'],
        ]);

        $requested = array_values(array_unique($validated['rules']));
        $invalid = array_filter($requested, fn ($key) => ! SignalRules::isValidKey($key));

        if ($invalid !== []) {
            return response()->json(['message' => 'Unknown rule key(s): '.implode(', ', $invalid)], 422);
        }

        $mode = $validated['mode'] ?? 'any';

        $stocks = Stock::with(['latestSignal', 'latestPrice'])->get();
        $changes = $reports->priceChanges();

        $matches = $stocks->filter(function ($stock) use ($requested, $mode) {
            $fired = $stock->latestSignal?->rule_keys ?? [];

            return $mode === 'all'
                ? count(array_diff($requested, $fired)) === 0
                : count(array_intersect($requested, $fired)) > 0;
        })->map(function ($stock) use ($changes, $requested) {
            $fired = $stock->latestSignal?->rule_keys ?? [];
            $matchedKeys = array_values(array_intersect($requested, $fired));

            return [
                'stock_id' => $stock->id,
                'symbol' => $stock->symbol,
                'company_name' => $stock->company_name,
                'sector' => $stock->sector,
                'close' => $stock->latestPrice?->close_price,
                'change_pct' => $changes->get($stock->id)['change_pct'] ?? null,
                'signal' => $stock->latestSignal?->signal,
                'trade_date' => $stock->latestSignal?->trade_date,
                'matched_rules' => array_map(fn ($key) => ['key' => $key, 'label' => SignalRules::label($key)], $matchedKeys),
            ];
        })->sortByDesc(fn ($row) => count($row['matched_rules']))->values();

        return response()->json([
            'mode' => $mode,
            'requested_rules' => $requested,
            'matched_count' => $matches->count(),
            'stocks' => $matches,
        ]);
    }
}
