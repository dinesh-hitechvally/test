<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An approved plan to buy: what a BUY signal turned into after the risk,
 * portfolio and cash checks (BuyOrderService). It records the plan only —
 * the actual purchase is still entered as a portfolio transaction.
 */
#[Fillable(['portfolio_id', 'stock_id', 'signal_id', 'status', 'trade_date', 'signal_score', 'quantity', 'entry_price', 'stop_loss', 'target_price', 'risk_per_share', 'risk_amount', 'position_value', 'fees', 'risk_reward'])]
class BuyOrder extends Model
{
    protected function casts(): array
    {
        return [
            'trade_date' => 'date:Y-m-d',
            'signal_score' => 'decimal:4',
            'quantity' => 'integer',
            'entry_price' => 'decimal:4',
            'stop_loss' => 'decimal:4',
            'target_price' => 'decimal:4',
            'risk_per_share' => 'decimal:4',
            'risk_amount' => 'decimal:4',
            'position_value' => 'decimal:4',
            'fees' => 'decimal:4',
            'risk_reward' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Portfolio, $this> */
    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }

    /** @return BelongsTo<Stock, $this> */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
