<?php

namespace App\Services\Cron\Jobs;

use App\Models\Sector;
use App\Models\Stock;
use App\Services\Cron\StockBatchJob;
use App\Services\MarketData\NepalStockSecurityResolver;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/** Fills in stocks.sector_id for whichever stocks are still missing one — each saved as soon as it's fetched. */
class SyncSectorsJob extends StockBatchJob
{
    public function __construct(private readonly NepalStockSecurityResolver $resolver) {}

    public function pending(): Builder
    {
        return Stock::whereNull('sector_id');
    }

    public function pauseMicroseconds(): int
    {
        return 300_000; // polite pacing, same as the other per-stock NEPSE fetches
    }

    public function process(Stock $stock): string
    {
        $sector = $this->resolver->fetchSector($stock);

        if ($sector === null) {
            return "{$stock->symbol}: no sector returned";
        }

        $stock->update(['sector_id' => Sector::firstOrCreate(['name' => $sector])->id]);
        $stock->ensureScrapeStatus()->clearSectorError();

        return "{$stock->symbol}: {$sector}";
    }

    public function failed(Stock $stock, Throwable $e): string
    {
        $stock->ensureScrapeStatus()->flagSectorError($e->getMessage());

        return parent::failed($stock, $e);
    }

    public function emptyMessage(): string
    {
        return "No stocks are missing a sector.\n";
    }

    public function remainingMessage(int $remaining): string
    {
        return "{$remaining} stock(s) still missing a sector — re-ping this URL to continue.";
    }
}
