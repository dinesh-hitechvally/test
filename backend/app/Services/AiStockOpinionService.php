<?php

namespace App\Services;

use App\Contracts\AiOpinionProvider;
use App\Models\SignalAccuracyStat;
use App\Models\Stock;
use App\Services\MarketData\MarketReportService;
use App\Services\MarketData\MlDirectionPredictorService;
use App\Services\MarketData\TechnicalAnalysisReportService;
use Throwable;

/**
 * Generates a buy/sell/hold opinion via the bound AiOpinionProvider, fed the
 * same technical/dividend/signal/ML context the Analyst Report page already
 * assembles (see ReportController::analyst()) — a 4th independent "lens"
 * alongside the technical read, rule-based signal, and ML predictor, not a
 * replacement for any of them.
 *
 * Generation only ever happens from the cron pipeline (GenerateAiOpinionsJob,
 * run by the batched /cron/scrape/ai-opinions endpoint) — never from a
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
        private readonly MarketReportService $reports,
        private readonly TechnicalAnalysisReportService $technical,
        private readonly MlDirectionPredictorService $predictor,
    ) {}

    public function isConfigured(): bool
    {
        return $this->provider->isConfigured();
    }

    /**
     * @return array{verdict: string, confidence: string, reasoning: string, generated_at: string, available: true}
     *         |array{available: false, message: string}
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
     * Same shape ReportController::analyst() assembles — kept independent
     * here (not a shared extraction) since the two call sites' error
     * handling and response shapes differ enough that sharing would mean a
     * method with two different failure modes to reason about.
     *
     * @return array<string, mixed>
     */
    private function buildContext(Stock $stock): array
    {
        $change = $this->reports->priceChanges()->get($stock->id);
        $signal = $stock->latestSignal;

        $signalAccuracy = $signal
            ? SignalAccuracyStat::where('signal_type', $signal->signal)
                ->where('computed_at', SignalAccuracyStat::max('computed_at'))
                ->first()
            : null;

        $mlModel = $this->predictor->latestMetrics();
        try {
            $mlPrediction = $mlModel ? $this->predictor->predict($stock) : null;
        } catch (Throwable) {
            $mlPrediction = null;
        }

        return [
            'symbol' => $stock->symbol,
            'company_name' => $stock->company_name,
            'sector' => $stock->sector?->name,
            'close_price' => $change['close'] ?? null,
            'change_pct_today' => $change['change_pct'] ?? null,
            'returns_pct' => $this->reports->stockReturns($stock),
            'technical' => $this->technical->build($stock),
            'dividend_history' => $this->reports->stockDividendSummary($stock),
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
