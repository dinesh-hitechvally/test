<?php

namespace App\GraphQL\Resolvers;

use App\Models\SignalBreakdown;

class SignalResolver
{
    /**
     * Signal.breakdown: how that day's decision was reached. signal_breakdowns is keyed by stock + trade date (like
     * signals), and the signal reaches here as a plain array, so it is looked up by those two values. Returns the
     * model so its category_scores / hold_scores accessors serve the grouped shape.
     *
     * @param  array<string, mixed>  $signal
     */
    public function breakdown(array $signal): ?SignalBreakdown
    {
        if (empty($signal['stock_id']) || empty($signal['trade_date'])) {
            return null;
        }

        return SignalBreakdown::query()
            ->where('stock_id', $signal['stock_id'])
            ->whereDate('trade_date', substr((string) $signal['trade_date'], 0, 10))
            ->first();
    }
}
