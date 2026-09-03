<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['stock_id', 'trade_date', 'open_price', 'high_price', 'low_price', 'close_price', 'volume', 'turnover'])]
class DailyPrice extends Model
{
    protected function casts(): array
    {
        return [
            'trade_date' => 'date:Y-m-d',
            'open_price' => 'decimal:4',
            'high_price' => 'decimal:4',
            'low_price' => 'decimal:4',
            'close_price' => 'decimal:4',
            'volume' => 'integer',
            'turnover' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Stock, $this> */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
