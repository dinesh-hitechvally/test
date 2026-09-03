<?php

namespace App\Services\MarketData;

use App\Models\Stock;

/**
 * Assembles a full discretionary-style technical analysis report for one
 * stock — trend, support/resistance, candlestick patterns, volume,
 * moving averages, RSI, MACD, Bollinger Bands, breakout state, Fibonacci
 * levels, a price target / stop-loss / risk-reward setup, and a composite
 * bias tally.
 *
 * Everything here reads already-stored daily_prices + technical_indicators
 * (no live recomputation) and is rule-based, not statistically fitted or
 * backtested — every section is a transparent, inspectable heuristic, and
 * the final "probability assessment" is explicitly labelled as a tally of
 * these rules rather than a real statistical probability. None of this is
 * financial advice.
 */
class TechnicalAnalysisReportService
{
    private const LOOKBACK_DAYS = 260;

    public function build(Stock $stock): array
    {
        $prices = $stock->dailyPrices()->orderByDesc('trade_date')->limit(self::LOOKBACK_DAYS)->get()->reverse()->values();

        if ($prices->count() < 30) {
            return ['available' => false, 'message' => 'Not enough price history yet for a technical analysis report (need at least 30 trading days).'];
        }

        $dates = $prices->pluck('trade_date')->map(fn ($d) => $d->toDateString())->all();
        $opens = $prices->pluck('open_price')->map(fn ($v) => (float) $v)->all();
        $highs = $prices->pluck('high_price')->map(fn ($v) => (float) $v)->all();
        $lows = $prices->pluck('low_price')->map(fn ($v) => (float) $v)->all();
        $closes = $prices->pluck('close_price')->map(fn ($v) => (float) $v)->all();
        $volumes = $prices->pluck('volume')->map(fn ($v) => (int) $v)->all();

        $indicatorsByDate = $stock->technicalIndicators()
            ->whereIn('trade_date', $dates)
            ->get()
            ->keyBy(fn ($row) => $row->trade_date->toDateString());

        $field = fn (string $column) => array_map(
            fn ($date) => $indicatorsByDate->get($date)?->{$column} !== null ? (float) $indicatorsByDate->get($date)->{$column} : null,
            $dates
        );

        $ind = [
            'sma_20' => $field('sma_20'),
            'sma_50' => $field('sma_50'),
            'sma_100' => $field('sma_100'),
            'sma_200' => $field('sma_200'),
            'rsi_14' => $field('rsi_14'),
            'macd' => $field('macd'),
            'macd_signal' => $field('macd_signal'),
            'macd_histogram' => $field('macd_histogram'),
            'bb_upper' => $field('bb_upper'),
            'bb_middle' => $field('bb_middle'),
            'bb_lower' => $field('bb_lower'),
            'atr_14' => $field('atr_14'),
        ];

        $i = count($dates) - 1; // "today" — the most recent row in the window
        $close = $closes[$i];
        $votes = [];

        $trend = $this->trend($ind, $closes, $i, $votes);
        $supportResistance = $this->supportResistance($highs, $lows, $close);
        $candlesticks = $this->candlestickPatterns($opens, $highs, $lows, $closes, $i, $votes);
        $volume = $this->volumeAnalysis($volumes, $closes, $i, $votes);
        $movingAverages = $this->movingAverages($ind, $close, $i, $votes);
        $rsi = $this->rsiAnalysis($ind['rsi_14'], $closes, $i, $votes);
        $macd = $this->macdAnalysis($ind, $i, $votes);
        $bollinger = $this->bollingerAnalysis($ind, $close, $i, $votes);
        $breakout = $this->breakoutAnalysis($closes, $i, $volume['volume_ratio'], $votes);
        $fibonacci = $this->fibonacciLevels($highs, $lows, $dates, $i);
        $tradeSetup = $this->tradeSetup($trend['direction'], $close, $supportResistance, $ind['atr_14'][$i] ?? null, $fibonacci);
        $probability = $this->probabilityAssessment($votes);

        return [
            'available' => true,
            'as_of' => $dates[$i],
            'close' => round($close, 2),
            'trend' => $trend,
            'support_resistance' => $supportResistance,
            'candlestick_patterns' => $candlesticks,
            'volume_analysis' => $volume,
            'moving_averages' => $movingAverages,
            'rsi' => $rsi,
            'macd' => $macd,
            'bollinger_bands' => $bollinger,
            'breakout' => $breakout,
            'fibonacci' => $fibonacci,
            'trade_setup' => $tradeSetup,
            'probability_assessment' => $probability,
        ];
    }

