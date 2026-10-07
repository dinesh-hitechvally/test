<?php

namespace App\Services\Analysis\Signals;

use App\Models\StockFundamental;

/**
 * BUY / SELL / HOLD is the OUTPUT of this class, never its starting point.
 *
 *   market data
 *     -> individual conditions, each with its own {buy, sell, hold} % (always adding to 100)
 *     -> category % = the average of its conditions
 *     -> final % = the category %s weighted by config('signals.weights')
 *     -> decision rule (config('signals.decision_min_pct')) -> BUY / SELL / HOLD
 *     -> when the answer is HOLD, six hold-reason scores say which kind of hold it is
 *
 * Fundamental and valuation conditions are only produced when a fundamentals snapshot is passed in, which the
 * generator does for each stock's latest day only (StockFundamental has no history; applying today's numbers to past
 * days would put information that did not exist then into the backtest).
 *
 * Context array ($ctx) the generator passes for one day:
 *   today, yesterday, earlier (the row 10 sessions back)  technical_indicators rows (or null)
 *   close, prev_close, close_10d_ago                      floats (or null)
 *   percent_b                                             Bollinger %B (or null)
 *   rule_keys                                             every rule that fired (SignalRules keys)
 *   scoring_keys                                          the subset allowed to vote (dip-buys need a bounce)
 *   dip_suppressed                                        a dip-buy fired but no bounce confirmed it
 *   fundamental                                           StockFundamental or null
 */
class SignalConditionScorer
{
    public const CATEGORIES = ['technical', 'fundamental', 'trend', 'momentum', 'volume', 'risk', 'valuation'];

    public const HOLD_TYPES = [
        'long_term' => 'Long-term strength',
        'consolidation' => 'Consolidation',
        'wait_confirmation' => 'Waiting for confirmation',
        'profit_protection' => 'Profit protection',
        'temporary_weakness' => 'Temporary weakness',
        'overbought' => 'Overbought',
    ];

    /**
     * @param  array<string, mixed>  $ctx
     * @return array{
     *     conditions: array<string, list<array<string, mixed>>>,
     *     categories: array<string, ?array{buy: float, sell: float, hold: float}>,
     *     final: array{buy: float, sell: float, hold: float},
     *     decision: string,
     *     hold_scores: array<string, float>,
     *     hold_type: ?string
     * }
     */
    public function evaluate(array $ctx): array
    {
        $conditions = [
            'technical' => $this->technical($ctx),
            'fundamental' => $this->fundamental($ctx['fundamental'] ?? null),
            'trend' => $this->trend($ctx),
            'momentum' => $this->momentum($ctx),
            'volume' => $this->volume($ctx),
            'risk' => $this->risk($ctx),
            'valuation' => $this->valuation($ctx['fundamental'] ?? null),
        ];

        $categories = array_map(fn (array $list) => $this->average($list), $conditions);
        $final = $this->combine($categories);
        $decision = $this->decide($final);
        $holdScores = $this->holdScores($ctx, $categories, $final);

        return [
            'conditions' => $conditions,
            'categories' => $categories,
            'final' => $final,
            'decision' => $decision,
            'hold_scores' => $holdScores,
            'hold_type' => $decision === 'hold' ? array_key_first($this->sortedDesc($holdScores)) : null,
        ];
    }

    /** The decision rule: a side wins only with at least decision_min_pct; anything else is HOLD. */
    public function decide(array $final): string
    {
        $min = (float) config('signals.decision_min_pct');

        return match (true) {
            $final['buy'] >= $min => 'buy',
            $final['sell'] >= $min => 'sell',
            default => 'hold',
        };
    }

