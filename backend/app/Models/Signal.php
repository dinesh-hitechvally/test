<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['stock_id', 'trade_date', 'signal', 'score', 'reasons', 'rule_keys', 'price_at_signal'])]
class Signal extends Model
{
    protected function casts(): array
    {
        return [
            'trade_date' => 'date:Y-m-d',
            'score' => 'decimal:4',
            'reasons' => 'array',
            'rule_keys' => 'array',
            'price_at_signal' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<Stock, $this> */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