    /**
     * @param  array{sma_50: array<int, ?float>, sma_200: array<int, ?float>}  $ind
     */
    private function trend(array $ind, array $closes, int $i, array &$votes): array
    {
        $close = $closes[$i];
        $sma50 = $ind['sma_50'][$i] ?? null;
        $sma200 = $ind['sma_200'][$i] ?? null;
        $sma50Prior = $ind['sma_50'][$i - 10] ?? null;

        if ($sma50 === null || $sma200 === null) {
            return ['direction' => 'unknown', 'reason' => 'Not enough history yet (need 200+ trading days).'];
        }

        $slope = $sma50Prior === null ? 'unknown' : ($sma50 > $sma50Prior ? 'rising' : ($sma50 < $sma50Prior ? 'falling' : 'flat'));

        $direction = match (true) {
            $close > $sma50 && $sma50 > $sma200 && $slope === 'rising' => 'bullish',
            $close < $sma50 && $sma50 < $sma200 && $slope === 'falling' => 'bearish',
            default => 'sideways',
        };

        $votes[] = ['label' => 'Trend', 'vote' => $direction === 'bullish' ? 1 : ($direction === 'bearish' ? -1 : 0)];

        return [
            'direction' => $direction,
            'price_vs_sma50' => $close > $sma50 ? 'above' : 'below',
            'sma50_vs_sma200' => $sma50 > $sma200 ? 'above' : 'below',
            'sma50_slope' => $slope,
        ];
    }

