<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['stock_id', 'trade_date', 'next_close', 'reasons', 'method'])]
class Forecast extends Model
{
    protected function casts(): array
    {
        return [
            'trade_date' => 'date',
            'next_close' => 'decimal:4',
            'reasons' => 'array',
        ];
    }

    /** @return BelongsTo<Stock, $this> */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
