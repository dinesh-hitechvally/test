<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['stock_id', 'generated_date', 'target_date', 'predicted_close', 'method'])]
class Forecast extends Model
{
    protected function casts(): array
    {
        return [
            'generated_date' => 'date:Y-m-d',
            'target_date' => 'date:Y-m-d',
            'predicted_close' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<Stock, $this> */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
