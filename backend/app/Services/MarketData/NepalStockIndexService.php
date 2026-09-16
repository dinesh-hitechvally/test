<?php

namespace App\Services\MarketData;

use App\Models\IndexSnapshot;
use App\Models\ScrapeLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * NEPSE Index and sub-indices (Sensitive, Float, Sensitive Float) from the
 * official nepalstock.com API — confirmed live at /api/nots/nepse-index.
 * No historical endpoint was found for indices (only for individual
 * securities), so history here only starts accumulating from whenever this
 * is first run — there's no way to backfill it.
 */
class NepalStockIndexService
{
    private const BASE_URL = 'https://www.nepalstock.com';

    private const INDEX_PATH = '/api/nots/nepse-index';

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome Safari';

    private const SOURCE_NAME = 'nepalstock.com/index';

    public function __construct(
        private readonly NepalStockTokenService $tokens,
        private readonly NepalStockMarketStatusService $marketStatus,
    ) {}

    /**
     * @return array{indices_updated: int, market_open: bool}
     */
    public function sync(): array
    {
        try {
            // Same guard as NepalStockScraperService::scrape() — the index
            // value NEPSE returns while closed is often just yesterday's
            // stale close repeated, not a real "today" snapshot worth storing.
            if (! $this->marketStatus->isOpen()) {
                ScrapeLog::create([
                    'source' => self::SOURCE_NAME,
                    'status' => 'success',
                    'records_processed' => 0,
                    'message' => 'Market is closed today — nothing synced.',
                ]);

                return ['indices_updated' => 0, 'market_open' => false];
            }

            $token = $this->tokens->getAccessToken();

            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Referer' => self::BASE_URL.'/',
                'Authorization' => 'Salter '.$token,
            ])->timeout(20)->get(self::BASE_URL.self::INDEX_PATH);

            $response->throw();
            $rows = $response->json();

            $today = now()->toDateString();
            $snapshots = [];

            foreach ($rows as $row) {
                $name = $row['index'] ?? null;

                if ($name === null || $row['close'] === null) {
                    continue;
                }

                $snapshots[] = [
                    'index_name' => $name,
                    'trade_date' => $today,
                    'close' => $row['close'],
                    'high' => $row['high'] ?? null,
                    'low' => $row['low'] ?? null,
                    'previous_close' => $row['previousClose'] ?? null,
                    'change' => $row['change'] ?? null,
                    'change_pct' => $row['perChange'] ?? null,
                    'fifty_two_week_high' => $row['fiftyTwoWeekHigh'] ?? null,
                    'fifty_two_week_low' => $row['fiftyTwoWeekLow'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($snapshots === []) {
                throw new \RuntimeException('No index rows returned — the source API may have changed.');
            }

            IndexSnapshot::upsert(
                $snapshots,
                uniqueBy: ['index_name', 'trade_date'],
                update: ['close', 'high', 'low', 'previous_close', 'change', 'change_pct', 'fifty_two_week_high', 'fifty_two_week_low', 'updated_at']
            );

            ScrapeLog::create([
                'source' => self::SOURCE_NAME,
                'status' => 'success',
                'records_processed' => count($snapshots),
                'message' => count($snapshots).' index snapshot(s) updated.',
            ]);

            return ['indices_updated' => count($snapshots), 'market_open' => true];
        } catch (Throwable $e) {
            Log::warning('NEPSE index sync failed', ['error' => $e->getMessage()]);

            ScrapeLog::create([
                'source' => self::SOURCE_NAME,
                'status' => 'failed',
                'records_processed' => 0,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
