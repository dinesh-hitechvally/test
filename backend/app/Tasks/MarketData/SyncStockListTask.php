<?php

namespace App\Tasks\MarketData;

use App\Events\ScrapeFinished;
use App\Services\DataSources\MeroLagani\MeroLaganiCompanyListService;
use App\Services\DataSources\NepalStock\NepalStockSecurityResolver;
use App\Services\DataSources\SourceFailover;
use App\Services\DataSources\StockListingWriter;
use App\Tasks\Task;

/**
 * Creates a stocks row for every listed security and fills in sector and instrument type. nepalstock.com is the
 * source; when it can't be reached (blocked, down) the failure is logged and MeroLagani's company list is used
 * instead (see SourceFailover). MeroLagani has no NEPSE security ids — those fill in later, when NEPSE is back.
 */
class SyncStockListTask extends Task
{
    public function __construct(
        private readonly NepalStockSecurityResolver $resolver,
        private readonly MeroLaganiCompanyListService $merolagani,
        private readonly StockListingWriter $writer,
        private readonly SourceFailover $failover,
    ) {}

    public function handle(): string
    {
        [$result, $source, $failed] = $this->failover->run('stock list', [
            'nepalstock.com/security-list' => fn () => $this->resolver->syncAllSecurities(),
            'merolagani.com/company-list' => function () {
                $list = $this->merolagani->fetch();

                return $this->writer->write($list['securities'], $list['companies']);
            },
        ]);

        $summary = "{$result['created']} new stock(s) created, {$result['existing']} already existed, {$result['sectors_filled']} given a sector ({$result['sectors_inferred']} of them promoter/preference shares, taken from their parent company), {$result['types_set']} had their instrument type set.";

        ScrapeFinished::dispatch(
            source: $source,
            succeeded: true,
            recordsProcessed: $result['total'],
            message: $summary.SourceFailover::note($failed),
        );

        return "{$result['total']} securities on record (from {$source}) — {$summary}".SourceFailover::note($failed);
    }
}
