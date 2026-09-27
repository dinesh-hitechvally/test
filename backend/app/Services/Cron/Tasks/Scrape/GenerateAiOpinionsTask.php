<?php

namespace App\Services\Cron\Tasks\Scrape;

use App\Services\Cron\PerStockTask;

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
 *
 * Runs every pending stock in one go, so it has to live within Groq's
 * free-tier rate limit rather than skip past it: a real prompt is ~3,000
 * tokens against an 8,000-tokens/minute cap, so only ~2 stocks fit per
 * minute. A 429 means "wait", not "this stock is broken" — see
 * generateWithinRateLimit(). Expect a full run to take roughly
 * (pending stocks ÷ 2) minutes.
 */
class GenerateAiOpinionsTask extends PerStockTask
{
    /** How many times one stock waits out a 429 before it's recorded as failed. */
    private const RATE_LIMIT_RETRIES = 5;

    /** Fallback wait when Groq doesn't send a Retry-After header. */
    private const RATE_LIMIT_WAIT_SECONDS = 30;

    public function __construct(private readonly AiStockOpinionService $ai) {}

    public function name(): string
    {
        return 'ai-opinions';
    }

    public function description(): string
    {
        return 'Generate a buy/hold/sell AI opinion (Groq) for every stock whose opinion is missing or a day+ old';
    }

    public function logFile(): string
    {
        return 'ai-opinions.log';
    }

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

    protected function pauseMicroseconds(): int
    {
        return 500_000;
    }

    protected function process(Stock $stock): string
    {
        $opinion = $this->generateWithinRateLimit($stock);

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

    /** @return array{verdict: string, confidence: string, reasoning: string} */
    private function generateWithinRateLimit(Stock $stock): array
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->ai->generate($stock);
            } catch (RequestException $e) {
                if ($e->response->status() !== 429 || $attempt >= self::RATE_LIMIT_RETRIES) {
                    throw $e;
                }

                sleep(max(1, (int) ($e->response->header('Retry-After') ?: self::RATE_LIMIT_WAIT_SECONDS)));
            }
        }
    }
}
