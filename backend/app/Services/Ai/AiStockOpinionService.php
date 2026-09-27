<?php

namespace App\Services\Ai;

use App\Contracts\AiOpinionProvider;
use App\Models\Stock;
use App\Services\Analysis\Signals\SignalAccuracyService;
use App\Services\MachineLearning\MlDirectionPredictorService;
use App\Services\Reports\DividendReportService;
use App\Services\Reports\PriceStatisticsService;
use App\Services\Reports\TechnicalAnalysisReportService;

/**
 * Generates a buy/sell/hold opinion via the bound AiOpinionProvider, fed the
 * same technical/dividend/signal/ML context the Analyst Report page already
 * assembles (see ReportController::analyst()) — a 4th independent "lens"
 * alongside the technical read, rule-based signal, and ML predictor, not a
 * replacement for any of them.
 *
 * Generation only ever happens in a background task (GenerateAiOpinionsTask,
 * run by the /cron/scrape/ai-opinions endpoint) — never from a
 * user-facing request — and is persisted to ai_stock_opinions. Reading an
 * opinion (getStoredOpinion()) is a plain DB lookup with no external call,
 * so it's safe to show on every page load with no button/latency/quota
 * concern.
 *
 * Which LLM actually answers isn't this class's concern — that's
 * AiOpinionProvider (currently GroqOpinionProvider, bound in
 * AppServiceProvider). This class only owns what gets asked.
 */
class AiStockOpinionService
{
    public function __construct(
        private readonly AiOpinionProvider $provider,
        private readonly PriceStatisticsService $prices,
        private readonly DividendReportService $dividends,
        private readonly TechnicalAnalysisReportService $technical,
        private readonly MlDirectionPredictorService $predictor,
        private readonly SignalAccuracyService $accuracy,
    ) {}

    public function isConfigured(): bool
    {
        return $this->provider->isConfigured();
    }

    /**
     * @return array{verdict: string, confidence: string, reasoning: string, generated_at: string, available: true}
     *                                                                                                              |array{available: false, message: string}
     */
    public function getStoredOpinion(Stock $stock): array
    {
        $opinion = $stock->aiOpinion;

        if (! $opinion || $opinion->verdict === null) {
            return ['available' => false, 'message' => 'No AI opinion generated for this stock yet — it\'s picked up by the next scheduled run.'];
        }

        return [
            'available' => true,
            'verdict' => $opinion->verdict,
            'confidence' => $opinion->confidence,
            'reasoning' => $opinion->reasoning,
            'generated_at' => $opinion->generated_at->toIso8601String(),
        ];
    }

    /**
     * Asks the bound AiOpinionProvider and returns the parsed opinion —
     * throws on any failure. Callers (the cron job) are responsible for
     * persisting the result; this method never touches the database itself.
     *
     * @return array{verdict: string, confidence: string, reasoning: string}
     */
    public function generate(Stock $stock): array
    {
        return $this->provider->requestOpinion($this->buildPrompt($stock, $this->buildContext($stock)));
    }

    /**
     * The same lenses StockReportService::analyst() assembles, in the
     * compact shape the prompt wants (percentages, no display fields) — kept
     * separate because the two shapes serve different readers.
     *
     * @return array<string, mixed>
     */
    private function buildContext(Stock $stock): array
    {
        $change = $this->prices->priceChanges()->get($stock->id);
        $signal = $stock->latestSignal;

        $signalAccuracy = $signal ? $this->accuracy->latestFor($signal->signal) : null;

        $mlModel = $this->predictor->latestMetrics();
        $mlPrediction = $mlModel ? $this->predictor->tryPredict($stock) : null;

        return [
            'symbol' => $stock->symbol,
            'company_name' => $stock->company_name,
            'sector' => $stock->sector?->name,
            'close_price' => $change['close'] ?? null,
            'change_pct_today' => $change['change_pct'] ?? null,
            'returns_pct' => $this->prices->stockReturns($stock),
            'technical' => $this->technical->build($stock),
            'dividend_history' => $this->dividends->stockDividendSummary($stock),
            'rule_based_signal' => $signal ? [
                'signal' => $signal->signal,
                'reasons' => $signal->reasons,
                'backtested_accuracy' => $signalAccuracy ? [
                    'win_rate_pct' => (float) $signalAccuracy->win_rate,
                    'baseline_win_rate_pct' => (float) $signalAccuracy->baseline_win_rate,
                    'sample_size' => $signalAccuracy->sample_size,
                ] : null,
            ] : null,
            'ml_model_prediction' => $mlPrediction ? [
                'direction' => $mlPrediction['direction'],
                'probability' => $mlPrediction['probability'],
                'model_accuracy_pct' => round((float) $mlModel->accuracy * 100, 1),
                'beats_naive_baseline' => $mlModel->beatsBaseline(),
            ] : null,
        ];
    }

    private function buildPrompt(Stock $stock, array $context): string
    {
        $json = json_encode($context, JSON_PRETTY_PRINT);

        return 'You are a cautious equity analyst assistant for the Nepal Stock Exchange (NEPSE). '
            .'You are given real, already-computed data about one stock — technical indicators, dividend history, '
            .'a rule-based signal with its own backtested win rate, and a machine-learning direction prediction with '
            .'its own accuracy. This data does NOT include company fundamentals (no EPS, P/E, book value, or '
            .'earnings) — it is purely price/technical/dividend history.'."\n\n"
            .'Based ONLY on the data below, give your own independent buy/hold/sell opinion for '.$stock->symbol.'. '
            .'Be honest about uncertainty: if the rule signal and ML prediction disagree, or if accuracy figures are '
            .'weak or near a coin flip, say so explicitly and lean toward "hold" rather than manufacturing false '
            .'confidence. Keep the reasoning to 2-4 sentences, plain language, no markdown formatting.'."\n\n"
            ."Stock data (JSON):\n{$json}";
    }
}
