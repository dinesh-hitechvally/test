<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['stock_id', 'verdict', 'confidence', 'reasoning', 'generated_at', 'error', 'error_at'])]
class AiStockOpinion extends Model
{
    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'error_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Stock, $this> */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