    /**
     * Weighted average of the categories that have data (weights renormalised over those).
     *
     * @param  array<string, ?array{buy: float, sell: float, hold: float}>  $categories
     * @return array{buy: float, sell: float, hold: float}
     */
    public function combine(array $categories): array
    {
        $weights = config('signals.weights');
        $sum = ['buy' => 0.0, 'sell' => 0.0, 'hold' => 0.0];
        $used = 0.0;

        foreach ($categories as $category => $triple) {
            if ($triple === null || ! isset($weights[$category])) {
                continue;
            }
            foreach ($sum as $side => $_) {
                $sum[$side] += $triple[$side] * $weights[$category];
            }
            $used += $weights[$category];
        }

        if ($used <= 0) {
            return ['buy' => 0.0, 'sell' => 0.0, 'hold' => 100.0];
        }

        return array_map(fn ($v) => round($v / $used, 2), $sum);
    }

    /** One line saying how the decision was reached. */
    public function explain(array $result): string
    {
        $final = $result['final'];
        $parts = [];

        foreach (self::CATEGORIES as $category) {
            if ($result['categories'][$category] !== null) {
                $parts[] = sprintf('%s %.0f%%', ucfirst($category), $result['categories'][$category][$result['decision']] ?? 0);
            }
        }

        return sprintf(
            '%s: BUY %.1f%% · SELL %.1f%% · HOLD %.1f%% (%s %% by category: %s)',
            strtoupper($result['decision']),
            $final['buy'],
            $final['sell'],
            $final['hold'],
            strtoupper($result['decision']),
            implode(', ', $parts)
        );
    }

    // ------------------------------------------------------------------ technical

    private function technical(array $c): array
    {
        $t = $c['today'];
        $keys = $c['rule_keys'];
        $voting = $c['scoring_keys'];
        $has = fn (string $k) => in_array($k, $keys, true);
        $out = [];

        if ($t->rsi_14 !== null) {
            $rsi = (float) $t->rsi_14;
            $out[] = match (true) {
                $has('rsi_oversold') && in_array('rsi_oversold', $voting, true) => $this->cond('rsi', 'RSI', sprintf('%.0f — oversold and bouncing', $rsi), 70, 10),
                $has('rsi_oversold') => $this->cond('rsi', 'RSI', sprintf('%.0f — oversold, no bounce yet', $rsi), 10, 25),
                $has('rsi_overbought') => $this->cond('rsi', 'RSI', sprintf('%.0f — rolled over from overbought', $rsi), 10, 65),
                $rsi > 70 => $this->cond('rsi', 'RSI', sprintf('%.0f — overbought', $rsi), 25, 25),
                $rsi >= 50 => $this->cond('rsi', 'RSI', sprintf('%.0f — bullish zone', $rsi), 50, 15),
                default => $this->cond('rsi', 'RSI', sprintf('%.0f — below 50', $rsi), 30, 25),
            };
        }

        if ($c['percent_b'] !== null) {
            $pb = $c['percent_b'];
            $out[] = match (true) {
                $has('bb_lower_touch') && in_array('bb_lower_touch', $voting, true) => $this->cond('bollinger', 'Bollinger %B', sprintf('%.2f — at the lower band and bouncing', $pb), 70, 10),
                $has('bb_lower_touch') => $this->cond('bollinger', 'Bollinger %B', sprintf('%.2f — at the lower band, no bounce yet', $pb), 10, 25),
                $has('bb_upper_touch') => $this->cond('bollinger', 'Bollinger %B', sprintf('%.2f — rejected from the upper band', $pb), 10, 65),
                $pb >= 0.5 => $this->cond('bollinger', 'Bollinger %B', sprintf('%.2f — upper half of the bands', $pb), 50, 15),
                default => $this->cond('bollinger', 'Bollinger %B', sprintf('%.2f — lower half of the bands', $pb), 30, 25),
            };
        }

        if ($t->macd !== null && $t->macd_signal !== null) {
            $out[] = match (true) {
                $has('macd_bull_cross') => $this->cond('macd', 'MACD', 'Bullish crossover today', 80, 5),
                $has('macd_bear_cross') => $this->cond('macd', 'MACD', 'Bearish crossover today', 5, 80),
                (float) $t->macd > (float) $t->macd_signal => $this->cond('macd', 'MACD', 'MACD above its signal line', 65, 15),
                default => $this->cond('macd', 'MACD', 'MACD below its signal line', 15, 60),
            };
        }

        if ($t->stoch_k !== null && $t->stoch_d !== null) {
            $out[] = match (true) {
                $has('stoch_bull_cross') => $this->cond('stochastic', 'Stochastic', '%K crossed above %D while oversold', 75, 5),
                $has('stoch_bear_cross') => $this->cond('stochastic', 'Stochastic', '%K crossed below %D while overbought', 5, 75),
                (float) $t->stoch_k > (float) $t->stoch_d => $this->cond('stochastic', 'Stochastic', '%K above %D', 60, 15),
                default => $this->cond('stochastic', 'Stochastic', '%K below %D', 20, 50),
            };
        }

        if ($t->sma_20 !== null && $t->sma_50 !== null) {
            $out[] = match (true) {
                $has('sma_20_50_bull_cross') => $this->cond('ma_cross', 'MA20 vs MA50', 'MA20 crossed above MA50 today', 80, 5),
                $has('sma_20_50_bear_cross') => $this->cond('ma_cross', 'MA20 vs MA50', 'MA20 crossed below MA50 today', 5, 80),
                (float) $t->sma_20 > (float) $t->sma_50 => $this->cond('ma_cross', 'MA20 vs MA50', 'MA20 above MA50', 65, 15),
                default => $this->cond('ma_cross', 'MA20 vs MA50', 'MA20 below MA50', 15, 60),
            };
        }

        if ($has('golden_cross')) {
            $out[] = $this->cond('golden_cross', 'Golden / death cross', 'Golden cross confirmed', 80, 5);
        } elseif ($has('death_cross')) {
            $out[] = $this->cond('golden_cross', 'Golden / death cross', 'Death cross confirmed', 5, 80);
        }

        return $out;
    }

