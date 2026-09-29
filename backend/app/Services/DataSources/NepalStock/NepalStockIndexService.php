<?php

namespace App\Services\DataSources\NepalStock;

use App\Events\ScrapeFinished;
use App\Models\IndexSnapshot;
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
    private const INDEX_PATH = '/api/nots/nepse-index';

    private const SOURCE_NAME = 'nepalstock.com/index';

    public function __construct(
        private readonly NepalStockClient $client,
        private readonly NepalStockMarketStatusService $marketStatus,
    ) {}

    /**
     * @return array{indices_updated: int, market_open: bool}
     */
    public function sync(): array
    {
        try {
            // Same market-open guard the price sync used to have — the index
            // value NEPSE returns while closed is often just yesterday's
            // stale close repeated, not a real "today" snapshot worth storing.
            if (! $this->marketStatus->isOpen()) {
                ScrapeFinished::dispatch(
                    source: self::SOURCE_NAME,
                    succeeded: true,
                    recordsProcessed: 0,
                    message: 'Market is closed today — nothing synced.',
                );

                return ['indices_updated' => 0, 'market_open' => false];
            }

            $response = $this->client->get(self::INDEX_PATH);

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

            ScrapeFinished::dispatch(
                source: self::SOURCE_NAME,
                succeeded: true,
                recordsProcessed: count($snapshots),
                message: count($snapshots).' index snapshot(s) updated.',
            );

            return ['indices_updated' => count($snapshots), 'market_open' => true];
        } catch (Throwable $e) {
            Log::warning('NEPSE index sync failed', ['error' => $e->getMessage()]);

            ScrapeFinished::dispatch(
                source: self::SOURCE_NAME,
                succeeded: false,
                recordsProcessed: 0,
                message: $e->getMessage(),
            );

            throw $e;
        }
    }
}
