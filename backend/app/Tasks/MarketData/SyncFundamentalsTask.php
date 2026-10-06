<?php

namespace App\Tasks\MarketData;

use App\Models\Stock;
use App\Models\StockFundamental;
use App\Services\DataSources\MeroLagani\MeroLaganiFundamentalsService;
use App\Tasks\PerStockTask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * EPS/P/E/book value only move quarterly (or drift slowly with price), so
 * a week-old row is still fine to show — this just keeps every stock from
 * going more than ~7 days stale.
 *
 * ONE stock per run (each is a page download from merolagani.com plus a pause), so a ping stays short
 * instead of walking every stock in one long request. Ping again for the next; the response says how many
 * are still due.
 *
 * Debentures, preference shares and mutual funds are skipped: their pages have no meaningful EPS or P/E (the
 * figures come back as zeros). The type comes from the stock list sync (instrument_type), so stocks it has not
 * typed yet are still fetched. /cron/fetch/fundamentals/{symbol} can still fetch any one of them on request.
 *
 * Which stock is next: never fetched first, then the longest-stale. A stock whose fetch failed is left out
 * for a few hours, so one bad page can't be picked again and again and hold up the rest; it is retried after
 * that. (The failures are remembered in the cache — there is no failure column for fundamentals.)
 */
class SyncFundamentalsTask extends PerStockTask
{
    /** Stocks handled per run. */
    private const PER_RUN = 1;

    /** Cache key holding [stock_id => unix time of its last failure]. */
    private const FAILED_CACHE_KEY = 'fundamentals:failed';

    private const RETRY_AFTER_HOURS = 6;

    /** instrument_type values (NEPSE's own names) whose fundamentals are not worth fetching. */
    private const SKIPPED_TYPES = ['Non-Convertible Debentures', 'Preference Shares', 'Mutual Funds'];

    public function __construct(private readonly MeroLaganiFundamentalsService $fundamentals) {}

    protected function perRunLimit(): ?int
    {
        return self::PER_RUN;
    }

    protected function pending(): Builder
    {
        $staleBefore = now()->subDays(7);

        return Stock::whereDoesntHave('fundamental', function ($q) use ($staleBefore) {
            $q->where('fetched_at', '>=', $staleBefore);
        })
            ->whereNotIn('id', $this->recentlyFailedIds())
            // Untyped stocks stay in (NULL NOT IN (...) would drop them), the skipped kinds go.
            ->where(fn ($q) => $q->whereNull('instrument_type')->orWhereNotIn('instrument_type', self::SKIPPED_TYPES))
            // Never-fetched stocks (no row, so null) first, then the stalest.
            ->orderBy(StockFundamental::select('fetched_at')->whereColumn('stock_id', 'stocks.id')->limit(1));
    }

    protected function pauseMicroseconds(): int
    {
        return 500_000;
    }

    protected function process(Stock $stock): string
    {
        $data = $this->fundamentals->syncOne($stock);

        return "{$stock->symbol}: EPS={$data->eps} PE={$data->pe_ratio} BookValue={$data->book_value}";
    }

    protected function failed(Stock $stock, Throwable $e): string
    {
        $failures = $this->recentFailures();
        $failures[$stock->id] = now()->timestamp;
        Cache::put(self::FAILED_CACHE_KEY, $failures, now()->addDay());

        return parent::failed($stock, $e);
    }

    protected function nothingPendingMessage(): string
    {
        return 'No stocks are due for a fundamentals refresh.';
    }

    /** @return array<int, int> stock_id => unix time of the failure, only those still inside the hold-off window */
    private function recentFailures(): array
    {
        $cutoff = now()->subHours(self::RETRY_AFTER_HOURS)->timestamp;

        return array_filter(Cache::get(self::FAILED_CACHE_KEY, []), fn ($at) => $at >= $cutoff);
    }

    /** @return list<int> */
    private function recentlyFailedIds(): array
    {
        return array_map('intval', array_keys($this->recentFailures()));
    }
}