    // ---------------------------------------------------------------------- trend

    private function trend(array $c): array
    {
        $t = $c['today'];
        $close = $c['close'];
        $out = [];

        foreach ([20, 50, 200] as $period) {
            $sma = $t->{'sma_'.$period};
            if ($close !== null && $sma !== null) {
                $out[] = $close > (float) $sma
                    ? $this->cond('above_ma'.$period, "Price vs MA{$period}", "Price above MA{$period}", 80, 10)
                    : $this->cond('above_ma'.$period, "Price vs MA{$period}", "Price below MA{$period}", 10, 65);
            }
        }

        if ($t->sma_50 !== null && $t->sma_200 !== null) {
            $out[] = (float) $t->sma_50 > (float) $t->sma_200
                ? $this->cond('ma50_ma200', 'MA50 vs MA200', 'MA50 above MA200', 80, 10)
                : $this->cond('ma50_ma200', 'MA50 vs MA200', 'MA50 below MA200', 10, 65);
        }

        $earlier = $c['earlier'];
        if ($t->sma_50 !== null && $earlier?->sma_50 !== null) {
            $out[] = (float) $t->sma_50 > (float) $earlier->sma_50
                ? $this->cond('ma50_slope', 'MA50 direction', 'MA50 rising over 10 sessions', 75, 10)
                : $this->cond('ma50_slope', 'MA50 direction', 'MA50 falling over 10 sessions', 10, 60);
        }

        if ($t->adx_14 !== null && $t->plus_di_14 !== null && $t->minus_di_14 !== null) {
            $adx = (float) $t->adx_14;
            $up = (float) $t->plus_di_14 > (float) $t->minus_di_14;
            $out[] = match (true) {
                $adx >= 25 && $up => $this->cond('adx', 'ADX / DI', sprintf('Strong uptrend (ADX %.0f)', $adx), 85, 5),
                $adx >= 25 => $this->cond('adx', 'ADX / DI', sprintf('Strong downtrend (ADX %.0f)', $adx), 5, 85),
                $adx >= 20 && $up => $this->cond('adx', 'ADX / DI', sprintf('Weak uptrend (ADX %.0f)', $adx), 55, 20),
                $adx >= 20 => $this->cond('adx', 'ADX / DI', sprintf('Weak downtrend (ADX %.0f)', $adx), 20, 55),
                default => $this->cond('adx', 'ADX / DI', sprintf('No real trend (ADX %.0f)', $adx), 25, 25),
            };
        }

        return $out;
    }