    /**
     * Swing highs/lows via a local-extreme window, clustered into levels by
     * proximity so several nearby touches count as one stronger level.
     */
    private function supportResistance(array $highs, array $lows, float $currentPrice, int $window = 3): array
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
     * A handful of classic 1-3 candle reversal/continuation patterns,
     * checked on the most recent candles. Multiple patterns can fire at
     * once (rare, but not mutually exclusive by construction here).
     */
    private function candlestickPatterns(array $opens, array $highs, array $lows, array $closes, int $i, array &$votes): array
    {
        $found = [];
        $candle = fn (int $k) => [
            'o' => $opens[$k], 'h' => $highs[$k], 'l' => $lows[$k], 'c' => $closes[$k],
            'body' => abs($closes[$k] - $opens[$k]),
            'range' => max($highs[$k] - $lows[$k], 0.0001),
            'upper_wick' => $highs[$k] - max($opens[$k], $closes[$k]),
            'lower_wick' => min($opens[$k], $closes[$k]) - $lows[$k],
            'bullish' => $closes[$k] > $opens[$k],
        ];

        $today = $candle($i);
        $priorTrendDown = $i >= 5 && $closes[$i - 1] < $closes[$i - 5];
        $priorTrendUp = $i >= 5 && $closes[$i - 1] > $closes[$i - 5];

        if ($today['body'] <= 0.1 * $today['range']) {
            $found[] = ['name' => 'Doji', 'signal' => 'neutral', 'note' => 'Indecision — open and close nearly equal.'];
        }

        if ($priorTrendDown && $today['lower_wick'] >= 2 * $today['body'] && $today['upper_wick'] <= 0.3 * $today['range']) {
            $found[] = ['name' => 'Hammer', 'signal' => 'bullish', 'note' => 'Long lower wick after a downtrend — potential reversal up.'];
        }

        if ($priorTrendUp && $today['upper_wick'] >= 2 * $today['body'] && $today['lower_wick'] <= 0.3 * $today['range']) {
            $found[] = ['name' => 'Shooting Star', 'signal' => 'bearish', 'note' => 'Long upper wick after an uptrend — potential reversal down.'];
        }

        if ($i >= 1) {
            $prev = $candle($i - 1);

            if (! $prev['bullish'] && $today['bullish'] && $today['o'] <= $prev['c'] && $today['c'] >= $prev['o']) {
                $found[] = ['name' => 'Bullish Engulfing', 'signal' => 'bullish', 'note' => "Today's candle fully engulfs the prior bearish candle."];
            }

            if ($prev['bullish'] && ! $today['bullish'] && $today['o'] >= $prev['c'] && $today['c'] <= $prev['o']) {
                $found[] = ['name' => 'Bearish Engulfing', 'signal' => 'bearish', 'note' => "Today's candle fully engulfs the prior bullish candle."];
            }

            if (! $prev['bullish'] && $today['bullish'] && $today['o'] < $prev['c'] && $today['c'] > ($prev['o'] + $prev['c']) / 2 && $today['c'] < $prev['o']) {
                $found[] = ['name' => 'Piercing Line', 'signal' => 'bullish', 'note' => 'Gapped down then closed above the midpoint of the prior bearish candle.'];
            }

            if ($prev['bullish'] && ! $today['bullish'] && $today['o'] > $prev['c'] && $today['c'] < ($prev['o'] + $prev['c']) / 2 && $today['c'] > $prev['o']) {
                $found[] = ['name' => 'Dark Cloud Cover', 'signal' => 'bearish', 'note' => 'Gapped up then closed below the midpoint of the prior bullish candle.'];
            }
        }

        if ($i >= 2) {
            $first = $candle($i - 2);
            $middle = $candle($i - 1);

            if (! $first['bullish'] && $first['body'] > $first['range'] * 0.5
                && $middle['body'] < $first['body'] * 0.5
                && $today['bullish'] && $today['c'] > ($first['o'] + $first['c']) / 2) {
                $found[] = ['name' => 'Morning Star', 'signal' => 'bullish', 'note' => '3-candle bottom reversal: big drop, indecision, strong bounce back.'];
            }

            if ($first['bullish'] && $first['body'] > $first['range'] * 0.5
                && $middle['body'] < $first['body'] * 0.5
                && ! $today['bullish'] && $today['c'] < ($first['o'] + $first['c']) / 2) {
                $found[] = ['name' => 'Evening Star', 'signal' => 'bearish', 'note' => '3-candle top reversal: big rally, indecision, strong drop back.'];
            }
        }

        $net = array_sum(array_map(fn ($p) => $p['signal'] === 'bullish' ? 1 : ($p['signal'] === 'bearish' ? -1 : 0), $found));
        if ($net !== 0) {
            $votes[] = ['label' => 'Candlestick patterns', 'vote' => $net > 0 ? 1 : -1];
        }

        return $found;
    }

