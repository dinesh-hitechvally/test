<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per backtest run of NextCloseEstimatorService's formula — kept as
 * a history (like MlModel), not a single overwritten row, so accuracy over
 * time stays visible. Always beatsBaseline() === false as of the formula's
 * initial validation (MAPE 2.238% vs a 1.815% "assume no change" baseline,
 * ~50% direction accuracy across ~469K historical day-pairs) — shown to
 * users anyway, honestly labeled, same as MlModel's own underperformance is.
 */
#[Fillable(['sample_size', 'stocks_used', 'mape', 'naive_mape', 'direction_accuracy', 'computed_at'])]
class NextCloseAccuracyStat extends Model
{
    protected function casts(): array
    {
        return [
            'mape' => 'decimal:4',
            'naive_mape' => 'decimal:4',
            'direction_accuracy' => 'decimal:4',
            'computed_at' => 'datetime',
        ];
    }

    public function beatsBaseline(): bool
    {
        return (float) $this->mape < (float) $this->naive_mape;
    }
}
