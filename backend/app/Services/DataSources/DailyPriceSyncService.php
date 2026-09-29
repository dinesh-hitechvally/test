<?php

namespace App\Services\DataSources;

use App\Events\ScrapeFinished;
use App\Events\StockPricesUpdated;
use App\Services\DataSources\NepalStock\NepalStockLivePriceService;
use App\Services\DataSources\NepalStock\NepalStockMarketStatusService;
use App\Services\DataSources\ShareSansar\SharesansarDailyPriceService;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The market sync: keeps daily_prices right for NEPSE's latest trading date,
 * whether the market is open or closed.
 *
 *   open   → NEPSE's live feed (the session in progress).
 *   closed → ShareSansar's FINAL prices for that date — fills in stocks the
 *            live sync missed and corrects any row captured mid-session.
 *
 * Always saved under NEPSE's own trading date (never the server's "today"),
 * and only rows that are new or different are written, so running it again
 * and again is cheap and safe. Only stocks whose prices changed are
 * announced (StockPricesUpdated) — and so recalculated.
 */
class DailyPriceSyncService
{
    public function __construct(
        private readonly NepalStockMarketStatusService $marketStatus,
        private readonly NepalStockLivePriceService $livePrices,
        private readonly SharesansarDailyPriceService $finalPrices,
        private readonly DailyPriceWriter $writer,
    ) {}

    /**
     * @return array{market_open: bool, trade_date: string, source: string, fetched: int, inserted: int, updated: int, unchanged: int, skipped: int, created_stocks: int}
     */
    public function sync(): array
    {
        $source = 'nepalstock.com';

        try {
            $status = $this->marketStatus->current();
            $date = $status['last_open_date'];

            if ($date === '') {
                throw new RuntimeException('NEPSE reported no trading date — prices can\'t be saved without one.');
            }

            if ($status['open']) {
                $rows = $this->livePrices->currentPrices();

                if ($rows === []) {
                    throw new RuntimeException('NEPSE\'s live feed is empty while the market is open — the API may have changed.');
                }

                // NEPSE's symbols are authoritative, so a brand-new listing gets a stocks row.
                $result = $this->writer->write($date, $rows, createMissingStocks: true);
            } else {
                $source = 'sharesansar.com/today-share-price';
                $rows = $this->finalPrices->pricesFor($date);

                // Only stocks the app already knows — ShareSansar isn't the listing authority.
                $result = $this->writer->write($date, $rows, createMissingStocks: false);
            }

            ScrapeFinished::dispatch(
                source: $source,
                succeeded: true,
                recordsProcessed: count($rows),
                message: $this->summary($date, $status['open'], count($rows), $result),
            );
        } catch (Throwable $e) {
            Log::warning('Market sync failed', ['source' => $source, 'error' => $e->getMessage()]);

            ScrapeFinished::dispatch(source: $source, succeeded: false, recordsProcessed: 0, message: $e->getMessage());

            throw $e;
        }

        // Outside the try: the prices are saved, so a failure in a
        // (synchronous) listener — the recalculation — isn't a sync failure.
        if ($result['changed_stock_ids'] !== []) {
            StockPricesUpdated::dispatch($result['changed_stock_ids'], $source);
        }

        unset($result['changed_stock_ids']);

        return ['market_open' => $status['open'], 'trade_date' => $date, 'source' => $source, 'fetched' => count($rows), ...$result];
    }

    private function summary(string $date, bool $open, int $fetched, array $result): string
    {
        if ($fetched === 0) {
            return "{$date}: no prices available yet — nothing to update.";
        }

        return sprintf(
            '%s (%s): %d fetched — %d added, %d corrected, %d already up to date%s%s.',
            $date,
            $open ? 'live' : 'final',
            $fetched,
            $result['inserted'],
            $result['updated'],
            $result['unchanged'],
            $result['skipped'] ? ", {$result['skipped']} unknown symbol(s) skipped" : '',
            $result['created_stocks'] ? ", {$result['created_stocks']} new stock(s)" : '',
        );
    }
}
