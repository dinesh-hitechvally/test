<?php

namespace App\Services\Analysis\Indicators;

/**
 * Pure calculators for common technical indicators.
 *
 * Every method takes an ordered (oldest -> newest) array of floats and
 * returns an aligned array of the same length, with null wherever there
 * isn't yet enough history to compute a value.
 */
class TechnicalAnalysisService
{
    /**
     * @param  float[]  $values
     * @return array<int, float|null>
     */
    public function sma(array $values, int $period): array
    {
        $count = count($values);
        $result = array_fill(0, $count, null);

        for ($i = $period - 1; $i < $count; $i++) {
            $window = array_slice($values, $i - $period + 1, $period);
            $result[$i] = array_sum($window) / $period;
        }

        return $result;
    }

    /**
     * @param  float[]  $values
     * @return array<int, float|null>
     */
    public function ema(array $values, int $period): array
    {
        $count = count($values);
        $result = array_fill(0, $count, null);

        if ($count < $period) {
            return $result;
        }

        $multiplier = 2 / ($period + 1);
        $seed = array_sum(array_slice($values, 0, $period)) / $period;
        $result[$period - 1] = $seed;

        $previous = $seed;
        for ($i = $period; $i < $count; $i++) {
            $previous = (($values[$i] - $previous) * $multiplier) + $previous;
            $result[$i] = $previous;
        }

        return $result;
    }

    /**
     * @param  float[]  $closes
     * @return array<int, float|null>
     */
    public function rsi(array $closes, int $period = 14): array
    {
        $count = count($closes);
        $result = array_fill(0, $count, null);

        if ($count <= $period) {
            return $result;
        }

        $gains = [];
        $losses = [];
        for ($i = 1; $i < $count; $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            $gains[$i] = max($change, 0);
            $losses[$i] = max(-$change, 0);
        }

        $avgGain = array_sum(array_slice($gains, 1, $period, true)) / $period;
        $avgLoss = array_sum(array_slice($losses, 1, $period, true)) / $period;
        $result[$period] = $this->rsiFromAverages($avgGain, $avgLoss);

        for ($i = $period + 1; $i < $count; $i++) {
            $avgGain = (($avgGain * ($period - 1)) + $gains[$i]) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $losses[$i]) / $period;
            $result[$i] = $this->rsiFromAverages($avgGain, $avgLoss);
        }

