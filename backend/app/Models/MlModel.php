<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name', 'horizon_days', 'train_samples', 'test_samples',
    'accuracy', 'baseline_accuracy', 'precision', 'recall', 'f1', 'stocks_used', 'model_path', 'trained_at',
])]
class MlModel extends Model
{
    protected function casts(): array
    {
        return [
            'trained_at' => 'datetime',
            'accuracy' => 'decimal:4',
            'baseline_accuracy' => 'decimal:4',
            'precision' => 'decimal:4',
            'recall' => 'decimal:4',
            'f1' => 'decimal:4',
        ];
    }

    /**
     * Whether the model actually beats just guessing the majority class —
     * the real bar for "is this worth showing at all."
     */
    public function beatsBaseline(): bool
    {
        return (float) $this->accuracy > (float) $this->baseline_accuracy;
    }
}
