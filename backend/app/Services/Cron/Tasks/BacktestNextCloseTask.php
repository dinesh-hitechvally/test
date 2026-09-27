<?php

namespace App\Services\Cron\Tasks;

use App\Services\Cron\CronTask;
use App\Services\MarketData\NextCloseEstimatorService;

class BacktestNextCloseTask extends CronTask
{
    public function __construct(private readonly NextCloseEstimatorService $estimator) {}

    public function name(): string
    {
        return 'signals:backtest-next-close';
    }

    public function description(): string
    {
        return 'Backtest the next-close price estimator against every real historical (day, next-day) pair';
    }

    public function logFile(): string
    {
        return 'next-close-accuracy.log';
    }

    public function handle(): string
    {
        $stat = $this->estimator->backtest();

        $verdict = $stat->beatsBaseline()
            ? 'Beats the naive "assume no change" baseline.'
            : 'Does NOT beat the naive "assume no change" baseline — shown to users anyway, honestly labeled, not as a reliable prediction.';

        return implode("\n", [
            "Stocks used: {$stat->stocks_used}",
            'Day-pairs sampled: '.number_format($stat->sample_size),
            'Estimator MAPE: '.round((float) $stat->mape, 3).'%',
            'Naive ("no change") MAPE: '.round((float) $stat->naive_mape, 3).'%',
            'Direction accuracy: '.round((float) $stat->direction_accuracy, 2).'%',
            $verdict,
        ]);
    }
}
