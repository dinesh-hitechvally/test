<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'signal_type', 'horizon_days', 'sample_size', 'win_rate',
    'avg_forward_return_pct', 'baseline_win_rate', 'computed_at',
])]
class SignalAccuracyStat extends Model
{
    protected function casts(): array
    {
        return [
            'win_rate' => 'decimal:2',
            'avg_forward_return_pct' => 'decimal:4',
            'baseline_win_rate' => 'decimal:2',
            'computed_at' => 'datetime',
        ];
    }
}
