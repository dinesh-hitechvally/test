<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'weight_technical', 'weight_fundamental', 'weight_trend', 'weight_momentum', 'weight_volume',
    'weight_risk', 'weight_valuation', 'min_pct', 'margin', 'guard_extremes',
])]
class SignalSetting extends Model
{
    // Keyed by user_id (no id column) and only updated_at is kept.
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public const CREATED_AT = null;

    protected function casts(): array
    {
        return ['guard_extremes' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
