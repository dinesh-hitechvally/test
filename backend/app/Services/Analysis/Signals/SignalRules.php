<?php

namespace App\Services\Analysis\Signals;

/**
 * The canonical list of individual buy/sell conditions the signal engine
 * evaluates — single source of truth for both SignalGeneratorService (which
 * tags each fired condition with its key and scores it by its weight) and
 * the rule scanner (which lets a user pick a subset of these keys to filter
 * on). Keeping this as data rather than duplicating key strings in both
 * places avoids the two ever drifting apart.
 *
 * 'weight' is how many raw points a rule adds to the signal score when it
 * fires. Only the four mean-reversion rules carry weight: a per-rule
 * walk-forward backtest (30-day horizon, ~256k stock-days) found they're
 * the only ones that beat the baseline — RSI oversold +2.1 points,
 * %B ≤ 0 +2.3, RSI rollover +1.0 — while every trend-following crossover
 * lost to it (golden cross -3.6, MACD bear -3.3, SMA20/50 bear -2.3,
 * stochastic bull -0.9 / bear -0.6, ...). NEPSE stocks at this horizon
 * tend to snap back rather than keep trending.
 *
 * Weight-0 rules are still detected and recorded (reasons, rule_keys, the
 * rule scanner), just not counted toward buy/sell — context, not a vote.
 * Valuation rules are also weight 0 here because they're applied
 * separately, as a bounded tilt on the latest row only (see
 * SignalGeneratorService::valuationTilt()).
 */
class SignalRules
{
    public const RULES = [
        'golden_cross' => ['label' => 'Golden Cross — SMA50 crossed above SMA200', 'direction' => 'bullish', 'weight' => 0],
        'death_cross' => ['label' => 'Death Cross — SMA50 crossed below SMA200', 'direction' => 'bearish', 'weight' => 0],
        'sma_20_50_bull_cross' => ['label' => 'SMA20 crossed above SMA50', 'direction' => 'bullish', 'weight' => 0],
        'sma_20_50_bear_cross' => ['label' => 'SMA20 crossed below SMA50', 'direction' => 'bearish', 'weight' => 0],
        'rsi_oversold' => ['label' => 'RSI Oversold (< 30)', 'direction' => 'bullish', 'weight' => 1],
        'rsi_overbought' => ['label' => 'RSI Rolled Over From Overbought (> 70)', 'direction' => 'bearish', 'weight' => -1],
        'macd_bull_cross' => ['label' => 'MACD Bullish Crossover', 'direction' => 'bullish', 'weight' => 0],
        'macd_bear_cross' => ['label' => 'MACD Bearish Crossover', 'direction' => 'bearish', 'weight' => 0],
        'bb_lower_touch' => ['label' => 'Bollinger %B at/below 0 (lower band)', 'direction' => 'bullish', 'weight' => 1],
        'bb_upper_touch' => ['label' => 'Bollinger %B rejected from above 1 (upper band)', 'direction' => 'bearish', 'weight' => -1],
        'stoch_bull_cross' => ['label' => 'Stochastic %K crossed above %D while oversold (< 20)', 'direction' => 'bullish', 'weight' => 0],
        'stoch_bear_cross' => ['label' => 'Stochastic %K crossed below %D while overbought (> 80)', 'direction' => 'bearish', 'weight' => 0],
        'valuation_loss' => ['label' => 'Reporting a Loss (Negative EPS)', 'direction' => 'bearish', 'weight' => 0],
        'valuation_undervalued' => ['label' => 'Trading Below Book Value (PBV < 1)', 'direction' => 'bullish', 'weight' => 0],
        'valuation_overvalued' => ['label' => 'Trading Well Above Book Value (PBV > 4)', 'direction' => 'bearish', 'weight' => 0],
        'valuation_cheap_pe' => ['label' => 'Cheap Earnings Multiple (P/E < 10)', 'direction' => 'bullish', 'weight' => 0],
        'valuation_expensive_pe' => ['label' => 'Expensive Earnings Multiple (P/E > 40)', 'direction' => 'bearish', 'weight' => 0],
    ];

    public static function isValidKey(string $key): bool
    {
        return array_key_exists($key, self::RULES);
    }

    public static function label(string $key): string
    {
        return self::RULES[$key]['label'] ?? $key;
    }

    /** @param string[] $keys rule keys that fired on one day */
    public static function rawScore(array $keys): float
    {
        return (float) array_sum(array_map(fn ($key) => self::RULES[$key]['weight'] ?? 0, $keys));
    }
}
