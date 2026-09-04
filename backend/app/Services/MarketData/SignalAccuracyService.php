<?php

namespace App\Services\MarketData;

use App\Models\SignalAccuracyStat;
use App\Models\Stock;

/**
 * Honest backtest of the rule-based signal engine: for every historical
 * buy/sell signal ever generated, look forward N trading days and check
 * whether price actually moved the direction the signal implied. Same
 * walk-forward, no-peeking spirit as the ML predictor and Holt forecast
 * backtests — a signal's "accuracy" here is measured against what actually
 * happened next, not fitted after the fact.
 */
class SignalAccuracyService
{
    private const DIRECTIONAL_SIGNALS = ['strong_buy', 'buy', 'sell', 'strong_sell'];

    public function backtest(int $horizonDays = 10): array
    {
        $stocks = Stock::whereHas('signals')->get(['id']);

        // wins/samples per signal type, plus an overall baseline: of every
        // trading day regardless of signal, how often did price rise over
        // the same horizon — the "would a coin flip have done this well" line.
        $bySignal = [];
        foreach (self::DIRECTIONAL_SIGNALS as $type) {
            $bySignal[$type] = ['wins' => 0, 'samples' => 0, 'returnSum' => 0.0];
        }
        $baselineUp = 0;
        $baselineTotal = 0;

        foreach ($stocks as $stock) {
            $prices = $stock->dailyPrices()->orderBy('trade_date')->get(['trade_date', 'close_price']);

            if ($prices->count() < $horizonDays + 5) {
                continue;
            }

            $closes = $prices->pluck('close_price')->map(fn ($v) => (float) $v)->all();
            $dateIndex = [];
            foreach ($prices as $i => $p) {
                $dateIndex[$p->trade_date->toDateString()] = $i;
            }

            $n = count($closes);
            for ($i = 0; $i < $n - $horizonDays; $i++) {
                // A handful of thinly-traded securities have a zero/bad close
                // on some days — skip rather than divide by zero.
                if ($closes[$i] <= 0) {
                    continue;
                }

                $forwardReturn = (($closes[$i + $horizonDays] - $closes[$i]) / $closes[$i]) * 100;
                $baselineTotal++;
                if ($forwardReturn > 0) {
                    $baselineUp++;
                }
            }

            $signals = $stock->signals()
                ->whereIn('signal', self::DIRECTIONAL_SIGNALS)
                ->orderBy('trade_date')
                ->get(['trade_date', 'signal']);

            foreach ($signals as $signal) {
                $date = $signal->trade_date->toDateString();
                $i = $dateIndex[$date] ?? null;

                if ($i === null || $i + $horizonDays >= $n || $closes[$i] <= 0) {
                    continue;
                }

                $forwardReturn = (($closes[$i + $horizonDays] - $closes[$i]) / $closes[$i]) * 100;
                $isBullishCall = in_array($signal->signal, ['strong_buy', 'buy'], true);
                $won = $isBullishCall ? $forwardReturn > 0 : $forwardReturn < 0;

                $bySignal[$signal->signal]['samples']++;
                $bySignal[$signal->signal]['returnSum'] += $forwardReturn;
                if ($won) {
                    $bySignal[$signal->signal]['wins']++;
                }
            }
        }

        $baselineWinRate = $baselineTotal > 0 ? round(($baselineUp / $baselineTotal) * 100, 2) : null;
        $computedAt = now();
        $rows = [];

        foreach ($bySignal as $type => $stat) {
            if ($stat['samples'] === 0) {
                continue;
            }

            $isBullish = in_array($type, ['strong_buy', 'buy'], true);

            $rows[] = [
                'signal_type' => $type,
                'horizon_days' => $horizonDays,
                'sample_size' => $stat['samples'],
                'win_rate' => round(($stat['wins'] / $stat['samples']) * 100, 2),
                'avg_forward_return_pct' => round($stat['returnSum'] / $stat['samples'], 4),
                // A sell signal "wins" when price falls, so its fair baseline
                // is the inverse of the overall up-rate, not the up-rate itself.
                'baseline_win_rate' => $baselineWinRate === null ? null : ($isBullish ? $baselineWinRate : round(100 - $baselineWinRate, 2)),
                'computed_at' => $computedAt,
                'created_at' => $computedAt,
                'updated_at' => $computedAt,
            ];
        }

        if ($rows !== []) {
            SignalAccuracyStat::insert($rows);
        }

        return ['horizon_days' => $horizonDays, 'signal_types_computed' => count($rows), 'baseline_win_rate' => $baselineWinRate];
    }
}
