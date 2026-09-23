<?php

namespace App\Console\Commands;

use App\Services\MarketData\SignalAccuracyService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

// Default is ~1.5 months of trading days (NEPSE trades 5 days/week), not the
// old 10-day (~2 week) horizon — a 10-day window is too short to judge
// whether a buy/sell/hold call actually played out; see SignalAccuracyService.
#[Signature('signals:backtest-accuracy {--horizon=30}')]
#[Description('Backtest the rule-based signal engine\'s historical win rate over a forward horizon')]
class BacktestSignalAccuracy extends Command
{
    public function handle(SignalAccuracyService $service): int
    {
        $horizon = (int) $this->option('horizon');
        $this->info("Backtesting signal accuracy over a {$horizon}-trading-day horizon...");

        $result = $service->backtest($horizon);

        $this->info("Done — {$result['signal_types_computed']} signal type(s), baseline win rate {$result['baseline_win_rate']}%.");

        return self::SUCCESS;
    }
}
