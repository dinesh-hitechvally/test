<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'stock_id', 'eps', 'eps_fiscal_year', 'pe_ratio', 'book_value', 'pbv',
    'market_cap', 'shares_outstanding', 'one_year_yield_pct', 'fetched_at',
])]
class StockFundamental extends Model
{
    protected function casts(): array
    {
        return [
            'eps' => 'decimal:4',
            'pe_ratio' => 'decimal:4',
            'book_value' => 'decimal:4',
            'pbv' => 'decimal:4',
            'market_cap' => 'decimal:2',
            'shares_outstanding' => 'decimal:2',
            'one_year_yield_pct' => 'decimal:4',
            'fetched_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Stock, $this> */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
