<?php

namespace App\Services\Portfolio;

/**
 * How many shares to buy — sized by what we can lose, not by a rupee amount.
 *
 *   max risk     = equity × risk per trade %
 *   by risk      = floor(max risk ÷ (entry − stop))
 *   by size cap  = floor(equity × max position % ÷ entry)
 *   by cash      = floor(free cash ÷ (entry × (1 + fee %)))
 *   quantity     = the smallest of the three
 *
 * Example: equity 1,000,000, 1% risk, entry 820, stop 775 → max risk 10,000,
 * risk/share 45 → 222 shares, costing 182,040. If free cash only covers 150
 * shares, the quantity is reduced to 150 (limited_by = cash).
 */
class PositionSizer
{
    /**
     * @return array{quantity: int, by_risk: int, by_cap: int, by_cash: int, limited_by: string, max_risk: float, risk_amount: float, position_value: float, fees: float}
     */
    public function size(float $equity, float $freeCash, float $entry, float $stop): array
    {
        $riskPerShare = $entry - $stop;
        $feeRate = (float) config('trading.fee_pct') / 100;
        $maxRisk = $equity * (float) config('trading.risk_per_trade_pct') / 100;

        $limits = [
            'risk' => $riskPerShare > 0 ? (int) floor($maxRisk / $riskPerShare) : 0,
            'size_cap' => $entry > 0 ? (int) floor($equity * (float) config('trading.max_position_pct') / 100 / $entry) : 0,
            'cash' => $entry > 0 ? (int) floor($freeCash / ($entry * (1 + $feeRate))) : 0,
        ];

        $quantity = max(0, min($limits));
        $positionValue = round($quantity * $entry, 4);

        return [
            'quantity' => $quantity,
            'by_risk' => $limits['risk'],
            'by_cap' => $limits['size_cap'],
            'by_cash' => $limits['cash'],
            'limited_by' => (string) array_search($quantity, $limits, true),
            'max_risk' => round($maxRisk, 4),
            'risk_amount' => round($quantity * $riskPerShare, 4),
            'position_value' => $positionValue,
            'fees' => round($positionValue * $feeRate, 4),
        ];
    }
}