    // ------------------------------------------------------------------- momentum

    private function momentum(array $c): array
    {
        $t = $c['today'];
        $y = $c['yesterday'];
        $out = [];

        if ($t->macd_histogram !== null && $y?->macd_histogram !== null) {
            $positive = (float) $t->macd_histogram > 0;
            $rising = (float) $t->macd_histogram > (float) $y->macd_histogram;
            $out[] = match (true) {
                $positive && $rising => $this->cond('macd_hist', 'MACD histogram', 'Positive and rising', 80, 5),
                $positive => $this->cond('macd_hist', 'MACD histogram', 'Positive but fading', 45, 25),
                $rising => $this->cond('macd_hist', 'MACD histogram', 'Negative but recovering', 35, 30),
                default => $this->cond('macd_hist', 'MACD histogram', 'Negative and falling', 5, 80),
            };
        }

        if ($t->rsi_14 !== null && $y?->rsi_14 !== null) {
            $strong = (float) $t->rsi_14 >= 50;
            $rising = (float) $t->rsi_14 > (float) $y->rsi_14;
            $out[] = match (true) {
                $strong && $rising => $this->cond('rsi_direction', 'RSI direction', 'Above 50 and rising', 70, 10),
                $strong => $this->cond('rsi_direction', 'RSI direction', 'Above 50 but falling', 40, 25),
                $rising => $this->cond('rsi_direction', 'RSI direction', 'Below 50 but rising', 40, 25),
                default => $this->cond('rsi_direction', 'RSI direction', 'Below 50 and falling', 10, 70),
            };
        }

        if ($c['close'] !== null && $c['close_10d_ago'] !== null && $c['close_10d_ago'] > 0) {
            $roc = ($c['close'] / $c['close_10d_ago'] - 1) * 100;
            $out[] = match (true) {
                $roc > 5 => $this->cond('roc', '10-day change', sprintf('%+.1f%%', $roc), 75, 10),
                $roc >= 0 => $this->cond('roc', '10-day change', sprintf('%+.1f%%', $roc), 55, 15),
                $roc >= -5 => $this->cond('roc', '10-day change', sprintf('%+.1f%%', $roc), 25, 40),
                default => $this->cond('roc', '10-day change', sprintf('%+.1f%%', $roc), 10, 70),
            };
        }

        return $out;
    }

    // --------------------------------------------------------------------- volume

    private function volume(array $c): array
    {
        $t = $c['today'];

        if ($t->volume_ratio === null || $c['close'] === null || $c['prev_close'] === null) {
            return [];
        }

        $ratio = (float) $t->volume_ratio;
        $label = sprintf('%.1fx average volume', $ratio);

        if ($ratio < 1) {
            return [$this->cond('volume', 'Volume', $label.' — thin, little conviction', 25, 15)];
        }
        if ($c['close'] === $c['prev_close']) {
            return [$this->cond('volume', 'Volume', $label.' on an unchanged day', 30, 30)];
        }

        $up = $c['close'] > $c['prev_close'];

        return [match (true) {
            $ratio >= 1.5 && $up => $this->cond('volume', 'Volume', $label.' on an up day', 85, 5),
            $ratio >= 1.5 => $this->cond('volume', 'Volume', $label.' on a down day', 5, 85),
            $up => $this->cond('volume', 'Volume', $label.' on an up day', 65, 10),
            default => $this->cond('volume', 'Volume', $label.' on a down day', 10, 65),
        }];
    }

    // ----------------------------------------------------------------------- risk

