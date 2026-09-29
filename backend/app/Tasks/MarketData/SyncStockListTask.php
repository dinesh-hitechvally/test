<?php

namespace App\Tasks\MarketData;

use App\Events\ScrapeFinished;
use App\Services\DataSources\NepalStock\NepalStockSecurityResolver;
use App\Tasks\Task;
use Throwable;

class SyncStockListTask extends Task
{
    private const SOURCE = 'nepalstock.com/security-list';

    public function __construct(private readonly NepalStockSecurityResolver $resolver) {}

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
