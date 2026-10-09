<?php

/**
 * How a stock's daily BUY / SELL / HOLD decision is reached (App\Services\Analysis\Signals).
 *
 * The decision is an OUTPUT, not the starting point:
 *   1. every individual condition (price above MA20, RSI zone, MACD, volume, ...) gets its own BUY / SELL / HOLD %
 *   2. conditions in a category are averaged into that category's BUY / SELL / HOLD %
 *   3. categories are combined with the weights below into the final BUY / SELL / HOLD %
 *   4. the decision rule turns the final percentages into BUY, SELL or HOLD
 */
// These are the SYSTEM defaults: what the cron-generated signals, the dashboard, reports and backtests use, and what a
// user sees until they save their own in Settings → Signal Settings (weights, minimum %, margin and guard are per user,
// stored in signal_settings; stretch_pct stays global because it changes how the stored percentages are produced).
return [
    // Weights should add up to 100. A category with no data that day is left out and the rest are re-weighted.
    'weights' => [
        'technical' => 25,   // RSI, Bollinger, MACD, stochastic, MA20/50 and golden/death cross (dip-buys only once a bounce is confirmed)
        'fundamental' => 25, // profitable? return on equity (latest data only)
        'trend' => 15,       // price vs MA20/50/200, MA50 slope, ADX / DI
        'momentum' => 10,    // MACD histogram, RSI direction, 10-day change
        'volume' => 10,      // participation behind the day's move
        'risk' => 10,        // volatility (ATR) and room to resistance vs support
        'valuation' => 5,    // P/E and price-to-book (latest data only)
    ],

    // Decision rule: final BUY % at or above this is BUY, final SELL % at or above it is SELL, otherwise HOLD.
    'decision_min_pct' => 50,

    // ...and the winning side must also lead the other side by at least this many points (0 = no extra margin).
    'decision_margin' => 0,

    // Never act against an extreme reading: a SELL while the price is oversold (RSI under 30, or on/below the lower
    // Bollinger band) is selling the low, and a BUY while it is overbought (RSI over 70, or on/above the upper band)
    // is buying the high. Such a day is a HOLD instead, with the reason shown.
    'guard_extremes' => true,

    // A price this many % above (or below) its 50-day average is stretched and tends to snap back; it then counts
    // in the Risk category against buying the stretch (or against selling it). null = off.
    'stretch_pct' => null,
];
