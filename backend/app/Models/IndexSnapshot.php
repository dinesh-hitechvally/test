<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'index_name', 'trade_date', 'close', 'high', 'low', 'previous_close',
    'change', 'change_pct', 'fifty_two_week_high', 'fifty_two_week_low',
])]
class IndexSnapshot extends Model
{
    protected function casts(): array
    {
        return [
            'trade_date' => 'date:Y-m-d',
            'close' => 'decimal:4',
            'high' => 'decimal:4',
            'low' => 'decimal:4',
            'previous_close' => 'decimal:4',
            'change' => 'decimal:4',
            'change_pct' => 'decimal:4',
            'fifty_two_week_high' => 'decimal:4',
            'fifty_two_week_low' => 'decimal:4',
        ];
    }
}
