<?php

namespace App\Tasks\Ai;

use App\Models\AiStockOpinion;
use App\Models\Stock;
use App\Services\Ai\AiStockOpinionService;
use App\Tasks\PerStockTask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
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
 * A 429 (rate limit) or a Groq that does not answer in time means "not now", not "this stock is broken": the run
 * returns at once, the stock stays pending (no failure recorded, no 6-hour cooldown) and the next ping tries it
 * again. It never sleeps or retries inside the request — the cron URL has to finish well inside the host's
 * connection timeout — and Groq's own calls are capped at 25 seconds (see GroqOpinionProvider).
 */
class GenerateAiOpinionsTask extends PerStockTask
{
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
        $started = microtime(true);

        try {
            $opinion = $this->ai->generate($stock);
        } catch (RequestException $e) {
            // 429 = Groq's free-tier rate limit. Waiting here would hold the web request open, so the stock is simply
            // left pending and the next ping (a minute on) tries it again.
            if ($e->response->status() === 429) {
                return "{$stock->symbol}: Groq's rate limit is in effect — left pending, the next run tries it again.";
            }

            throw $e;
        } catch (ConnectionException $e) {
            // Groq did not answer in time (or could not be reached): nothing is wrong with the stock either.
            return "{$stock->symbol}: Groq did not answer in time — left pending, the next run tries it again.";
        }

        AiStockOpinion::updateOrCreate(
            ['stock_id' => $stock->id],
            [...$opinion, 'generated_at' => now(), 'error' => null, 'error_at' => null]
        );

        return sprintf('%s: %s (%s confidence) in %.1fs', $stock->symbol, $opinion['verdict'], $opinion['confidence'], microtime(true) - $started);
    }

    /** Only reached for real failures — rate limits and timeouts are left pending in process(). */
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
}
