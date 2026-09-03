<?php

namespace App\Services\MarketData;

/**
 * Holt's linear trend method (double exponential smoothing) — a real,
 * established forecasting technique, not a naive straight line. Unlike
 * ordinary least-squares regression, it weights recent observations more
 * heavily and separately tracks a smoothed level and trend. Smoothing
 * parameters are chosen per fit via grid search minimizing in-sample
 * one-step-ahead error, not hardcoded.
 */
class HoltForecastService
{
    private const ALPHA_GRID = [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9];

    private const BETA_GRID = [0.05, 0.1, 0.2, 0.3, 0.4, 0.5];

    /**
     * Recent-window lookback used for fitting — bounds the cost of a fit
     * regardless of how much total history a stock has (matters a lot for
     * backtesting, which fits repeatedly), and is standard practice for
     * exponential smoothing anyway (old data is barely weighted regardless).
     */
    public const DEFAULT_LOOKBACK = 250;

    /**
     * @param  float[]  $closes
     * @return array{alpha: float, beta: float, level: float, trend: float, sse: float}
     */
    public function fit(array $closes): array
    {
        $n = count($closes);

        if ($n < 2) {
            $last = $closes[0] ?? 0.0;

            return ['alpha' => 0.0, 'beta' => 0.0, 'level' => $last, 'trend' => 0.0, 'sse' => 0.0];
        }

        $best = null;

        foreach (self::ALPHA_GRID as $alpha) {
            foreach (self::BETA_GRID as $beta) {
                $result = $this->runSmoothing($closes, $alpha, $beta);

                if ($best === null || $result['sse'] < $best['sse']) {
                    $best = [...$result, 'alpha' => $alpha, 'beta' => $beta];
                }
            }
        }

        return $best;
    }

    /**
     * @param  float[]  $closes  Recent history — caller decides the window.
     * @return float[] `$steps` values, one per future period.
     */
    public function forecast(array $closes, int $steps): array
    {
        $fit = $this->fit($closes);

        $forecasts = [];
        for ($h = 1; $h <= $steps; $h++) {
            $forecasts[] = max(0.0, $fit['level'] + ($h * $fit['trend']));
        }

        return $forecasts;
    }

    /**
     * Walk-forward backtest over one stock's full close history: at each
     * step, fit only on the preceding DEFAULT_LOOKBACK days (exactly what
     * generate() does live), forecast `horizon` days ahead, and compare to
     * what actually happened.
     *
     * @param  float[]  $closes  Full chronological close history.
     * @return list<array{pct_error: float, correct_direction: bool}>
     */
    public function backtestOne(array $closes, int $horizon, int $step = 5): array
    {
        $n = count($closes);
        $minHistory = 60;
        $results = [];

        for ($i = $minHistory; $i + $horizon < $n; $i += $step) {
            $windowStart = max(0, $i + 1 - self::DEFAULT_LOOKBACK);
            $window = array_slice($closes, $windowStart, $i + 1 - $windowStart);

            $forecast = $this->forecast($window, $horizon);
            $predicted = $forecast[$horizon - 1];

            $today = $closes[$i];
            $actual = $closes[$i + $horizon];

            $results[] = [
                'pct_error' => $actual != 0.0 ? abs(($actual - $predicted) / $actual) : 0.0,
                'correct_direction' => ($actual > $today) === ($predicted > $today),
            ];
        }

        return $results;
    }

    /**
     * @param  float[]  $values
     * @return array{level: float, trend: float, sse: float}
     */
    private function runSmoothing(array $values, float $alpha, float $beta): array
    {
        $level = $values[0];
        $trend = $values[1] - $values[0];
        $sse = 0.0;
        $n = count($values);

        for ($i = 1; $i < $n; $i++) {
            $oneStepForecast = $level + $trend;
            $error = $values[$i] - $oneStepForecast;
            $sse += $error ** 2;

            $newLevel = ($alpha * $values[$i]) + ((1 - $alpha) * ($level + $trend));
            $newTrend = ($beta * ($newLevel - $level)) + ((1 - $beta) * $trend);

            $level = $newLevel;
            $trend = $newTrend;
        }

        return ['level' => $level, 'trend' => $trend, 'sse' => $sse];
    }
}
