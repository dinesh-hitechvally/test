<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'stock_id', 'ratio', 'total_units', 'issue_price', 'opening_date',
    'closing_date', 'book_closure_date', 'listing_date', 'issue_manager', 'status',
])]
class RightShare extends Model
{
    protected function casts(): array
    {
        return [
            'total_units' => 'decimal:2',
            'issue_price' => 'decimal:4',
            'opening_date' => 'date:Y-m-d',
            'closing_date' => 'date:Y-m-d',
            'listing_date' => 'date:Y-m-d',
        ];
    }

    /** @return BelongsTo<Stock, $this> */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
