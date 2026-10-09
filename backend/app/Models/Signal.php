<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Casts\Attribute;

#[Fillable(['stock_id', 'trade_date', 'signal', 'score', 'reasons', 'rule_keys'])]
class Signal extends Model
{
    /** Only updated_at is kept (see the migration). */
    public const CREATED_AT = null;

    protected function casts(): array
    {
        return [
            'trade_date' => 'date:Y-m-d',
            'score' => 'decimal:4',
            'reasons' => 'array',
            'rule_keys' => 'array',
        ];
    }

    /**
     * How this day's decision was reached (signal_breakdowns is keyed by stock + trade date, like this table).
     * Read as an attribute — a composite key cannot be eager-loaded as a relation.
     *
     * @return Attribute<?SignalBreakdown, never>
     */
    protected function breakdown(): Attribute
    {
        return Attribute::get(fn () => SignalBreakdown::query()
            ->where('stock_id', $this->stock_id)
            ->whereDate('trade_date', $this->trade_date?->toDateString())
            ->first());
    }

    /** @return BelongsTo<Stock, $this> */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
