<?php

namespace App\Console\Commands;

use App\Services\MarketData\NextCloseEstimatorService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('signals:backtest-next-close')]
#[Description('Backtest the next-close price estimator against every real historical (day, next-day) pair')]
class BacktestNextClose extends Command
{
    public function handle(NextCloseEstimatorService $estimator): int
    {
        set_time_limit(0);

        $this->info('Backtesting the next-close estimator against real historical outcomes...');

        $stat = $estimator->backtest();

        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Stocks used', $stat->stocks_used],
                ['Day-pairs sampled', number_format($stat->sample_size)],
                ['Estimator MAPE', round((float) $stat->mape, 3).'%'],
                ['Naive ("no change") MAPE', round((float) $stat->naive_mape, 3).'%'],
                ['Direction accuracy', round((float) $stat->direction_accuracy, 2).'%'],
            ]
        );

        if ($stat->beatsBaseline()) {
            $this->info('Beats the naive "assume no change" baseline.');
        } else {
            $this->warn('Does NOT beat the naive "assume no change" baseline — shown to users anyway, honestly labeled, not as a reliable prediction.');
        }

        return self::SUCCESS;
    }
}
