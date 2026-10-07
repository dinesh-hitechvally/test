<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Casts\Attribute;

#[Fillable(['stock_id', 'trade_date', 'signal', 'score', 'reasons', 'rule_keys', 'price_at_signal'])]
class Signal extends Model
{
    protected function casts(): array
    {
        return [
            'trade_date' => 'date:Y-m-d',
            'score' => 'decimal:4',
            'reasons' => 'array',
            'rule_keys' => 'array',
            'price_at_signal' => 'decimal:4',
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
