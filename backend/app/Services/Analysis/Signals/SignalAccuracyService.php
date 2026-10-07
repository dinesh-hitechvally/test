<?php

namespace App\Services\Analysis\Signals;

use App\Models\SignalAccuracyStat;
use App\Models\Stock;
use Illuminate\Support\Facades\DB;

/**
 * Honest backtest of the rule-based signal engine: for every historical
 * buy/sell signal ever generated, look forward N trading days and check
 * whether price actually moved the direction the signal implied. Same
 * walk-forward, no-peeking spirit as the ML predictor's own backtest — a
 * signal's "accuracy" here is measured against what actually happened
 * next, not fitted after the fact.
 */
class SignalAccuracyService
{
    private const DIRECTIONAL_SIGNALS = ['buy', 'sell'];

    /**
     * BUY % (for buys) / SELL % (for sells) bands the confidence backtest groups days into. The bands below the
     * 50% decision line are on purpose: they show what happens just under the threshold, so you can see whether
     * 50 is the right place to draw it.
     */
    private const BANDS = ['30-40' => [30, 40], '40-50' => [40, 50], '50-60' => [50, 60], '60-70' => [60, 70], '70+' => [70, 101]];

    public function backtest(int $horizonDays = 30): array
    {
        $stocks = Stock::whereHas('signals')->get(['id']);

        // wins/samples per signal type, plus an overall baseline: of every
        // trading day regardless of signal, how often did price rise over
        // the same horizon — the "would a coin flip have done this well" line.
        $bySignal = [];
        foreach (self::DIRECTIONAL_SIGNALS as $type) {
            $bySignal[$type] = ['wins' => 0, 'samples' => 0, 'returnSum' => 0.0];
        }
        $byBand = [];
        foreach (['buy', 'sell'] as $side) {
            foreach (array_keys(self::BANDS) as $band) {
                $byBand[$side][$band] = ['wins' => 0, 'samples' => 0, 'returnSum' => 0.0];
            }
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
                $isBullishCall = $signal->signal === 'buy';
                $won = $isBullishCall ? $forwardReturn > 0 : $forwardReturn < 0;

                $bySignal[$signal->signal]['samples']++;
                $bySignal[$signal->signal]['returnSum'] += $forwardReturn;
                if ($won) {
                    $bySignal[$signal->signal]['wins']++;
                }
            }

            // The same forward-return test, grouped by how confident the day was (its BUY % / SELL %), whatever
            // the final decision turned out to be. Reads the stored percentages; no recomputation.
            $percentages = DB::table('signal_breakdowns')
                ->where('stock_id', $stock->id)
                ->get(['trade_date', 'buy_pct', 'sell_pct']);

            foreach ($percentages as $day) {
                $i = $dateIndex[substr((string) $day->trade_date, 0, 10)] ?? null;

                if ($i === null || $i + $horizonDays >= $n || $closes[$i] <= 0) {
                    continue;
                }

                $forwardReturn = (($closes[$i + $horizonDays] - $closes[$i]) / $closes[$i]) * 100;

                foreach (['buy' => (float) $day->buy_pct, 'sell' => (float) $day->sell_pct] as $side => $pct) {
                    $band = $this->bandFor($pct);

                    if ($band === null) {
                        continue;
                    }

                    $byBand[$side][$band]['samples']++;
                    $byBand[$side][$band]['returnSum'] += $forwardReturn;
                    if ($side === 'buy' ? $forwardReturn > 0 : $forwardReturn < 0) {
                        $byBand[$side][$band]['wins']++;
                    }
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

            $isBullish = $type === 'buy';

            $rows[] = [
                'signal_type' => $type,
                'confidence_band' => null,
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

        $signalTypes = count($rows);

        foreach ($byBand as $side => $bands) {
            foreach ($bands as $band => $stat) {
                if ($stat['samples'] === 0) {
                    continue;
                }

                $rows[] = [
                    'signal_type' => $side,
                    'confidence_band' => $band,
                    'horizon_days' => $horizonDays,
                    'sample_size' => $stat['samples'],
                    'win_rate' => round(($stat['wins'] / $stat['samples']) * 100, 2),
                    'avg_forward_return_pct' => round($stat['returnSum'] / $stat['samples'], 4),
                    'baseline_win_rate' => $baselineWinRate === null ? null : ($side === 'buy' ? $baselineWinRate : round(100 - $baselineWinRate, 2)),
                    'computed_at' => $computedAt,
                    'created_at' => $computedAt,
                    'updated_at' => $computedAt,
                ];
            }
        }

        if ($rows !== []) {
            SignalAccuracyStat::insert($rows);
        }

        return [
            'horizon_days' => $horizonDays,
            'signal_types_computed' => $signalTypes,
            'bands_computed' => count($rows) - $signalTypes,
            'baseline_win_rate' => $baselineWinRate,
        ];
    }

    private function bandFor(float $pct): ?string
    {
        foreach (self::BANDS as $band => [$low, $high]) {
            if ($pct >= $low && $pct < $high) {
                return $band;
            }
        }

        return null;
    }

    /** The latest backtest's stats for one signal type (e.g. "buy") — null before the first backtest. */
    public function latestFor(string $signalType): ?SignalAccuracyStat
    {
        return SignalAccuracyStat::where('signal_type', $signalType)
            ->whereNull('confidence_band')
            ->where('computed_at', SignalAccuracyStat::max('computed_at'))
            ->first();
    }

    /** The Signal History / Accuracy page: the most recent backtest run, per signal type. */
    public function latestReport(): array
    {
        $latestRun = SignalAccuracyStat::max('computed_at');

        if ($latestRun === null) {
            return ['available' => false];
        }

        $stats = SignalAccuracyStat::where('computed_at', $latestRun)->get();

        return [
            'available' => true,
            'computed_at' => $latestRun,
            'horizon_days' => $stats->first()?->horizon_days,
            'stats' => $stats,
            'disclaimer' => 'Walk-forward backtest over historical signals — real past performance of the rule-based signal engine, not a guarantee of future results.',
        ];
    }
}
