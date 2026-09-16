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
 * Scrapes today's NEPSE snapshot from the official nepalstock.com API (via
 * NepalStockTokenService for auth) — this app's one and only daily price
 * source. One clean JSON call covers every security (open/high/low/last
 * traded/volume/turnover/previous close), no HTML parsing needed.
 */
class NepalStockScraperService
{
    private const BASE_URL = 'https://www.nepalstock.com';

    private const LIVE_MARKET_PATH = '/api/nots/lives-market';

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome Safari';

    private const SOURCE_NAME = 'nepalstock.com';

    public function __construct(
        private readonly NepalStockTokenService $tokens,
        private readonly NepalStockMarketStatusService $marketStatus,
    ) {}

    /**
     * @return array{created_stocks: int, updated_prices: int, affected_stock_ids: int[], market_open: bool}
     */
    public function scrape(): array
    {
        try {
            $token = $this->tokens->getAccessToken();

            // Skipping (rather than writing whatever the live-market endpoint
            // happens to return while closed — often stale or empty) means a
            // stray ping on a non-trading day can never leave today's
            // daily_prices wrong.
            if (! $this->marketStatus->isOpen()) {
                ScrapeLog::create([
                    'source' => self::SOURCE_NAME,
                    'status' => 'success',
                    'records_processed' => 0,
                    'message' => 'Market is closed today — nothing synced.',
                ]);

                return ['created_stocks' => 0, 'updated_prices' => 0, 'affected_stock_ids' => [], 'market_open' => false];
            }

            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Referer' => self::BASE_URL.'/',
                'Authorization' => 'Salter '.$token,
            ])->timeout(20)->get(self::BASE_URL.self::LIVE_MARKET_PATH);

            $response->throw();
            $rows = $response->json();

            if (! is_array($rows) || $rows === []) {
                throw new RuntimeException('No rows returned from nepalstock.com live-market — the API may have changed.');
            }

            $result = $this->persist($rows);

            ScrapeLog::create([
                'source' => self::SOURCE_NAME,
                'status' => 'success',
                'records_processed' => count($rows),
                'message' => sprintf(
                    '%d stocks created, %d price rows upserted.',
                    $result['created_stocks'],
                    $result['updated_prices']
                ),
            ]);

            return [...$result, 'market_open' => true];
        } catch (Throwable $e) {
            Log::warning('NEPSE official scrape failed', ['error' => $e->getMessage()]);

            ScrapeLog::create([
                'source' => self::SOURCE_NAME,
                'status' => 'failed',
                'records_processed' => 0,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{created_stocks: int, updated_prices: int, affected_stock_ids: int[]}
     */
    private function persist(array $rows): array
    {
        $today = Carbon::today()->toDateString();
        $createdStocks = 0;
        $affectedStockIds = [];
        $priceRows = [];

        foreach ($rows as $row) {
            $symbol = strtoupper((string) ($row['symbol'] ?? ''));

            if ($symbol === '') {
                continue;
            }

            $stock = Stock::firstOrNew(['symbol' => $symbol]);
            $isNew = ! $stock->exists;

            if ($isNew) {
                $stock->company_name = $row['securityName'] ?? null;
                $stock->is_active = true;
            }

            if (isset($row['securityId']) && $stock->nepse_security_id === null) {
                $stock->nepse_security_id = (int) $row['securityId'];
            }

            if ($isNew || $stock->isDirty()) {
                $stock->save();
            }

            if ($isNew) {
                $createdStocks++;
            }

            $affectedStockIds[] = $stock->id;

            $priceRows[] = [
                'stock_id' => $stock->id,
                'trade_date' => $today,
                'open_price' => $this->number($row['openPrice'] ?? null),
                'high_price' => $this->number($row['highPrice'] ?? null),
                'low_price' => $this->number($row['lowPrice'] ?? null),
                'close_price' => $this->number($row['lastTradedPrice'] ?? null),
                'volume' => (int) $this->number($row['totalTradeQuantity'] ?? null),
                'turnover' => $this->number($row['totalTradeValue'] ?? null),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DailyPrice::upsert(
            $priceRows,
            uniqueBy: ['stock_id', 'trade_date'],
            update: ['open_price', 'high_price', 'low_price', 'close_price', 'volume', 'turnover', 'updated_at']
        );

        return [
            'created_stocks' => $createdStocks,
            'updated_prices' => count($priceRows),
            'affected_stock_ids' => $affectedStockIds,
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
}
