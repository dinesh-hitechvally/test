<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'stock_id', 'fiscal_year', 'bonus_share_pct', 'cash_dividend_pct', 'total_dividend_pct',
    'announcement_date', 'distribution_date', 'book_closure_date', 'bonus_listing_date',
])]
class Dividend extends Model
{
    protected function casts(): array
    {
        return [
            'bonus_share_pct' => 'decimal:4',
            'cash_dividend_pct' => 'decimal:4',
            'total_dividend_pct' => 'decimal:4',
            'announcement_date' => 'date:Y-m-d',
            'distribution_date' => 'date:Y-m-d',
            'bonus_listing_date' => 'date:Y-m-d',
        ];
    }

    /** @return BelongsTo<Stock, $this> */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
