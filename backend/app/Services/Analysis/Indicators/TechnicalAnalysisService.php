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
     * Trailing 365-calendar-day high/low as of each day — a calendar window,
     * not a fixed row count, since trading days per year vary (holidays).
     *
     * @param  string[]  $dates  'Y-m-d', oldest -> newest, aligned with $highs/$lows
     * @param  float[]  $highs
     * @param  float[]  $lows
     * @return array{high: array<int, float|null>, low: array<int, float|null>}
     */
    public function fiftyTwoWeekRange(array $dates, array $highs, array $lows): array
    {
        $count = count($dates);
        $high52 = array_fill(0, $count, null);
        $low52 = array_fill(0, $count, null);

        // Two-pointer: $start only ever moves forward, so this is O(n) total
        // rather than O(n × 365) despite the sliding window.
        $timestamps = array_map(strtotime(...), $dates);
        $start = 0;

        for ($i = 0; $i < $count; $i++) {
            $cutoff = $timestamps[$i] - (365 * 86400);
            while ($timestamps[$start] < $cutoff) {
                $start++;
            }
            $high52[$i] = max(array_slice($highs, $start, $i - $start + 1));
            $low52[$i] = min(array_slice($lows, $start, $i - $start + 1));
        }

        return ['high' => $high52, 'low' => $low52];
    }

    /**
     * Today's volume over its trailing $period-day average — "volume
     * change": > 1 means above-average participation, < 1 below.
     *
     * @param  int[]  $volumes
     * @return array<int, float|null>
     */
    public function volumeRatio(array $volumes, int $period = 20): array
    {
        $count = count($volumes);
        $result = array_fill(0, $count, null);

        for ($i = $period - 1; $i < $count; $i++) {
            $window = array_slice($volumes, $i - $period + 1, $period);
            $avg = array_sum($window) / $period;
            $result[$i] = $avg > 0 ? round($volumes[$i] / $avg, 4) : null;
        }

        return $result;
    }

    /**
     * The closest support and resistance level as of each day — the same
     * swing-detection-then-clustering approach as supportResistanceLevels()
     * (used live by the discretionary Technical Analysis report), just run
     * once per historical day instead of only for "today". $lookback bounds
     * the window each day's levels are drawn from, matching the report's own
     * window, so a day's persisted level means the same thing the live
     * report would have shown for that day at the time.
     *
     * @param  float[]  $highs
     * @param  float[]  $lows
     * @param  float[]  $closes
     * @return array{support: array<int, float|null>, resistance: array<int, float|null>}
     */
    public function supportResistanceSeries(array $highs, array $lows, array $closes, int $lookback = 260, int $window = 3): array
    {
        $count = count($closes);
        $support = array_fill(0, $count, null);
        $resistance = array_fill(0, $count, null);

        for ($i = 0; $i < $count; $i++) {
            $start = max(0, $i - $lookback);
            $levels = $this->supportResistanceLevels(
                array_slice($highs, $start, $i - $start + 1),
                array_slice($lows, $start, $i - $start + 1),
                $closes[$i],
                $window,
            );
            $resistance[$i] = $levels['resistance'][0]['price'] ?? null;
            $support[$i] = $levels['support'][0]['price'] ?? null;
        }

        return ['support' => $support, 'resistance' => $resistance];
    }

    /**
     * Support/resistance levels as of "now" (the end of the given arrays),
     * for a single point-in-time read — what the live Technical Analysis
     * report uses. Swing highs/lows via a local-extreme window, clustered
     * into levels by proximity so several nearby touches count as one
     * stronger level, strongest first.
     *
     * @param  float[]  $highs
     * @param  float[]  $lows
     * @return array{resistance: list<array{price: float, strength: int}>, support: list<array{price: float, strength: int}>}
     */
    public function supportResistanceLevels(array $highs, array $lows, float $currentPrice, int $window = 3): array
    {
        $n = count($highs);
        $swingHighs = [];
        $swingLows = [];

        for ($k = $window; $k < $n - $window; $k++) {
            $highWindow = array_slice($highs, $k - $window, $window * 2 + 1);
            if ($highs[$k] === max($highWindow)) {
                $swingHighs[] = $highs[$k];
            }
            $lowWindow = array_slice($lows, $k - $window, $window * 2 + 1);
            if ($lows[$k] === min($lowWindow)) {
                $swingLows[] = $lows[$k];
            }
        }

        $resistance = $this->clusterLevels(array_filter($swingHighs, fn ($p) => $p > $currentPrice));
        usort($resistance, fn ($a, $b) => $a['price'] <=> $b['price']);

        $support = $this->clusterLevels(array_filter($swingLows, fn ($p) => $p < $currentPrice));
        usort($support, fn ($a, $b) => $b['price'] <=> $a['price']);

        return [
            'resistance' => array_slice($resistance, 0, 3),
            'support' => array_slice($support, 0, 3),
        ];
    }

    /**
     * Groups nearby price points (within $tolerancePct of each other) into
     * one level, strength = how many points landed in it. Strongest first.
     *
     * @param  float[]  $prices
     * @return list<array{price: float, strength: int}>
     */
    private function clusterLevels(array $prices, float $tolerancePct = 0.015): array
    {
        $prices = array_values($prices);
        sort($prices);

        $clusters = [];
        foreach ($prices as $p) {
            $matched = false;
            foreach ($clusters as &$cluster) {
                if ($cluster['price'] > 0 && abs($p - $cluster['price']) / $cluster['price'] <= $tolerancePct) {
                    $cluster['price'] = (($cluster['price'] * $cluster['strength']) + $p) / ($cluster['strength'] + 1);
                    $cluster['strength']++;
                    $matched = true;
                    break;
                }
            }
            unset($cluster);
            if (! $matched) {
                $clusters[] = ['price' => $p, 'strength' => 1];
            }
        }

        usort($clusters, fn ($a, $b) => $b['strength'] <=> $a['strength']);
        $strongest = array_slice($clusters, 0, 6);

        return array_map(fn ($c) => ['price' => round($c['price'], 2), 'strength' => $c['strength']], $strongest);
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

    /**
     * ATR expressed as a % of the close instead of rupees — atr() alone
     * isn't comparable between a Rs. 100 stock and a Rs. 5,000 one; this is.
     *
     * @param  array<int, float|null>  $atr  output of atr()
     * @param  float[]  $closes
     * @return array<int, float|null>
     */
    public function atrPercent(array $atr, array $closes): array
    {
        $result = array_fill(0, count($atr), null);

        foreach ($atr as $i => $value) {
            if ($value !== null && ($closes[$i] ?? 0.0) > 0.0) {
                $result[$i] = round($value / $closes[$i] * 100, 4);
            }
        }

        return $result;
    }

    /**
     * Average Directional Index (Wilder, 14) plus its two directional-
     * movement components. Unlike every other indicator here, ADX says
     * nothing about direction — only whether a trend exists worth trading
     * at all (conventionally: < 20 no trend / choppy, > 25 trending).
     * +DI/-DI say which direction is winning the directional movement.
     *
     * Same Wilder smoothing atr() already uses (an average, re-smoothed
     * each day as prior×(period-1)/period + today/period) applied to true
     * range, +DM and -DM in parallel, so +DI/-DI stay valid ratios of two
     * series smoothed the identical way.
     *
     * @param  float[]  $highs
     * @param  float[]  $lows
     * @param  float[]  $closes
     * @return array{adx: array<int, float|null>, plus_di: array<int, float|null>, minus_di: array<int, float|null>}
     */
    public function adx(array $highs, array $lows, array $closes, int $period = 14): array
    {
        $count = count($closes);
        $adx = array_fill(0, $count, null);
        $plusDi = array_fill(0, $count, null);
        $minusDi = array_fill(0, $count, null);

        // +DI/-DI need $period smoothed days; ADX then needs a further
        // $period DX values on top of that before it has anything to smooth.
        if ($count < $period * 2) {
            return ['adx' => $adx, 'plus_di' => $plusDi, 'minus_di' => $minusDi];
        }

        $trueRanges = [];
        $plusDm = [];
        $minusDm = [];
        for ($i = 1; $i < $count; $i++) {
            $trueRanges[$i] = max(
                $highs[$i] - $lows[$i],
                abs($highs[$i] - $closes[$i - 1]),
                abs($lows[$i] - $closes[$i - 1]),
            );

            // Never both positive: a day's movement counts toward whichever
            // direction moved more, or neither if the moves are equal.
            $upMove = $highs[$i] - $highs[$i - 1];
            $downMove = $lows[$i - 1] - $lows[$i];
            $plusDm[$i] = ($upMove > $downMove && $upMove > 0) ? $upMove : 0.0;
            $minusDm[$i] = ($downMove > $upMove && $downMove > 0) ? $downMove : 0.0;
        }

        $avgTr = array_sum(array_slice($trueRanges, 1, $period, true)) / $period;
        $avgPlusDm = array_sum(array_slice($plusDm, 1, $period, true)) / $period;
        $avgMinusDm = array_sum(array_slice($minusDm, 1, $period, true)) / $period;

        $dx = array_fill(0, $count, null);
        [$plusDi[$period], $minusDi[$period], $dx[$period]] = $this->diAndDx($avgPlusDm, $avgMinusDm, $avgTr);

        for ($i = $period + 1; $i < $count; $i++) {
            $avgTr = ((($avgTr * ($period - 1)) + $trueRanges[$i])) / $period;
            $avgPlusDm = ((($avgPlusDm * ($period - 1)) + $plusDm[$i])) / $period;
            $avgMinusDm = ((($avgMinusDm * ($period - 1)) + $minusDm[$i])) / $period;

            [$plusDi[$i], $minusDi[$i], $dx[$i]] = $this->diAndDx($avgPlusDm, $avgMinusDm, $avgTr);
        }

        // ADX = Wilder-smoothed DX, itself only available once a full
        // $period window of DX values exists (DX starts at index $period).
        $firstAdxIndex = ($period * 2) - 1;
        $avgDx = array_sum(array_slice($dx, $period, $period, true)) / $period;
        $adx[$firstAdxIndex] = $avgDx;

        for ($i = $firstAdxIndex + 1; $i < $count; $i++) {
            $avgDx = ((($avgDx * ($period - 1)) + $dx[$i])) / $period;
            $adx[$i] = $avgDx;
        }

        return ['adx' => $adx, 'plus_di' => $plusDi, 'minus_di' => $minusDi];
    }

    /** @return array{0: float, 1: float, 2: float} +DI, -DI, DX for one day's smoothed averages */
    private function diAndDx(float $avgPlusDm, float $avgMinusDm, float $avgTr): array
    {
        $plusDi = $avgTr > 0 ? 100 * $avgPlusDm / $avgTr : 0.0;
        $minusDi = $avgTr > 0 ? 100 * $avgMinusDm / $avgTr : 0.0;
        $diSum = $plusDi + $minusDi;
        $dx = $diSum > 0 ? 100 * abs($plusDi - $minusDi) / $diSum : 0.0;

        return [$plusDi, $minusDi, $dx];
    }
}
