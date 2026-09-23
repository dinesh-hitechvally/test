<?php

namespace App\Services\MarketData;

/**
 * The canonical list of individual buy/sell conditions the signal engine
 * evaluates — single source of truth for both SignalGeneratorService (which
 * tags each fired condition with its key) and the rule scanner (which lets a
 * user pick a subset of these keys to filter on). Keeping this as data
 * rather than duplicating key strings in both places avoids the two ever
 * drifting apart.
 */
class SignalRules
{
    public const RULES = [
        'golden_cross' => ['label' => 'Golden Cross — SMA50 crossed above SMA200', 'direction' => 'bullish'],
        'death_cross' => ['label' => 'Death Cross — SMA50 crossed below SMA200', 'direction' => 'bearish'],
        'sma_20_50_bull_cross' => ['label' => 'SMA20 crossed above SMA50', 'direction' => 'bullish'],
        'sma_20_50_bear_cross' => ['label' => 'SMA20 crossed below SMA50', 'direction' => 'bearish'],
        'rsi_oversold' => ['label' => 'RSI Oversold (< 30)', 'direction' => 'bullish'],
        'rsi_overbought' => ['label' => 'RSI Rolled Over From Overbought (> 70)', 'direction' => 'bearish'],
        'macd_bull_cross' => ['label' => 'MACD Bullish Crossover', 'direction' => 'bullish'],
        'macd_bear_cross' => ['label' => 'MACD Bearish Crossover', 'direction' => 'bearish'],
        'bb_lower_touch' => ['label' => 'Price at/below Lower Bollinger Band', 'direction' => 'bullish'],
        'bb_upper_touch' => ['label' => 'Price Rejected From Upper Bollinger Band', 'direction' => 'bearish'],
        'valuation_loss' => ['label' => 'Reporting a Loss (Negative EPS)', 'direction' => 'bearish'],
        'valuation_undervalued' => ['label' => 'Trading Below Book Value (PBV < 1)', 'direction' => 'bullish'],
        'valuation_overvalued' => ['label' => 'Trading Well Above Book Value (PBV > 4)', 'direction' => 'bearish'],
        'valuation_cheap_pe' => ['label' => 'Cheap Earnings Multiple (P/E < 10)', 'direction' => 'bullish'],
        'valuation_expensive_pe' => ['label' => 'Expensive Earnings Multiple (P/E > 40)', 'direction' => 'bearish'],
    ];

    public static function isValidKey(string $key): bool
    {
        return array_key_exists($key, self::RULES);
    }

    public static function label(string $key): string
    {
        return self::RULES[$key]['label'] ?? $key;
    }
}
