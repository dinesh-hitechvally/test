<?php

namespace App\Services\MarketData;

use App\Models\DailyPrice;
use App\Models\ScrapeLog;
use App\Models\Stock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Fetches one stock's price history from the official nepalstock.com API.
 * NOT the app's primary history source — SharesansarHistoryService::fetchFullHistory()
 * is, precisely because this one empirically only returns roughly the
 * trailing ~1 year no matter how far back it's asked (confirmed against the
 * live site), which isn't enough for a real price chart on an established
 * stock. Kept as a secondary, official cross-check option (same-day data is
 * more precise — a real open price is included), reachable via
 * StockController::fetchNepseHistory() but not wired to any button.
 */
class NepalStockHistoryService
{
    private const BASE_URL = 'https://www.nepalstock.com';

    private const HISTORY_PATH = '/api/nots/market/history/security/%d';

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome Safari';

    private const SOURCE_NAME = 'nepalstock.com/history';

    private const PAGE_SIZE = 1000;

    public function __construct(
        private readonly NepalStockTokenService $tokens,
        private readonly NepalStockSecurityResolver $resolver,
        private readonly RecalculationPipeline $pipeline,
    ) {}

    /**
     * @return array{rows_imported: int, oldest_date: ?string, newest_date: ?string}
     */
    public function fetchHistory(Stock $stock): array
    {
        try {
            $securityId = $this->resolver->resolve($stock);
            $rows = $this->paginateHistory($securityId);

            if ($rows === []) {
                throw new RuntimeException("No historical rows returned for [{$stock->symbol}] — nepalstock.com may not have data for this security.");
            }

            $result = $this->persist($stock, $rows);

            // Marks the stock as "history fetched" the same way
            // SharesansarHistoryService::fetchFullHistory() does, so a manual
            // call through this service also stops stocks:queue-missing-history
            // from re-queueing it.
            $stock->update(['history_fetched_at' => now()]);

            ScrapeLog::create([
                'source' => self::SOURCE_NAME,
                'status' => 'success',
                'records_processed' => $result['rows_imported'],
                'message' => sprintf(
                    '%s: %d rows imported (%s to %s).',
                    $stock->symbol,
                    $result['rows_imported'],
                    $result['oldest_date'],
                    $result['newest_date']
                ),
            ]);

            $this->pipeline->runFor($stock->fresh());

            return $result;
        } catch (Throwable $e) {
            Log::warning('NEPSE official history fetch failed', ['symbol' => $stock->symbol, 'error' => $e->getMessage()]);

            ScrapeLog::create([
                'source' => self::SOURCE_NAME,
                'status' => 'failed',
                'records_processed' => 0,
                'message' => $this->truncatedMessage($stock->symbol, $e),
            ]);

            throw $e;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function paginateHistory(int $securityId): array
    {
        $rows = [];
        $page = 0;
        $totalPages = 1;
        $startDate = '2000-01-01'; // as far back as the API accepts; it caps depth server-side regardless
        $endDate = Carbon::today()->toDateString();

        do {
            $token = $this->tokens->getAccessToken(); // refetch each page — the token is only valid ~45s

            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Referer' => self::BASE_URL.'/',
                'Authorization' => 'Salter '.$token,
            ])->timeout(20)->get(self::BASE_URL.sprintf(self::HISTORY_PATH, $securityId), [
                'size' => self::PAGE_SIZE,
                'page' => $page,
                'startDate' => $startDate,
                'endDate' => $endDate,
            ]);

            $response->throw();
            $body = $response->json();

            $totalPages = (int) ($body['totalPages'] ?? 1);
            $pageRows = $body['content'] ?? [];

            if ($pageRows === []) {
                break;
            }

            array_push($rows, ...$pageRows);
            $page++;
        } while ($page < $totalPages);

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{rows_imported: int, oldest_date: ?string, newest_date: ?string}
     */
    private function persist(Stock $stock, array $rows): array
    {
        $priceRows = [];
        $dates = [];

        foreach ($rows as $row) {
            $date = $row['businessDate'] ?? null;

            if (! $date) {
                continue;
            }

            $dates[] = $date;
            $close = $this->number($row['closePrice'] ?? null);

            $priceRows[] = [
                'stock_id' => $stock->id,
                'trade_date' => $date,
                // No open price in this source — fall back to close for brand-new
                // rows only; excluded from the upsert's update list below so it
                // never overwrites a real open price from a ShareSansar import.
                'open_price' => $close,
                'high_price' => $this->number($row['highPrice'] ?? null),
                'low_price' => $this->number($row['lowPrice'] ?? null),
                'close_price' => $close,
                'volume' => (int) $this->number($row['totalTradedQuantity'] ?? null),
                'turnover' => $this->number($row['totalTradedValue'] ?? null),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($priceRows, 500) as $chunk) {
            DailyPrice::upsert(
                $chunk,
                uniqueBy: ['stock_id', 'trade_date'],
                update: ['high_price', 'low_price', 'close_price', 'volume', 'turnover', 'updated_at']
            );
        }

        sort($dates);

        return [
            'rows_imported' => count($priceRows),
            'oldest_date' => $dates[0] ?? null,
            'newest_date' => $dates[count($dates) - 1] ?? null,
        ];
    }

    private function number(mixed $raw): float
    {
        if ($raw === null) {
            return 0.0;
        }

        $clean = trim(str_replace([',', ' '], '', (string) $raw));

        return $clean === '' ? 0.0 : (float) $clean;
    }

    /**
     * A DB error's message can itself echo back a giant multi-row SQL
     * statement — capped here so logging a failure can never fail a second
     * time and mask the original error.
     */
    private function truncatedMessage(string $symbol, Throwable $e): string
    {
        $message = "{$symbol}: {$e->getMessage()}";

        return mb_strlen($message) > 2000 ? mb_substr($message, 0, 2000).'… (truncated)' : $message;
    }
}
