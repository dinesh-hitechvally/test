<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['stock_id', 'trade_date', 'check_type', 'severity', 'message', 'detected_at', 'resolved_at'])]
class DataQualityFlag extends Model
{
    public const CREATED_AT = 'detected_at';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'trade_date' => 'date:Y-m-d',
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Stock, $this> */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
