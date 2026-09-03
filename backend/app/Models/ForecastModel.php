<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'method', 'horizon_days', 'test_points', 'mape',
    'directional_accuracy', 'stocks_used', 'computed_at',
])]
class ForecastModel extends Model
{
    protected function casts(): array
    {
        return [
            'computed_at' => 'datetime',
            'mape' => 'decimal:4',
            'directional_accuracy' => 'decimal:4',
        ];
    }

    public function beatsCoinFlip(): bool
    {
        return (float) $this->directional_accuracy > 0.5;
    }
}
