<?php

namespace App\Services\Reports;

use App\Models\Stock;
use App\Services\Analysis\Signals\SignalAccuracyService;
use App\Services\MachineLearning\MlDirectionPredictorService;

/** One stock's report pages: summary, technical analysis, and the all-lenses Analyst Report. */
class StockReportService
{
    public function __construct(
        private readonly PriceStatisticsService $prices,
        private readonly DividendReportService $dividends,
        private readonly TechnicalAnalysisReportService $technical,
        private readonly MlDirectionPredictorService $predictor,
        private readonly SignalAccuracyService $accuracy,
    ) {}

    /** Expects sector, latestPrice and latestSignal loaded. */
    public function summary(Stock $stock): array
    {
        $signalCounts90d = $stock->signals()
            ->where('trade_date', '>=', now()->subDays(90))
            ->selectRaw('`signal`, COUNT(*) as count') // `signal` is a reserved word in MySQL (stored-procedure SIGNAL statement)
            ->groupBy('signal')
            ->pluck('count', 'signal');

        $change = $this->prices->priceChanges()->get($stock->id);

        return [
            'stock' => $this->identity($stock),
            'latest_price' => $stock->latestPrice,
            'latest_signal' => $stock->latestSignal,
            'change_pct' => $change['change_pct'] ?? null,
            'returns' => $this->prices->stockReturns($stock),
            'signal_counts_90d' => $signalCounts90d,
        ];
    }

    public function technical(Stock $stock): array
    {
        return [
            'stock' => $this->identity($stock),
            'report' => $this->technical->build($stock),
        ];
    }

    /**
     * The "Analyst Report" — every lens the app has on one stock (price
     * performance, technical read, dividend history, rule-based signal with
     * its own backtest context, ML direction call) in one response. No new
     * computation happens here; it merges what the other services already
     * produce, so nothing here can drift from the other pages.
     *
     * Expects sector, latestPrice and latestSignal loaded.
     */
    public function analyst(Stock $stock): array
    {
        $change = $this->prices->priceChanges()->get($stock->id);
        $signal = $stock->latestSignal;

        $signalAccuracy = $signal ? $this->accuracy->latestFor($signal->signal) : null;

        $mlModel = $this->predictor->latestMetrics();
        $mlPrediction = $mlModel ? $this->predictor->tryPredict($stock) : null;

        return [
            'stock' => $this->identity($stock),
            'latest_price' => $stock->latestPrice,
            'change_pct' => $change['change_pct'] ?? null,
            'returns' => $this->prices->stockReturns($stock),
            'technical' => $this->technical->build($stock),
            'dividend' => $this->dividends->stockDividendSummary($stock),
            'signal' => $signal ? [
                'signal' => $signal->signal,
                'score' => (float) $signal->score,
                'reasons' => $signal->reasons,
                'trade_date' => $signal->trade_date,
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
        ];
    }

    private function identity(Stock $stock): array
    {
        return [
            'symbol' => $stock->symbol,
            'company_name' => $stock->company_name,
            'sector' => $stock->sector?->name,
        ];
    }
}
