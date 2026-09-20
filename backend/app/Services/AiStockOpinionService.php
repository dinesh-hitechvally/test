<?php

namespace App\Services;

use App\Models\SignalAccuracyStat;
use App\Models\Stock;
use App\Services\MarketData\MarketReportService;
use App\Services\MarketData\MlDirectionPredictorService;
use App\Services\MarketData\TechnicalAnalysisReportService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Generates a buy/sell/hold opinion via Groq's free-tier API, fed the same
 * technical/dividend/signal/ML context the Analyst Report page already
 * assembles (see ReportController::analyst()) — a 4th independent "lens"
 * alongside the technical read, rule-based signal, and ML predictor, not a
 * replacement for any of them.
 *
 * Generation only ever happens from the cron pipeline (CronController's
 * batched generate-ai-opinions endpoint) — never from a user-facing
 * request — and is persisted to ai_stock_opinions. Reading an opinion
 * (getStoredOpinion()) is a plain DB lookup with no external call, so it's
 * safe to show on every page load with no button/latency/quota concern.
 *
 * Third provider this app has used for this feature (Gemini, then Claude,
 * now Groq — see git history) — Groq hosts open-weight models (Llama,
 * etc.) behind an OpenAI-compatible chat-completions API, with a genuinely
 * free tier (no billing setup) but real per-minute rate limits.
 */
class AiStockOpinionService
{
    private const ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';

    public function __construct(
        private readonly MarketReportService $reports,
        private readonly TechnicalAnalysisReportService $technical,
        private readonly MlDirectionPredictorService $predictor,
    ) {}

    public function isConfigured(): bool
    {
        return filled(config('services.groq.api_key'));
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
     * Calls Groq and returns the parsed opinion — throws on any failure.
     * Callers (the cron endpoint) are responsible for persisting the
     * result; this method never touches the database itself.
     *
     * Uses forced tool-calling (OpenAI-compatible function-calling, not
     * just "ask for JSON in the prompt") for reliable structured output —
     * same reasoning Gemini's responseSchema / Claude's tool_choice served
     * here with the earlier providers.
     *
     * @return array{verdict: string, confidence: string, reasoning: string}
     */
    public function generate(Stock $stock): array
    {
        $prompt = $this->buildPrompt($stock, $this->buildContext($stock));

        $response = Http::timeout(40)
            ->withToken(config('services.groq.api_key'))
            // Transient overload/rate-limit responses are retried a
            // couple of times with a short delay before giving up.
            ->retry(2, 1500, throw: false)
            ->post(self::ENDPOINT, [
                'model' => config('services.groq.model'),
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'tools' => [[
                    'type' => 'function',
                    'function' => [
                        'name' => 'record_opinion',
                        'description' => 'Record the buy/hold/sell opinion for this stock.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'verdict' => ['type' => 'string', 'enum' => ['buy', 'hold', 'sell']],
                                'confidence' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                                'reasoning' => ['type' => 'string'],
                            ],
                            'required' => ['verdict', 'confidence', 'reasoning'],
                        ],
                    ],
                ]],
                'tool_choice' => ['type' => 'function', 'function' => ['name' => 'record_opinion']],
            ]);

        $response->throw();

        // OpenAI-compatible shape: the tool call's arguments come back as a
        // JSON *string*, not a nested object — needs its own decode.
        $arguments = $response->json('choices.0.message.tool_calls.0.function.arguments');
        $parsed = json_decode((string) $arguments, true);

        if (! is_array($parsed) || ! isset($parsed['verdict'], $parsed['confidence'], $parsed['reasoning'])) {
            throw new RuntimeException('Groq returned an unexpected response shape.');
        }

        return [
            'verdict' => $parsed['verdict'],
            'confidence' => $parsed['confidence'],
            'reasoning' => $parsed['reasoning'],
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
