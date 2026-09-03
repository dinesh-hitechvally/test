<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['portfolio_id', 'stock_id', 'type', 'quantity', 'price', 'fees', 'transaction_date', 'notes'])]
class PortfolioTransaction extends Model
{
    protected function casts(): array
    {
        return [
            'transaction_date' => 'date:Y-m-d',
            'quantity' => 'integer',
            'price' => 'decimal:4',
            'fees' => 'decimal:4',
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
