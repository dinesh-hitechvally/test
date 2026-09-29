<?php

namespace App\Tasks\Analysis;

use App\Services\Analysis\Signals\SignalAccuracyService;
use App\Tasks\Task;

class BacktestSignalsTask extends Task
{
    /** ~1.5 months of NEPSE trading days — see SignalAccuracyService. */
    private const HORIZON_DAYS = 30;

    public function __construct(private readonly SignalAccuracyService $accuracy) {}

    public function handle(): string
    {
        $result = $this->accuracy->backtest(self::HORIZON_DAYS);

        return "Backtested over a {$result['horizon_days']}-trading-day horizon — {$result['signal_types_computed']} signal type(s), baseline win rate {$result['baseline_win_rate']}%.";
    }
}
