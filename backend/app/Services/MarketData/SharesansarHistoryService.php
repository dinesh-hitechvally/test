<?php

namespace App\Services\MarketData;

use App\Models\DailyPrice;
use App\Models\ScrapeLog;
use App\Models\Stock;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Fetches a single stock's ENTIRE historical price record from ShareSansar's
 * per-company "Price History" tab (a DataTables server-side endpoint), back
 * to its listing date. User-triggered, one stock at a time — not a bulk
 * crawler — because it issues dozens of paginated requests per stock.
 *
 * This app's daily sync and dividend/right-share/sector data all come from
 * the official nepalstock.com API (see the NepalStock* services) — this is
 * the one deliberate exception, kept specifically for full price history,
 * because NEPSE's own history endpoint only returns roughly the trailing
 * ~1 year no matter how far back it's asked, which isn't enough for a real
 * price chart on an established stock. No Node/browser dependency either
 * way — this is plain HTTP + HTML parsing, same as it always was.
 */
class SharesansarHistoryService
{
    private const COMPANY_PAGE = 'https://www.sharesansar.com/company/%s';

    private const HISTORY_ENDPOINT = 'https://www.sharesansar.com/company-price-history';

    /** Server rejects any length outside this set — matches the site's own UI page-size options. */
    private const PAGE_LENGTH = 50;

    private const SOURCE_NAME = 'sharesansar.com/history';

    public function __construct(private readonly RecalculationPipeline $pipeline) {}

    /**
     * @return array{rows_imported: int, oldest_date: ?string, newest_date: ?string}
     */
    public function fetchFullHistory(Stock $stock): array
    {
        set_time_limit(0); // dozens of paginated requests; a stock with years of history can take a minute or more

        try {
            $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome Safari';
            $slug = strtolower($stock->symbol);

            $pageResponse = Http::withHeaders(['User-Agent' => $userAgent])
                ->timeout(20)
                ->get(sprintf(self::COMPANY_PAGE, $slug));

            if ($pageResponse->status() === 404) {
                throw new RuntimeException("No ShareSansar page found for symbol [{$stock->symbol}].");
            }

            $pageResponse->throw();
            $html = $pageResponse->body();

            $token = $this->extractToken($html);
            $companyId = $this->extractCompanyId($html);
            $cookies = $pageResponse->cookies();

            $rows = $this->paginateHistory($userAgent, $slug, $token, $companyId, $cookies);

            if ($rows === []) {
                throw new RuntimeException('No historical rows returned — the source page layout may have changed.');
            }

            $result = $this->persist($stock, $rows);
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
            Log::warning('ShareSansar full-history fetch failed', ['symbol' => $stock->symbol, 'error' => $e->getMessage()]);

            ScrapeLog::create([
                'source' => self::SOURCE_NAME,
                'status' => 'failed',
                'records_processed' => 0,
                'message' => $this->truncatedMessage($stock->symbol, $e),
            ]);

            throw $e;
        }
    }

    private function extractToken(string $html): string
    {
        if (! preg_match('/name="_token"\s+content="([^"]+)"/', $html, $m)) {
            throw new RuntimeException('Could not find a CSRF token on the source page — its layout may have changed.');
        }

        return $m[1];
    }

    private function extractCompanyId(string $html): string
    {
        if (! preg_match('/id="companyid"[^>]*>(\d+)</', $html, $m)) {
            throw new RuntimeException('Could not find the internal company id on the source page — its layout may have changed.');
        }

        return $m[1];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function paginateHistory(string $userAgent, string $slug, string $token, string $companyId, $cookies): array
    {
        $rows = [];
        $start = 0;
        $recordsTotal = null;
        $draw = 1;
        $referer = sprintf(self::COMPANY_PAGE, $slug);

        do {
            $response = Http::withHeaders([
                'User-Agent' => $userAgent,
                'X-CSRF-Token' => $token,
                'X-Requested-With' => 'XMLHttpRequest',
                'Referer' => $referer,
                'Cookie' => $this->cookieHeader($cookies),
            ])->asForm()->timeout(15)->post(self::HISTORY_ENDPOINT, [
                'company' => $companyId,
                'draw' => $draw,
                'start' => $start,
                'length' => self::PAGE_LENGTH,
            ]);

            $response->throw();
            $page = $response->json();

            $recordsTotal ??= (int) ($page['recordsTotal'] ?? 0);
            $pageRows = $page['data'] ?? [];

            if ($pageRows === []) {
                break;
            }

            array_push($rows, ...$pageRows);
            $start += self::PAGE_LENGTH;
            $draw++;

            usleep(300_000); // be a polite, slow client — this is a manual per-stock action, not a crawler
        } while ($start < $recordsTotal);

        return $rows;
    }

    private function cookieHeader($cookies): string
    {
        $parts = [];
        foreach ($cookies as $cookie) {
            $parts[] = $cookie->getName().'='.$cookie->getValue();
        }

        return implode('; ', $parts);
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
            $date = $row['published_date'] ?? null;

            if (! $date) {
                continue;
            }

            $dates[] = $date;

            $priceRows[] = [
                'stock_id' => $stock->id,
                'trade_date' => $date,
                'open_price' => $this->number($row['open'] ?? null),
                'high_price' => $this->number($row['high'] ?? null),
                'low_price' => $this->number($row['low'] ?? null),
                'close_price' => $this->number($row['close'] ?? null),
                'volume' => (int) $this->number($row['traded_quantity'] ?? null),
                'turnover' => $this->number($row['traded_amount'] ?? null),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($priceRows, 500) as $chunk) {
            DailyPrice::upsert(
                $chunk,
                uniqueBy: ['stock_id', 'trade_date'],
                update: ['open_price', 'high_price', 'low_price', 'close_price', 'volume', 'turnover', 'updated_at']
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