    private function volumeAnalysis(array $volumes, array $closes, int $i, array &$votes): array
    {
        $avgPeriod = 20;
        $start = max(0, $i - $avgPeriod + 1);
        $window = array_slice($volumes, $start, $i - $start + 1);
        $avgVolume = count($window) > 0 ? array_sum($window) / count($window) : 0;
        $ratio = $avgVolume > 0 ? $volumes[$i] / $avgVolume : null;

        $obvStart = max(1, $i - 40);
        $obv = 0;
        $obvFirst = null;
        for ($k = $obvStart; $k <= $i; $k++) {
            if ($closes[$k] > $closes[$k - 1]) {
                $obv += $volumes[$k];
            } elseif ($closes[$k] < $closes[$k - 1]) {
                $obv -= $volumes[$k];
            }
            if ($obvFirst === null) {
                $obvFirst = $obv;
            }
        }

        $obvTrend = $obvFirst !== null && $obv !== $obvFirst ? ($obv > $obvFirst ? 'rising' : 'falling') : 'flat';
        $classification = match ($obvTrend) {
            'rising' => 'accumulation',
            'falling' => 'distribution',
            default => 'neutral',
        };

        $votes[] = ['label' => 'Volume (OBV trend)', 'vote' => $classification === 'accumulation' ? 1 : ($classification === 'distribution' ? -1 : 0)];

        return [
            'today_volume' => $volumes[$i],
            'avg_volume_20d' => round($avgVolume),
            'volume_ratio' => $ratio !== null ? round($ratio, 2) : null,
            'volume_level' => $ratio === null ? 'unknown' : ($ratio >= 1.5 ? 'well above average' : ($ratio >= 1.1 ? 'above average' : ($ratio <= 0.7 ? 'below average' : 'average'))),
            'obv_trend' => $obvTrend,
            'classification' => $classification,
        ];
    }

    private function movingAverages(array $ind, float $close, int $i, array &$votes): array
    {
        $periods = ['20' => $ind['sma_20'][$i] ?? null, '50' => $ind['sma_50'][$i] ?? null, '100' => $ind['sma_100'][$i] ?? null, '200' => $ind['sma_200'][$i] ?? null];

        $rows = [];
        foreach ($periods as $period => $value) {
            $rows[] = [
                'period' => (int) $period,
                'value' => $value !== null ? round($value, 2) : null,
                'position' => $value !== null ? ($close > $value ? 'above' : 'below') : null,
            ];
        }

        $allPresent = ! in_array(null, $periods, true);
        $bullishAligned = $allPresent && $periods['20'] > $periods['50'] && $periods['50'] > $periods['100'] && $periods['100'] > $periods['200'];
        $bearishAligned = $allPresent && $periods['20'] < $periods['50'] && $periods['50'] < $periods['100'] && $periods['100'] < $periods['200'];
        $alignment = $bullishAligned ? 'bullish' : ($bearishAligned ? 'bearish' : 'mixed');

        $votes[] = ['label' => 'Moving average alignment', 'vote' => $alignment === 'bullish' ? 1 : ($alignment === 'bearish' ? -1 : 0)];

        return ['series' => $rows, 'alignment' => $alignment];
    }

    private function rsiAnalysis(array $rsiSeries, array $closes, int $i, array &$votes): array
    {
        $rsi = $rsiSeries[$i] ?? null;

        if ($rsi === null) {
            return ['value' => null, 'state' => 'unknown', 'divergence' => null];
        }

        $state = match (true) {
            $rsi >= 70 => 'overbought',
            $rsi <= 30 => 'oversold',
            default => 'neutral',
        };

        $divergence = $this->detectRsiDivergence($rsiSeries, $closes, $i);

        $votes[] = ['label' => 'RSI level', 'vote' => $state === 'oversold' ? 1 : ($state === 'overbought' ? -1 : 0)];
        if ($divergence !== null) {
            $votes[] = ['label' => 'RSI divergence', 'vote' => $divergence === 'bullish' ? 1 : -1];
        }

        return ['value' => round($rsi, 1), 'state' => $state, 'divergence' => $divergence];
    }

