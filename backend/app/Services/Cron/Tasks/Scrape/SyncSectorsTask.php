<?php

namespace App\Services\Cron\Tasks\Scrape;

use App\Services\Cron\PerStockTask;

use App\Models\Sector;
use App\Models\Stock;
use App\Services\DataSources\NepalStock\NepalStockSecurityResolver;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/** Fills in stocks.sector_id for every stock still missing one. */
class SyncSectorsTask extends PerStockTask
{
    public function __construct(private readonly NepalStockSecurityResolver $resolver) {}

    public function name(): string
    {
        return 'sync-sectors';
    }

    public function description(): string
    {
        return 'Fetch each stock\'s sector from nepalstock.com, for stocks missing one';
    }

    public function logFile(): string
    {
        return 'sync-sectors.log';
    }

    protected function pending(): Builder
    {
        return Stock::whereNull('sector_id');
    }

    protected function pauseMicroseconds(): int
    {
        return 300_000; // polite pacing, same as the other per-stock NEPSE fetches
    }

    protected function process(Stock $stock): string
    {
        $sector = $this->resolver->fetchSector($stock);

        if ($sector === null) {
            return "{$stock->symbol}: no sector returned";
        }

        $stock->update(['sector_id' => Sector::firstOrCreate(['name' => $sector])->id]);
        $stock->ensureScrapeStatus()->clearSectorError();

        return "{$stock->symbol}: {$sector}";
    }

    protected function failed(Stock $stock, Throwable $e): string
    {
        $stock->ensureScrapeStatus()->flagSectorError($e->getMessage());

        return parent::failed($stock, $e);
    }

    protected function nothingPendingMessage(): string
    {
        return 'No stocks are missing a sector.';
    }
}