    private function risk(array $c): array
    {
        $t = $c['today'];
        $close = $c['close'];
        $out = [];

        if ($t->atr_percent !== null) {
            $atr = (float) $t->atr_percent;
            $label = sprintf('ATR %.1f%% of price', $atr);
            $out[] = match (true) {
                $atr <= 2 => $this->cond('atr', 'Volatility', $label.' — calm', 65, 10),
                $atr <= 4 => $this->cond('atr', 'Volatility', $label.' — moderate', 45, 20),
                $atr <= 6 => $this->cond('atr', 'Volatility', $label.' — high', 25, 40),
                default => $this->cond('atr', 'Volatility', $label.' — very high', 10, 60),
            };
        }

        if ($close !== null && $t->support_price !== null && $t->resistance_price !== null && $close > (float) $t->support_price) {
            $ratio = ((float) $t->resistance_price - $close) / ($close - (float) $t->support_price);
            $label = sprintf('%.1f : 1 to resistance vs support', $ratio);
            $out[] = match (true) {
                $ratio >= 2 => $this->cond('reward_risk', 'Reward / risk', $label, 75, 10),
                $ratio >= 1 => $this->cond('reward_risk', 'Reward / risk', $label, 50, 20),
                $ratio >= 0.5 => $this->cond('reward_risk', 'Reward / risk', $label, 25, 40),
                default => $this->cond('reward_risk', 'Reward / risk', $label, 10, 65),
            };
        }

        return $out;
    }

    // --------------------------------------------------- fundamental / valuation

    private function fundamental(?StockFundamental $f): array
    {
        if ($f === null || $f->eps === null) {
            return [];
        }

        $eps = (float) $f->eps;

        if ($eps <= 0) {
            return [$this->cond('eps', 'Earnings', sprintf('Loss-making (EPS Rs. %.2f)', $eps), 5, 80)];
        }

        $out = [$this->cond('eps', 'Earnings', sprintf('Profitable (EPS Rs. %.2f)', $eps), 75, 10)];

        if ($f->book_value !== null && (float) $f->book_value > 0) {
            $roe = $eps / (float) $f->book_value * 100;
            $label = sprintf('Return on equity %.1f%%', $roe);
            $out[] = match (true) {
                $roe >= 12 => $this->cond('roe', 'Return on equity', $label, 85, 5),
                $roe >= 6 => $this->cond('roe', 'Return on equity', $label, 55, 15),
                default => $this->cond('roe', 'Return on equity', $label, 25, 40),
            };
        }

        return $out;
    }

    private function valuation(?StockFundamental $f): array
    {
        if ($f === null || $f->eps === null || $f->pe_ratio === null || $f->pbv === null) {
            return [];
        }

        $pbv = (float) $f->pbv;
        $pe = (float) $f->pe_ratio;
        $pbvLabel = sprintf('Price-to-book %.2f', $pbv);

        $out = [match (true) {
            $pbv < 1 => $this->cond('pbv', 'Price-to-book', $pbvLabel.' — below book value', 80, 5),
            $pbv <= 2 => $this->cond('pbv', 'Price-to-book', $pbvLabel, 55, 15),
            $pbv <= 4 => $this->cond('pbv', 'Price-to-book', $pbvLabel, 30, 35),
            default => $this->cond('pbv', 'Price-to-book', $pbvLabel.' — well above book value', 10, 65),
        }];

        $peLabel = sprintf('P/E %.1f', $pe);
        $out[] = match (true) {
            (float) $f->eps <= 0 || $pe <= 0 => $this->cond('pe', 'P/E ratio', 'No meaningful P/E (loss-making)', 5, 80),
            $pe < 10 => $this->cond('pe', 'P/E ratio', $peLabel.' — cheap', 80, 5),
            $pe <= 20 => $this->cond('pe', 'P/E ratio', $peLabel, 55, 15),
            $pe <= 40 => $this->cond('pe', 'P/E ratio', $peLabel, 30, 35),
            default => $this->cond('pe', 'P/E ratio', $peLabel.' — expensive', 10, 65),
        };

        return $out;
    }

    // ------------------------------------------------------------------ hold type

