<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'stock_id', 'trade_date',
    'sma_20', 'sma_50', 'sma_100', 'sma_200', 'rsi_14',
    'macd', 'macd_signal', 'macd_histogram',
    'bb_upper', 'bb_lower', 'stoch_k', 'stoch_d', 'atr_14', 'atr_percent',
    'adx_14', 'plus_di_14', 'minus_di_14',
    'support_price', 'resistance_price', 'high_52w', 'low_52w', 'volume_ratio',
])]
class TechnicalIndicator extends Model
{
    /** Only updated_at is kept (see the migration). */
    public const CREATED_AT = null;

    protected function casts(): array
    {
        return [
            'trade_date' => 'date:Y-m-d',
        ];
    }

    /** @return BelongsTo<Stock, $this> */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
