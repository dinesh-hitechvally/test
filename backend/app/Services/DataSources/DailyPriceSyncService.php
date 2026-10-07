<?php

namespace App\Services\DataSources;

use App\Events\ScrapeFinished;
use App\Events\StockPricesUpdated;
use App\Services\DataSources\MeroLagani\MeroLaganiLiveMarketService;
use App\Services\DataSources\NepalStock\NepalStockLivePriceService;
use App\Services\DataSources\NepalStock\NepalStockMarketStatusService;
use App\Services\DataSources\ShareSansar\SharesansarDailyPriceService;
use App\Services\DataSources\ShareSansar\SharesansarLiveMarketService;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The market sync: keeps daily_prices right for NEPSE's latest trading date,
 * whether the market is open or closed.
 *
 *   open   → the live feed (the session in progress).
 *   closed → ShareSansar's FINAL prices for that date — fills in stocks the
 *            live sync missed and corrects any row captured mid-session.
 *
 * Which website supplies the live feed and the market status is tried in order, and the next one is used when
 * one fails (blocked by a network filter, down, layout changed — each failure is logged, see SourceFailover):
 *
 *   1. nepalstock.com                     the official feed; its symbols are authoritative (new listings are added)
 *   2. sharesansar.com/live-trading       status, date and live prices; turnover estimated
 *   3. merolagani.com                     live prices; status judged from the clock; prices approximate
 *
 * Always saved under the market's own trading date (never the server's "today"),
 * and only rows that are new or different are written, so running it again
 * and again is cheap and safe. Only stocks whose prices changed are
 * announced (StockPricesUpdated); indicators are generated separately by the generate/indicators cron.
 */
class DailyPriceSyncService
{
    public function __construct(
        private readonly NepalStockMarketStatusService $marketStatus,
        private readonly NepalStockLivePriceService $livePrices,
        private readonly SharesansarDailyPriceService $finalPrices,
        private readonly SharesansarLiveMarketService $sharesansarLive,
        private readonly MeroLaganiLiveMarketService $merolaganiLive,
        private readonly DailyPriceWriter $writer,
        private readonly SourceFailover $failover,
    ) {}

    /**
     * @return array{market_open: bool, trade_date: string, source: string, fetched: int, inserted: int, updated: int, unchanged: int, skipped: int, created_stocks: int, failed_sources: array<string, string>}
     */
    public function sync(): array
    {
        [$market, , $failed] = $this->failover->run('market sync', [
            'nepalstock.com' => fn () => $this->viaNepse(),
            'sharesansar.com/live-trading' => fn () => $this->viaSharesansar(),
            'merolagani.com' => fn () => $this->viaMerolagani(),
        ]);

        $source = $market['source'];
        $date = $market['date'];
        $rows = $market['rows'];

        try {
            $result = $this->writer->write($date, $rows, createMissingStocks: $market['authoritative']);

            ScrapeFinished::dispatch(
                source: $source,
                succeeded: true,
                recordsProcessed: count($rows),
                message: $this->summary($date, $market['open'], count($rows), $result).SourceFailover::note($failed),
            );
        } catch (Throwable $e) {
            Log::warning('Market sync failed', ['source' => $source, 'error' => $e->getMessage()]);

            ScrapeFinished::dispatch(source: $source, succeeded: false, recordsProcessed: 0, message: $e->getMessage());

            throw $e;
        }

        // Outside the try: the prices are saved, so a failure in a
        // (synchronous) listener isn't a sync failure.
        if ($result['changed_stock_ids'] !== []) {
            StockPricesUpdated::dispatch($result['changed_stock_ids'], $source);
        }

        unset($result['changed_stock_ids']);

        return ['market_open' => $market['open'], 'trade_date' => $date, 'source' => $source, 'fetched' => count($rows), ...$result, 'failed_sources' => $failed];
    }

    /** @return array{open: bool, date: string, rows: array, source: string, authoritative: bool} */
    private function viaNepse(): array
    {
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
            return ['open' => true, 'date' => $date, 'rows' => $rows, 'source' => 'nepalstock.com', 'authoritative' => true];
        }

        return $this->closed($date);
    }

    private function viaSharesansar(): array
    {
        $snapshot = $this->sharesansarLive->snapshot();

        if ($snapshot['open']) {
            if ($snapshot['rows'] === []) {
                throw new RuntimeException('ShareSansar\'s live-trading table is empty while the market is open — its layout may have changed.');
            }

            return ['open' => true, 'date' => $snapshot['date'], 'rows' => $snapshot['rows'], 'source' => 'sharesansar.com/live-trading', 'authoritative' => false];
        }

        return $this->closed($snapshot['date']);
    }

    private function viaMerolagani(): array
    {
        $snapshot = $this->merolaganiLive->snapshot();

        if ($snapshot['rows'] === []) {
            throw new RuntimeException('MeroLagani\'s live-trading table is empty — its layout may have changed.');
        }

        // Open or closed, this page's last prices are all this source has; they stand in for the final prices too.
        return ['open' => $snapshot['open'], 'date' => $snapshot['date'], 'rows' => $snapshot['rows'], 'source' => 'merolagani.com', 'authoritative' => false];
    }

    /** Market closed: ShareSansar's final prices for that date. Only stocks the app already knows — ShareSansar isn't the listing authority. */
    private function closed(string $date): array
    {
        return ['open' => false, 'date' => $date, 'rows' => $this->finalPrices->pricesFor($date), 'source' => 'sharesansar.com/today-share-price', 'authoritative' => false];
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