    /**
     * Compares the two most recent price swing points against RSI at those
     * same points — a lower price low with a higher RSI low (or the mirror
     * at highs) is a classic early-warning divergence signal.
     */
    private function detectRsiDivergence(array $rsiSeries, array $closes, int $i, int $lookback = 40): ?string
    {
        $start = max(2, $i - $lookback);
        $swingLows = [];
        $swingHighs = [];

        for ($k = $start; $k <= $i - 2; $k++) {
            if ($k < 2) {
                continue;
            }
            if ($closes[$k] <= $closes[$k - 1] && $closes[$k] <= $closes[$k - 2] && $closes[$k] <= $closes[$k + 1] && $closes[$k] <= $closes[$k + 2]) {
                $swingLows[] = $k;
            }
            if ($closes[$k] >= $closes[$k - 1] && $closes[$k] >= $closes[$k - 2] && $closes[$k] >= $closes[$k + 1] && $closes[$k] >= $closes[$k + 2]) {
                $swingHighs[] = $k;
            }
        }

        if (count($swingLows) >= 2) {
            [$a, $b] = array_slice($swingLows, -2);
            if ($closes[$b] < $closes[$a] && $rsiSeries[$b] !== null && $rsiSeries[$a] !== null && $rsiSeries[$b] > $rsiSeries[$a]) {
                return 'bullish';
            }
        }

        if (count($swingHighs) >= 2) {
            [$a, $b] = array_slice($swingHighs, -2);
            if ($closes[$b] > $closes[$a] && $rsiSeries[$b] !== null && $rsiSeries[$a] !== null && $rsiSeries[$b] < $rsiSeries[$a]) {
                return 'bearish';
            }
        }

        return null;
    }

    private function macdAnalysis(array $ind, int $i, array &$votes): array
    {
        $macd = $ind['macd'][$i] ?? null;
        $signal = $ind['macd_signal'][$i] ?? null;
        $hist = $ind['macd_histogram'][$i] ?? null;
        $histPrev = $ind['macd_histogram'][$i - 1] ?? null;

        if ($macd === null || $signal === null) {
            return ['state' => 'unknown'];
        }

        $macdPrev = $ind['macd'][$i - 1] ?? null;
        $signalPrev = $ind['macd_signal'][$i - 1] ?? null;
        $crossover = null;
        if ($macdPrev !== null && $signalPrev !== null) {
            if ($macdPrev <= $signalPrev && $macd > $signal) {
                $crossover = 'bullish_cross';
            } elseif ($macdPrev >= $signalPrev && $macd < $signal) {
                $crossover = 'bearish_cross';
            }
        }

        $momentum = ($hist !== null && $histPrev !== null) ? (abs($hist) > abs($histPrev) ? 'strengthening' : 'weakening') : 'unknown';
        $position = $macd > $signal ? 'above_signal' : 'below_signal';

        $vote = $crossover === 'bullish_cross' ? 1 : ($crossover === 'bearish_cross' ? -1 : ($position === 'above_signal' ? 1 : -1));
        $votes[] = ['label' => 'MACD', 'vote' => $vote];

        return [
            'macd' => round($macd, 3),
            'signal' => round($signal, 3),
            'histogram' => $hist !== null ? round($hist, 3) : null,
            'position' => $position,
            'crossover' => $crossover,
            'momentum' => $momentum,
        ];
    }

    private function bollingerAnalysis(array $ind, float $close, int $i, array &$votes): array
    {
        $upper = $ind['bb_upper'][$i] ?? null;
        $middle = $ind['bb_middle'][$i] ?? null;
        $lower = $ind['bb_lower'][$i] ?? null;

        if ($upper === null || $middle === null || $middle == 0.0) {
            return ['state' => 'unknown'];
        }

        $bandwidth = ($upper - $lower) / $middle;

        $j = $i - 60;
        $priorMiddle = $ind['bb_middle'][$j] ?? null;
        $bandwidthPrior = null;
        if ($j >= 0 && $priorMiddle !== null && $priorMiddle > 0 && $ind['bb_upper'][$j] !== null && $ind['bb_lower'][$j] !== null) {
            $bandwidthPrior = ($ind['bb_upper'][$j] - $ind['bb_lower'][$j]) / $priorMiddle;
        }
        $squeeze = $bandwidthPrior !== null && $bandwidth < $bandwidthPrior * 0.7;

        $position = match (true) {
            $close >= $upper => 'at_or_above_upper',
            $close <= $lower => 'at_or_below_lower',
            default => 'inside_bands',
        };

        $votes[] = ['label' => 'Bollinger Band position', 'vote' => $position === 'at_or_below_lower' ? 1 : ($position === 'at_or_above_upper' ? -1 : 0)];

        return [
            'upper' => round($upper, 2),
            'middle' => round($middle, 2),
            'lower' => round($lower, 2),
            'position' => $position,
            'bandwidth_pct' => round($bandwidth * 100, 2),
            'squeeze' => $squeeze,
            'note' => $squeeze ? 'Bands have narrowed sharply versus ~3 months ago — volatility is compressed, often a precursor to a bigger move (direction unknown).' : null,
        ];
    }

