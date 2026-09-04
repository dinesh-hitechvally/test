<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user-set stop-loss / take-profit level for one (portfolio, stock) pair.
 * Deliberately independent of the transaction ledger — set once, edited
 * anytime, and never recomputed the way holdings are.
 */
#[Fillable(['portfolio_id', 'stock_id', 'stop_loss', 'target_price', 'notes'])]
class PositionTarget extends Model
{
    protected function casts(): array
    {
        return [
            'stop_loss' => 'decimal:4',
            'target_price' => 'decimal:4',
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
