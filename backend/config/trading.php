<?php

/**
 * Rules a BUY signal has to pass before it becomes a buy order
 * (App\Services\Portfolio\BuyOrderService). A signal alone never buys.
 */
return [
    // Signal gate: only these signals, at or above this -1..1 score, on a signal no older than this many days.
    'min_score' => 0.5,
    'signal_max_age_days' => 3,

    // A pending order lapses (status 'expired') after this many days, freeing its reserved cash and position slot.
    'order_valid_days' => 5,

    // Risk gate.
    'min_risk_reward' => 1.5,
    'stop_buffer_pct' => 0.5,      // stop sits this % below support, so a wick into support doesn't stop us out
    'max_stop_distance_pct' => 10, // refuse trades whose stop is further than this % under entry

    // Portfolio gate.
    'max_positions' => 10,         // open holdings + pending orders
    'max_position_pct' => 20,      // one position's cost, as % of portfolio equity (cash + holdings)

    // Position sizing.
    'risk_per_trade_pct' => 1,     // max loss at the stop, as % of portfolio equity
    'fee_pct' => 0.4,              // broker + SEBON + DP, added to the cost of a buy

    // Sell rules (App\Services\Portfolio\SellSignalService).
    'trailing_stop_pct' => 6,              // trailing stop sits this % of average cost below the highest close; starts once that is at/above cost
    'breakdown_min_volume_ratio' => 1.0,   // breakdown needs volume above this multiple of its 20-day average
];
