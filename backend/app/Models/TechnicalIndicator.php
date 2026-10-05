<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'stock_id', 'trade_date',
    'sma_20', 'sma_50', 'sma_100', 'sma_200', 'ema_12', 'ema_26', 'rsi_14',
    'macd', 'macd_signal', 'macd_histogram',
    'bb_upper', 'bb_middle', 'bb_lower', 'bb_percent_b', 'stoch_k', 'stoch_d', 'atr_14', 'atr_percent',
    'adx_14', 'plus_di_14', 'minus_di_14',
    'support_price', 'resistance_price', 'high_52w', 'low_52w', 'volume_ratio',
])]
class TechnicalIndicator extends Model
{
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
