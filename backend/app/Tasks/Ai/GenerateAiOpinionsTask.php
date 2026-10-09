<?php

namespace App\Tasks\Ai;

use App\Models\AiStockOpinion;
use App\Models\Stock;
use App\Services\Ai\AiStockOpinionService;
use App\Tasks\PerStockTask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * The ONLY place that ever calls the AI — the stockAiOpinion GraphQL query
 * just reads whatever's stored, so a page load never waits on (or
 * costs) an external AI call.
 *
 * "Pending" = a stock with a signal (nothing else shows an AI opinion next
 * to) whose stored opinion is missing, older than a day (indicators/signals
 * only change once daily at market close, same cadence as the ML
 * predictor), or whose last attempt failed more than 6h ago — a fresh
 * failure is left alone rather than retried immediately.
 *
 * Handles ONE stock per run. Groq's free tier allows about 2 stocks a minute (a real prompt is ~3,000 tokens
 * against an 8,000-tokens/minute cap), so working through every pending stock in one request took far longer than
 * a web request may last and the cron URL timed out. Ping it every minute until it says nothing is pending.
 *
 * A 429 means "wait", not "this stock is broken": the run waits a short, capped time and retries once; if Groq is
 * still limiting, the stock is simply left pending for the next ping (no failure recorded) — see
 * generateWithinRateLimit().
 */
class GenerateAiOpinionsTask extends PerStockTask
{
    /** Longest one run waits for Groq's rate limit to clear (a web request must stay short); Groq's Retry-After is capped to this. */
    private const RATE_LIMIT_MAX_WAIT_SECONDS = 20;

    /** Fallback wait when Groq doesn't send a Retry-After header. */
    private const RATE_LIMIT_WAIT_SECONDS = 15;

    public function __construct(private readonly AiStockOpinionService $ai) {}

    protected function unavailableReason(): ?string
    {
        return $this->ai->isConfigured()
            ? null
            : 'AI opinion is not configured on this instance — no GROQ_API_KEY set.';
    }

    protected function pending(): Builder
    {
        $staleBefore = now()->subDay();
        $retryErrorsBefore = now()->subHours(6);

        return Stock::whereHas('latestSignal')->whereDoesntHave('aiOpinion', function ($q) use ($staleBefore, $retryErrorsBefore) {
            $q->where('generated_at', '>=', $staleBefore)
                ->orWhere('error_at', '>=', $retryErrorsBefore);
        });
    }

    /** One stock per ping: each takes seconds and Groq's free tier allows ~2 a minute, so a long run would time out. */
    protected function perRunLimit(): ?int
    {
        return 1;
    }

    protected function process(Stock $stock): string
    {
        $opinion = $this->generateWithinRateLimit($stock);

        if ($opinion === null) {
            // Still rate limited after the short wait: not a failure — the stock stays pending for the next ping.
            return "{$stock->symbol}: Groq's rate limit is still in effect — left pending, the next run tries it again.";
        }

        AiStockOpinion::updateOrCreate(
            ['stock_id' => $stock->id],
            [...$opinion, 'generated_at' => now(), 'error' => null, 'error_at' => null]
        );

        return "{$stock->symbol}: {$opinion['verdict']} ({$opinion['confidence']} confidence)";
    }

    /** Only reached for real failures — rate limits are waited out in generateWithinRateLimit(). */
    protected function failed(Stock $stock, Throwable $e): string
    {
        // Gets the 6h cooldown (only touches error/error_at — never
        // clobbers the last good opinion still worth showing).
        AiStockOpinion::updateOrCreate(
            ['stock_id' => $stock->id],
            ['error' => $e->getMessage(), 'error_at' => now()]
        );

        return parent::failed($stock, $e);
    }

    protected function nothingPendingMessage(): string
    {
        return 'No stocks are due for an AI opinion refresh.';
    }

    /**
     * Null when Groq is still rate limiting after one short wait.
     *
     * @return array{verdict: string, confidence: string, reasoning: string}|null
     */
    private function generateWithinRateLimit(Stock $stock): ?array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                return $this->ai->generate($stock);
            } catch (RequestException $e) {
                if ($e->response->status() !== 429) {
                    throw $e;
                }

                if ($attempt === 0) {
                    sleep(min(self::RATE_LIMIT_MAX_WAIT_SECONDS, max(1, (int) ($e->response->header('Retry-After') ?: self::RATE_LIMIT_WAIT_SECONDS))));
                }
            }
        }

        return null;
    }
}
