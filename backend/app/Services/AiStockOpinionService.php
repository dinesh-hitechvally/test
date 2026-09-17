<?php

namespace App\Services;

use App\Models\SignalAccuracyStat;
use App\Models\Stock;
use App\Services\MarketData\MarketReportService;
use App\Services\MarketData\MlDirectionPredictorService;
use App\Services\MarketData\TechnicalAnalysisReportService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Generates a buy/sell/hold opinion via Google Gemini's free-tier API,
 * fed the same technical/dividend/signal/ML context the Analyst Report
 * page already assembles (see ReportController::analyst()) — a 4th
 * independent "lens" alongside the technical read, rule-based signal, and
 * ML predictor, not a replacement for any of them. Degrades to an
 * "unavailable" result (never a 500 the user sees as a crash) whenever
 * GEMINI_API_KEY isn't set or the call fails — this is a bonus opinion,
 * not something the rest of the app depends on.
 */
class AiStockOpinionService
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public function __construct(
        private readonly MarketReportService $reports,
        private readonly TechnicalAnalysisReportService $technical,
        private readonly MlDirectionPredictorService $predictor,
    ) {}

    public function isConfigured(): bool
    {
        return filled(config('services.gemini.api_key'));
    }

    /**
     * @return array{verdict: string, confidence: string, reasoning: string, generated_at: string, available: true}
     *         |array{available: false, message: string}
     */
    public function opinion(Stock $stock): array
    {
        if (! $this->isConfigured()) {
            return ['available' => false, 'message' => 'AI opinion is not configured on this instance — no GEMINI_API_KEY set.'];
        }

        // Cached per stock per trade date (indicators/signals only change
        // once a day, at market close) so repeat visits/clicks for the same
        // stock on the same day don't re-spend the free-tier quota. Only a
        // successful opinion is cached — a failure (e.g. Gemini's free tier
        // returning a transient 503 under load) must never get locked in
        // for 12 hours; the user should be able to just click again.
        $cacheKey = "ai-opinion:{$stock->id}:".($stock->latestSignal?->trade_date?->toDateString() ?? 'no-signal');

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        try {
            $result = [...$this->generate($stock), 'available' => true];
            Cache::put($cacheKey, $result, now()->addHours(12));

            return $result;
        } catch (Throwable $e) {
            Log::warning('AI opinion generation failed', ['symbol' => $stock->symbol, 'error' => $e->getMessage()]);

            return ['available' => false, 'message' => 'Could not get an AI opinion right now — try again shortly.'];
        }
    }

    /**
     * @return array{verdict: string, confidence: string, reasoning: string, generated_at: string}
     */
    private function generate(Stock $stock): array
    {
        $prompt = $this->buildPrompt($stock, $this->buildContext($stock));
        $url = sprintf(self::ENDPOINT, config('services.gemini.model')).'?key='.config('services.gemini.api_key');

        $response = Http::timeout(40)
            // Gemini's free tier occasionally returns a transient 503 ("high
            // demand") — retried a couple of times with a short delay before
            // giving up, since that's usually gone within a second or two.
            ->retry(2, 1500, throw: false)
            ->post($url, [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                // This is a structured-output classification task, not
                // something needing multi-step reasoning — the model's
                // default "thinking" mode roughly doubled latency (~15s vs
                // ~6s for a trivial prompt) for no real benefit here.
                'thinkingConfig' => ['thinkingBudget' => 0],
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'verdict' => ['type' => 'STRING', 'enum' => ['buy', 'hold', 'sell']],
                        'confidence' => ['type' => 'STRING', 'enum' => ['low', 'medium', 'high']],
                        'reasoning' => ['type' => 'STRING'],
                    ],
                    'required' => ['verdict', 'confidence', 'reasoning'],
                ],
            ],
        ]);

        $response->throw();

        $text = $response->json('candidates.0.content.parts.0.text');
        $parsed = json_decode((string) $text, true);

        if (! is_array($parsed) || ! isset($parsed['verdict'], $parsed['confidence'], $parsed['reasoning'])) {
            throw new RuntimeException('Gemini returned an unexpected response shape.');
        }

        return [
            'verdict' => $parsed['verdict'],
            'confidence' => $parsed['confidence'],
            'reasoning' => $parsed['reasoning'],
            'generated_at' => now()->toIso8601String(),
        ];
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
            'sector' => $stock->sector,
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
