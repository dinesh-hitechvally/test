<?php

/**
 * How a stock's daily BUY / SELL / HOLD decision is reached (App\Services\Analysis\Signals\SignalConditionScorer).
 *
 * The decision is an OUTPUT, not the starting point:
 *   1. every indicator gets its own BUY / SELL / HOLD % (adding to 100)
 *   2. the indicators are combined with the weights below (missing ones left out, the rest re-weighted to 100)
 *   3. the decision rules turn the final percentages into BUY, SELL or HOLD
 *   4. the entry guards stop a BUY at an overbought price and a SELL at an oversold one
 *
 * The indicators are grouped into categories (technical, trend, momentum, volume, risk, fundamental, valuation) only
 * to explain the result: the category percentages are averages of their indicators and do not change the decision.
 */
return [
    /*
    | Indicator-level weights. Total weight = 100.
    | Missing indicators are excluded and the remaining weights are normalized.
    | enabled = false leaves an indicator out entirely.
    */
    'indicators' => [
        // Built for a 3 to 30 day hold (short swing trades): the short and medium trend and the oscillators carry most
        // of the weight; the long-term and fundamental readings are only a filter.

        // Trend (30): the 20- and 50-day averages set the direction over this horizon, the 200-day MA only says which
        // side of the long trend the price is on. ADX says whether there is a trend to follow at all.
        'ma20' => ['weight' => 8, 'enabled' => true],
        'ma50' => ['weight' => 9, 'enabled' => true],
        'ma200' => ['weight' => 5, 'enabled' => true],
        'golden_death_cross' => ['weight' => 2, 'enabled' => true],
        'adx_di' => ['weight' => 6, 'enabled' => true],

        // Momentum and oscillators (47): what moves a price over days to weeks. MACD and RSI are the core pair; the
        // histogram and RSI direction repeat their parents, so they stay small.
        'macd' => ['weight' => 11, 'enabled' => true],
        'macd_histogram' => ['weight' => 5, 'enabled' => true],
        'rsi' => ['weight' => 11, 'enabled' => true],
        'rsi_direction' => ['weight' => 3, 'enabled' => true],
        'stochastic' => ['weight' => 5, 'enabled' => true],
        'bollinger_bands' => ['weight' => 6, 'enabled' => true],
        'price_change_10d' => ['weight' => 6, 'enabled' => true],

        // Participation (10): a move is trusted more when volume backs it.
        'volume_confirmation' => ['weight' => 10, 'enabled' => true],

        // Price levels (8): reward-to-risk between resistance and support is the entry-quality test for a swing trade.
        // ATR measures how much a price moves, not which way, so it is for sizing a stop, not for choosing BUY/SELL.
        'support_resistance' => ['weight' => 8, 'enabled' => true],
        'atr_risk' => ['weight' => 0, 'enabled' => false],

        // Fundamentals and valuation (5, latest day only): earnings and book value change quarterly, so they do not
        // time a 3 to 30 day trade. They only keep a loss-making or very expensive company from scoring as a clean buy.
        'roe_profitability' => ['weight' => 3, 'enabled' => true],
        'pe_ratio' => ['weight' => 1, 'enabled' => true],
        'price_to_book' => ['weight' => 1, 'enabled' => true],
    ],

    /*
    | Final decision rules.
    */
    'decision' => [
        'minimum_buy_pct' => 50,
        'minimum_sell_pct' => 50,

        // The winning side must exceed the opposing side by this many points.
        'minimum_margin_pct' => 10,

        // If neither BUY nor SELL qualifies, the answer is HOLD.
        'default' => 'HOLD',
    ],

    /*
    | Entry protection.
    */
    'guards' => [
        'enabled' => true,

        // Do not open a new BUY solely because price is rising when it is already overbought.
        'block_buy_when_overbought' => true,
        'overbought_rsi' => 70,

        // Do not SELL solely because price is falling when it is already oversold.
        'block_sell_when_oversold' => true,
        'oversold_rsi' => 30,

        // A blocked entry is let through when the trend confirms it: ADX at least 25, the +DI / -DI on the entry's
        // side and the MACD histogram on the same side. false = a blocked entry always stays a HOLD.
        'require_reversal_confirmation' => true,
    ],

    /*
    | Indicator correlation: related indicators must not dominate the result merely because they measure similar
    | market behavior. A group's combined weight is capped at max_group_multiple x its heaviest member (members are
    | scaled down together); the cap only bites where the group's members add up to more than that.
    */
    'correlation' => [
        'enabled' => true,
        'max_group_multiple' => 2.0,
        'groups' => [
            'macd_family' => ['macd', 'macd_histogram'],
            'rsi_family' => ['rsi', 'rsi_direction'],
            'moving_average_family' => ['ma20', 'ma50', 'ma200', 'golden_death_cross'],
        ],
    ],
];
