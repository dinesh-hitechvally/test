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

    /**
     * `ratio` is a free-text "held:new" string as declared by the company
     * (e.g. "10:5" = 5 new shares per 10 held, "1: 0.40" = 40 new per 100
     * held) — converts it to the plain % more shares an existing holder
     * gets, or null if it isn't in a parseable "x:y" shape.
     */
    public function percent(): ?float
    {
        if ($this->ratio === null || ! str_contains($this->ratio, ':')) {
            return null;
        }

        [$held, $new] = array_pad(explode(':', $this->ratio, 2), 2, null);
        $held = is_string($held) ? trim($held) : null;
        $new = is_string($new) ? trim($new) : null;

        if (! is_numeric($held) || ! is_numeric($new) || (float) $held === 0.0) {
            return null;
        }

        return round(((float) $new / (float) $held) * 100, 2);
    }
}
