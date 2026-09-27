<?php

namespace App\Services\Cron\Tasks\Scrape;

use App\Services\Cron\CronTask;

use App\Events\ScrapeFinished;
use App\Services\DataSources\NepalStock\NepalStockSecurityResolver;
use Throwable;

class SyncStockListTask extends CronTask
{
    private const SOURCE = 'nepalstock.com/security-list';

    public function __construct(private readonly NepalStockSecurityResolver $resolver) {}

    public function name(): string
    {
        return 'stocks:sync-list';
    }

    public function description(): string
    {
        return 'Create a stocks row for every security on the official nepalstock.com list, not just ones that traded today';
    }

    public function logFile(): string
    {
        return 'stocks-sync-list.log';
    }

    public function handle(): string
    {
        try {
            $result = $this->resolver->syncAllSecurities();
        } catch (Throwable $e) {
            ScrapeFinished::dispatch(
                source: self::SOURCE,
                succeeded: false,
                recordsProcessed: 0,
                message: $e->getMessage(),
            );

            throw $e;
        }

        $summary = "{$result['created']} new stock(s) created, {$result['existing']} already existed.";

        ScrapeFinished::dispatch(
            source: self::SOURCE,
            succeeded: true,
            recordsProcessed: $result['total'],
            message: $summary,
        );

        return "{$result['total']} securities on record — {$summary}";
    }
}
