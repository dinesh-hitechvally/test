<?php

namespace App\Services\Cron\BatchJobs;

use App\Models\AiStockOpinion;
use App\Models\Stock;
use App\Services\Ai\AiStockOpinionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * The ONLY place that ever calls the AI — StockController's ai-opinion
 * endpoint just reads whatever's stored, so a page load never waits on (or
 * costs) an external AI call.
 *
 * "Pending" = a stock with a signal (nothing else shows an AI opinion next
 * to) whose stored opinion is missing, older than a day (indicators/signals
 * only change once daily at market close, same cadence as the ML
 * predictor), or whose last attempt failed more than 6h ago — a fresh
 * failure is left alone rather than retried immediately.
 */
class GenerateAiOpinionsJob extends StockBatchJob
{
    public function __construct(private readonly AiStockOpinionService $ai) {}

    public function unavailableReason(): ?string
    {
        return $this->ai->isConfigured()
            ? null
            : "AI opinion is not configured on this instance — no GROQ_API_KEY set.\n";
    }

    public function pending(): Builder
    {
        $staleBefore = now()->subDay();
        $retryErrorsBefore = now()->subHours(6);

        return Stock::whereHas('latestSignal')->whereDoesntHave('aiOpinion', function ($q) use ($staleBefore, $retryErrorsBefore) {
            $q->where('generated_at', '>=', $staleBefore)
                ->orWhere('error_at', '>=', $retryErrorsBefore);
        });
    }

    public function defaultLimit(): int
    {
        return 1;
    }

    public function pauseMicroseconds(): int
    {
        return 500_000;
    }

    public function process(Stock $stock): string
    {
        $opinion = $this->ai->generate($stock);

        AiStockOpinion::updateOrCreate(
            ['stock_id' => $stock->id],
            [...$opinion, 'generated_at' => now(), 'error' => null, 'error_at' => null]
        );

        return "{$stock->symbol}: {$opinion['verdict']} ({$opinion['confidence']} confidence)";
    }

    public function failed(Stock $stock, Throwable $e): string
    {
        // A real stock-analysis prompt runs ~3,000 tokens against Groq's
        // free-tier 8,000-tokens/minute cap — only ~2 calls fit per minute,
        // so a 429 is an expected, self-clearing condition, not a sign this
        // stock is broken. Recording it with the normal 6h error cooldown
        // would leave it stuck long after the limit cleared, so it's left
        // untouched instead — still pending, picked up on the next ping.
        if ($e instanceof RequestException && $e->response->status() === 429) {
            return "{$stock->symbol}: rate limited — will retry on next ping.";
        }

        // Any other failure DOES get the 6h cooldown (only touches
        // error/error_at — never clobbers the last good opinion).
        AiStockOpinion::updateOrCreate(
            ['stock_id' => $stock->id],
            ['error' => $e->getMessage(), 'error_at' => now()]
        );

        return parent::failed($stock, $e);
    }

    public function emptyMessage(): string
    {
        return "No stocks are due for an AI opinion refresh.\n";
    }

    public function remainingMessage(int $remaining): string
    {
        return "{$remaining} stock(s) still due for an AI opinion — re-ping this URL to continue.";
    }
}