    private function breakoutAnalysis(array $closes, int $i, ?float $volumeRatio, array &$votes): array
    {
        $period = 20;
        if ($i < $period) {
            return ['state' => 'unknown'];
        }

        $priorWindow = array_slice($closes, $i - $period, $period);
        $priorHigh = max($priorWindow);
        $priorLow = min($priorWindow);
        $close = $closes[$i];

        $state = match (true) {
            $close > $priorHigh => 'breakout_up',
            $close < $priorLow => 'breakdown_down',
            default => 'none',
        };

        $volumeConfirmed = $state !== 'none' && $volumeRatio !== null ? $volumeRatio >= 1.3 : null;

        if ($state !== 'none') {
            $votes[] = ['label' => 'Breakout/breakdown', 'vote' => $state === 'breakout_up' ? 1 : -1];
        }

        return [
            'state' => $state,
            'period_high_20d' => round($priorHigh, 2),
            'period_low_20d' => round($priorLow, 2),
            'volume_confirmed' => $volumeConfirmed,
            'note' => $state === 'none' ? 'Trading inside its 20-day range.' : ($volumeConfirmed
                ? 'Move is backed by above-average volume — technically more convincing.'
                : 'Move is not backed by above-average volume — technically weaker, higher chance of failing.'),
        ];
    }

    /**
     * Standard retracement + extension levels off the most significant
     * swing high/low within the lookback window.
     */
    private function fibonacciLevels(array $highs, array $lows, array $dates, int $i, int $lookback = 180): array
    {
        $start = max(0, $i - $lookback);
        $windowHighs = array_slice($highs, $start, $i - $start + 1);
        $windowLows = array_slice($lows, $start, $i - $start + 1);

        $highOffset = array_search(max($windowHighs), $windowHighs, true);
        $lowOffset = array_search(min($windowLows), $windowLows, true);
        $highIdx = $start + $highOffset;
        $lowIdx = $start + $lowOffset;

        $high = $highs[$highIdx];
        $low = $lows[$lowIdx];
        $range = $high - $low;

        if ($range <= 0) {
            return ['available' => false];
        }

        $uptrendContext = $lowIdx < $highIdx;

        $retracementLevels = array_map(function ($ratio) use ($uptrendContext, $high, $low, $range) {
            $price = $uptrendContext ? $high - ($range * $ratio) : $low + ($range * $ratio);

            return ['ratio' => $ratio, 'price' => round($price, 2)];
        }, [0.0, 0.236, 0.382, 0.5, 0.618, 0.786, 1.0]);

        $extensionLevels = array_map(function ($ratio) use ($uptrendContext, $high, $low, $range) {
            $price = $uptrendContext ? $high + ($range * ($ratio - 1)) : $low - ($range * ($ratio - 1));

            return ['ratio' => $ratio, 'price' => round($price, 2)];
        }, [1.272, 1.618]);

        return [
            'available' => true,
            'swing_high' => round($high, 2),
            'swing_high_date' => $dates[$highIdx],
            'swing_low' => round($low, 2),
            'swing_low_date' => $dates[$lowIdx],
            'context' => $uptrendContext ? 'retracement_from_swing_high' : 'retracement_from_swing_low',
            'retracement_levels' => $retracementLevels,
            'extension_levels' => $extensionLevels,
        ];
    }

