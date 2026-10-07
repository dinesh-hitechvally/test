<?php

namespace App\Services\DataSources\NepalStock;

use App\Events\ScrapeFinished;
use App\Models\IndexSnapshot;
use App\Services\DataSources\ShareSansar\SharesansarLiveMarketService;
use App\Services\DataSources\SourceFailover;
use RuntimeException;

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
        private readonly SharesansarLiveMarketService $sharesansar,
        private readonly SourceFailover $failover,
    ) {}

    /**
     * nepalstock.com first; when it can't be reached the failure is logged and ShareSansar's market page is used
     * instead (see SourceFailover). Same market-open rule either way.
     *
     * @return array{indices_updated: int, market_open: bool, source: string, failed_sources: array<string, string>}
     */
    public function sync(): array
    {
        [$result, $source, $failed] = $this->failover->run('index sync', [
            self::SOURCE_NAME => fn () => $this->viaNepse(),
            'sharesansar.com/market' => fn () => $this->viaSharesansar(),
        ]);

        ScrapeFinished::dispatch(
            source: $source,
            succeeded: true,
            recordsProcessed: $result['indices_updated'],
            message: ($result['market_open'] ? "{$result['indices_updated']} index snapshot(s) updated." : 'Market is closed today — nothing synced.').SourceFailover::note($failed),
        );

        return [...$result, 'source' => $source, 'failed_sources' => $failed];
    }

    /** @return array{indices_updated: int, market_open: bool} */
    private function viaNepse(): array
    {
        // Same market-open guard the price sync used to have — the index
        // value NEPSE returns while closed is often just yesterday's
        // stale close repeated, not a real "today" snapshot worth storing.
        if (! $this->marketStatus->isOpen()) {
            return ['indices_updated' => 0, 'market_open' => false];
        }

        $response = $this->client->get(self::INDEX_PATH);
        $response->throw();

        $snapshots = [];

        foreach ($response->json() ?? [] as $row) {
            $name = $row['index'] ?? null;

            if ($name === null || $row['close'] === null) {
                continue;
            }

            $snapshots[] = [
                'index_name' => $name,
                'trade_date' => now()->toDateString(),
                'close' => $row['close'],
                'high' => $row['high'] ?? null,
                'low' => $row['low'] ?? null,
                'previous_close' => $row['previousClose'] ?? null,
                'change' => $row['change'] ?? null,
                'change_pct' => $row['perChange'] ?? null,
                'fifty_two_week_high' => $row['fiftyTwoWeekHigh'] ?? null,
                'fifty_two_week_low' => $row['fiftyTwoWeekLow'] ?? null,
            ];
        }

        return ['indices_updated' => $this->save($snapshots), 'market_open' => true];
    }

    /** @return array{indices_updated: int, market_open: bool} */
    private function viaSharesansar(): array
    {
        $market = $this->sharesansar->snapshot();

        if (! $market['open']) {
            return ['indices_updated' => 0, 'market_open' => false];
        }

        $snapshots = array_map(fn (array $i) => [
            'index_name' => $i['index'],
            // The market's own date, not the server's — ShareSansar prints it, NEPSE's endpoint does not.
            'trade_date' => $market['date'],
            'close' => $i['close'],
            'high' => $i['high'],
            'low' => $i['low'],
            'previous_close' => $i['previous_close'],
            'change' => $i['change'],
            'change_pct' => $i['change_pct'],
            'fifty_two_week_high' => null, // not on ShareSansar's page
            'fifty_two_week_low' => null,
        ], $this->sharesansar->indices());

        return ['indices_updated' => $this->save($snapshots), 'market_open' => true];
    }

    /** @param  list<array<string, mixed>>  $snapshots */
    private function save(array $snapshots): int
    {
        if ($snapshots === []) {
            throw new RuntimeException('No index rows returned — the source may have changed.');
        }

        $snapshots = array_map(fn ($s) => [...$s, 'created_at' => now(), 'updated_at' => now()], $snapshots);

        IndexSnapshot::upsert(
            $snapshots,
            uniqueBy: ['index_name', 'trade_date'],
            // 52-week figures are left alone when the source (ShareSansar) does not supply them.
            update: array_values(array_filter(
                ['close', 'high', 'low', 'previous_close', 'change', 'change_pct', 'fifty_two_week_high', 'fifty_two_week_low', 'updated_at'],
                fn ($c) => ! in_array($c, ['fifty_two_week_high', 'fifty_two_week_low'], true) || $snapshots[0][$c] !== null
            ))
        );

        return count($snapshots);
    }
}
