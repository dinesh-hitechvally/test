<?php

namespace App\Tasks\MarketData;

use App\Services\DataSources\MeroLagani\MeroLaganiFundamentalsService;
use App\Tasks\SingleStockTask;

/**
 * The one-stock counterpart to SyncFundamentalsTask: fetches one stock's EPS, P/E, book value and the rest
 * from merolagani.com (/cron/fetch/fundamentals/NABIL), whether or not its last fetch is still fresh —
 * the way to refresh a stock right after it publishes results.
 */
class FetchStockFundamentalsTask extends SingleStockTask
{
    public function __construct(private readonly MeroLaganiFundamentalsService $fundamentals) {}

    public function handle(): string
    {
        $stock = $this->stock();
        $data = $this->fundamentals->syncOne($stock);

        return "{$stock->symbol}: EPS={$data->eps} PE={$data->pe_ratio} BookValue={$data->book_value}";
    }
}