    /**
     * Target/stop/risk-reward, biased by the overall trend direction.
     * Prefers a real support/resistance level for the stop when one exists
     * close enough to be meaningful; falls back to an ATR-based stop
     * otherwise (the standard approach when there's no clean level nearby).
     */
    private function tradeSetup(string $bias, float $close, array $sr, ?float $atr, array $fib): array
    {
        $atrStopMultiple = 2.0;
        $atrDistance = $atr !== null ? $atr * $atrStopMultiple : null;

        if ($bias === 'bullish') {
            $target = $sr['resistance'][0]['price'] ?? ($fib['extension_levels'][0]['price'] ?? null);
            $levelStop = $sr['support'][0]['price'] ?? null;
            $atrStop = $atrDistance !== null ? round($close - $atrDistance, 2) : null;
            $stop = ($levelStop !== null && $levelStop < $close) ? max($levelStop, $atrStop ?? -INF) : $atrStop;
        } elseif ($bias === 'bearish') {
            $target = $sr['support'][0]['price'] ?? ($fib['extension_levels'][0]['price'] ?? null);
            $levelStop = $sr['resistance'][0]['price'] ?? null;
            $atrStop = $atrDistance !== null ? round($close + $atrDistance, 2) : null;
            $stop = ($levelStop !== null && $levelStop > $close) ? min($levelStop, $atrStop ?? INF) : $atrStop;
        } else {
            $target = $sr['resistance'][0]['price'] ?? null;
            $stop = $sr['support'][0]['price'] ?? null;
        }

        $risk = $stop !== null ? abs($close - $stop) : null;
        $reward = $target !== null ? abs($target - $close) : null;
        $rr = ($risk !== null && $risk > 0 && $reward !== null) ? round($reward / $risk, 2) : null;

        return [
            'bias' => $bias,
            'entry_reference' => round($close, 2),
            'target' => $target !== null ? round($target, 2) : null,
            'stop_loss' => $stop !== null ? round($stop, 2) : null,
            'risk_per_share' => $risk !== null ? round($risk, 2) : null,
            'reward_per_share' => $reward !== null ? round($reward, 2) : null,
            'risk_reward_ratio' => $rr,
            'attractive' => $rr !== null ? $rr >= 1.5 : null,
        ];
    }

    /**
     * @param  list<array{label: string, vote: int}>  $votes
     */
    private function probabilityAssessment(array $votes): array
    {
        $total = count($votes);

        if ($total === 0) {
            return ['bullish_pct' => 0, 'neutral_pct' => 100, 'bearish_pct' => 0, 'overall_bias' => 'neutral', 'signals_considered' => 0];
        }

        $bull = count(array_filter($votes, fn ($v) => $v['vote'] > 0));
        $bear = count(array_filter($votes, fn ($v) => $v['vote'] < 0));

        $bullishPct = (int) round($bull / $total * 100);
        $bearishPct = (int) round($bear / $total * 100);
        $neutralPct = 100 - $bullishPct - $bearishPct;

        $overall = match (true) {
            $bullishPct - $bearishPct >= 30 => 'bullish',
            $bearishPct - $bullishPct >= 30 => 'bearish',
            default => 'neutral',
        };

        return [
            'bullish_pct' => $bullishPct,
            'neutral_pct' => max($neutralPct, 0),
            'bearish_pct' => $bearishPct,
            'overall_bias' => $overall,
            'signals_considered' => $total,
            'breakdown' => $votes,
            'disclaimer' => 'A rule-based tally of the technical signals above — not a statistically validated probability, and not financial advice.',
        ];
    }
}