    /**
     * Six independent 0-100 scores for why a HOLD would be right; the highest names the hold. They are computed for
     * every day, but only meaningful (and only reported) when the decision is HOLD.
     *
     * @return array<string, float>
     */
    private function holdScores(array $c, array $categories, array $final): array
    {
        $t = $c['today'];
        $close = $c['close'];

        // Long-term strength: above the 200-day, 50-day above 200-day, and a bullish fundamental view.
        $strength = [];
        if ($close !== null && $t->sma_200 !== null) {
            $strength[] = $close > (float) $t->sma_200 ? 100 : 0;
        }
        if ($t->sma_50 !== null && $t->sma_200 !== null) {
            $strength[] = (float) $t->sma_50 > (float) $t->sma_200 ? 100 : 0;
        }
        if ($categories['fundamental'] !== null) {
            $strength[] = $categories['fundamental']['buy'];
        }

        // Consolidation: no real trend (low ADX).
        $consolidation = $t->adx_14 !== null ? $this->clamp(100 - 2.5 * (float) $t->adx_14) : 40.0;

        // Waiting for confirmation: a dip-buy fired without a bounce, or buyers and sellers are evenly matched.
        $confirmation = $c['dip_suppressed'] ? 90.0 : (abs($final['buy'] - $final['sell']) < 15 ? 60.0 : 20.0);

        // Profit protection: trading near the top of its 52-week range.
        $protection = 0.0;
        if ($close !== null && $t->high_52w !== null && $t->low_52w !== null && (float) $t->high_52w > (float) $t->low_52w) {
            $position = ($close - (float) $t->low_52w) / ((float) $t->high_52w - (float) $t->low_52w);
            $protection = $this->clamp($position > 0.8 ? 60 + ($position - 0.8) * 200 : $position * 50);
        }

        // Temporary weakness: below the short averages while the longer trend is still intact.
        $base = $t->sma_200 ?? $t->sma_50;
        $weakness = 10.0;
        if ($close !== null && $base !== null && $close > (float) $base) {
            $weakness = match (true) {
                $t->sma_20 !== null && $t->sma_50 !== null && $close < (float) $t->sma_20 && $close < (float) $t->sma_50 => 80.0,
                $t->sma_20 !== null && $close < (float) $t->sma_20 => 60.0,
                default => 15.0,
            };
        }

        // Overbought: RSI well above 50.
        $overbought = $t->rsi_14 !== null ? $this->clamp(((float) $t->rsi_14 - 50) * 4) : 0.0;

        return [
            'long_term' => round($strength === [] ? 0 : array_sum($strength) / count($strength), 1),
            'consolidation' => round($consolidation, 1),
            'wait_confirmation' => $confirmation,
            'profit_protection' => round($protection, 1),
            'temporary_weakness' => $weakness,
            'overbought' => round($overbought, 1),
        ];
    }

    // -------------------------------------------------------------------- helpers

    /** One condition: BUY and SELL % given, HOLD is the rest, so the three always add to 100. */
    private function cond(string $key, string $label, string $result, float|int $buy, float|int $sell): array
    {
        return ['key' => $key, 'label' => $label, 'result' => $result, 'buy' => (float) $buy, 'sell' => (float) $sell, 'hold' => (float) (100 - $buy - $sell)];
    }

    /**
     * @param  list<array<string, mixed>>  $conditions
     * @return ?array{buy: float, sell: float, hold: float}
     */
    private function average(array $conditions): ?array
    {
        if ($conditions === []) {
            return null;
        }

        $n = count($conditions);

        return [
            'buy' => round(array_sum(array_column($conditions, 'buy')) / $n, 2),
            'sell' => round(array_sum(array_column($conditions, 'sell')) / $n, 2),
            'hold' => round(array_sum(array_column($conditions, 'hold')) / $n, 2),
        ];
    }

    /** @param array<string, float> $scores */
    private function sortedDesc(array $scores): array
    {
        arsort($scores);

        return $scores;
    }

    private function clamp(float $value, float $min = 0.0, float $max = 100.0): float
    {
        return max($min, min($max, $value));
    }
}