        return $result;
    }

    private function rsiFromAverages(float $avgGain, float $avgLoss): float
    {
        if ($avgLoss == 0.0) {
            return 100.0;
        }

        $rs = $avgGain / $avgLoss;

        return 100 - (100 / (1 + $rs));
    }

    /**
     * @param  float[]  $closes
     * @return array{macd: array<int, float|null>, signal: array<int, float|null>, histogram: array<int, float|null>}
     */
    public function macd(array $closes, int $fast = 12, int $slow = 26, int $signalPeriod = 9): array
    {
        $count = count($closes);
        $emaFast = $this->ema($closes, $fast);
        $emaSlow = $this->ema($closes, $slow);

        $macdLine = array_fill(0, $count, null);
        for ($i = 0; $i < $count; $i++) {
            if ($emaFast[$i] !== null && $emaSlow[$i] !== null) {
                $macdLine[$i] = $emaFast[$i] - $emaSlow[$i];
            }
        }

        $firstValidIndex = $slow - 1;
        $macdValuesOnly = array_values(array_filter(
            array_slice($macdLine, $firstValidIndex),
            fn ($v) => $v !== null
        ));
        $signalOnly = $this->ema($macdValuesOnly, $signalPeriod);

        $signalLine = array_fill(0, $count, null);
        $histogram = array_fill(0, $count, null);
        $j = 0;
        for ($i = $firstValidIndex; $i < $count; $i++) {
            $signalLine[$i] = $signalOnly[$j] ?? null;
            if ($signalLine[$i] !== null) {
                $histogram[$i] = $macdLine[$i] - $signalLine[$i];
            }
            $j++;
        }

        return ['macd' => $macdLine, 'signal' => $signalLine, 'histogram' => $histogram];
    }

    /**
     * @param  float[]  $closes
     * @return array{upper: array<int, float|null>, middle: array<int, float|null>, lower: array<int, float|null>}
     */
    public function bollingerBands(array $closes, int $period = 20, float $stdDevMultiplier = 2.0): array
    {
        $count = count($closes);
        $middle = $this->sma($closes, $period);
        $upper = array_fill(0, $count, null);
        $lower = array_fill(0, $count, null);

        for ($i = $period - 1; $i < $count; $i++) {
            $window = array_slice($closes, $i - $period + 1, $period);
            $mean = $middle[$i];
            $variance = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $window)) / $period;
            $stdDev = sqrt($variance);
            $upper[$i] = $mean + ($stdDevMultiplier * $stdDev);
            $lower[$i] = $mean - ($stdDevMultiplier * $stdDev);
        }

        return ['upper' => $upper, 'middle' => $middle, 'lower' => $lower];
    }

    /**
     * Bollinger %B — where the close sits within its bands: 0 = on the
     * lower band, 1 = on the upper band, below 0 / above 1 = outside them.
     * Null while the bands aren't available yet, or when they've collapsed
     * to zero width (a perfectly flat window has no meaningful position).
     *
     * @param  float[]  $closes
     * @param  array{upper: array<int, float|null>, lower: array<int, float|null>}  $bands  output of bollingerBands()
     * @return array<int, float|null>
     */
    public function percentB(array $closes, array $bands): array
    {
        $result = array_fill(0, count($closes), null);

        foreach ($closes as $i => $close) {
            $upper = $bands['upper'][$i] ?? null;
            $lower = $bands['lower'][$i] ?? null;

            if ($upper !== null && $lower !== null && $upper > $lower) {
                $result[$i] = ($close - $lower) / ($upper - $lower);
            }
        }

        return $result;
    }

    /**
     * Slow stochastic oscillator: raw %K = where the close sits in the
     * trailing $period-day high/low range (0-100), smoothed by an SMA of
     * $kSmoothing to get %K, and %D = an SMA of $dPeriod over that %K.
     * The standard (14, 3, 3) is the default. A zero-width range (high ==
     * low for the whole window) reads as a neutral 50 rather than a
     * divide-by-zero.
     *
     * @param  float[]  $highs
     * @param  float[]  $lows
     * @param  float[]  $closes
     * @return array{k: array<int, float|null>, d: array<int, float|null>}
     */
    public function stochastic(array $highs, array $lows, array $closes, int $period = 14, int $kSmoothing = 3, int $dPeriod = 3): array
    {
        $count = count($closes);
        $rawK = [];

        for ($i = $period - 1; $i < $count; $i++) {
            $highest = max(array_slice($highs, $i - $period + 1, $period));
            $lowest = min(array_slice($lows, $i - $period + 1, $period));
            $rawK[] = $highest > $lowest ? ($closes[$i] - $lowest) / ($highest - $lowest) * 100 : 50.0;
        }

        // Smoothed on the dense (no-null) series, then shifted back into
        // place — same approach macd() uses for its signal line.
        $kDense = $this->sma($rawK, $kSmoothing);
        $dDense = $this->sma(array_values(array_filter($kDense, fn ($v) => $v !== null)), $dPeriod);

        $k = array_fill(0, $count, null);
        $d = array_fill(0, $count, null);
        $firstK = $period - 1;
        $firstD = $firstK + $kSmoothing - 1;

        foreach ($kDense as $j => $value) {
            $k[$firstK + $j] = $value;
        }
        foreach ($dDense as $j => $value) {
            $d[$firstD + $j] = $value;
        }

        return ['k' => $k, 'd' => $d];
    }

    /**
     * @param  float[]  $highs
     * @param  float[]  $lows
     * @param  float[]  $closes
     * @return array<int, float|null>
     */
    public function atr(array $highs, array $lows, array $closes, int $period = 14): array
    {
        $count = count($closes);
        $result = array_fill(0, $count, null);

        if ($count <= $period) {
            return $result;
        }

        $trueRanges = [];
        for ($i = 1; $i < $count; $i++) {
            $trueRanges[$i] = max(
                $highs[$i] - $lows[$i],
                abs($highs[$i] - $closes[$i - 1]),
                abs($lows[$i] - $closes[$i - 1]),
            );
        }

        $avgTr = array_sum(array_slice($trueRanges, 1, $period, true)) / $period;
        $result[$period] = $avgTr;

        for ($i = $period + 1; $i < $count; $i++) {
            $avgTr = ((($avgTr * ($period - 1)) + $trueRanges[$i])) / $period;
            $result[$i] = $avgTr;
        }

        return $result;
    }
}
