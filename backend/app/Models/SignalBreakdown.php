<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'stock_id', 'trade_date', 'buy_pct', 'sell_pct', 'hold_pct', 'hold_type', 'conditions',
    'hold_score_long_term', 'hold_score_consolidation', 'hold_score_wait_confirmation',
    'hold_score_profit_protection', 'hold_score_temporary_weakness', 'hold_score_overbought',
    'technical_buy', 'technical_sell', 'technical_hold',
    'fundamental_buy', 'fundamental_sell', 'fundamental_hold',
    'trend_buy', 'trend_sell', 'trend_hold',
    'momentum_buy', 'momentum_sell', 'momentum_hold',
    'volume_buy', 'volume_sell', 'volume_hold',
    'risk_buy', 'risk_sell', 'risk_hold',
    'valuation_buy', 'valuation_sell', 'valuation_hold',
])]
class SignalBreakdown extends Model
{
    // Keyed by (stock_id, trade_date): no id column, no timestamps.
    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    /** The categories that each have a {category}_buy / _sell / _hold column. */
    public const CATEGORIES = ['technical', 'fundamental', 'trend', 'momentum', 'volume', 'risk', 'valuation'];

    /** The hold reasons that each have a hold_score_{type} column; the highest is hold_type on a HOLD day. */
    public const HOLD_TYPES = ['long_term', 'consolidation', 'wait_confirmation', 'profit_protection', 'temporary_weakness', 'overbought'];

    protected function casts(): array
    {
        $casts = [
            'trade_date' => 'date:Y-m-d',
            'buy_pct' => 'float',
            'sell_pct' => 'float',
            'hold_pct' => 'float',
            'conditions' => 'array',
        ];

        foreach (self::HOLD_TYPES as $type) {
            $casts["hold_score_{$type}"] = 'float';
        }

        foreach (self::CATEGORIES as $category) {
            foreach (['buy', 'sell', 'hold'] as $side) {
                $casts["{$category}_{$side}"] = 'float';
            }
        }

        return $casts;
    }

    /**
     * The hold_score_* columns as { hold_type: score } — what the API reads.
     *
     * @return array<string, float>
     */
    protected function holdScores(): Attribute
    {
        return Attribute::get(function () {
            $scores = [];

            foreach (self::HOLD_TYPES as $type) {
                $scores[$type] = $this->{"hold_score_{$type}"};
            }

            return $scores;
        });
    }

    /**
     * The category columns as { category: { buy, sell, hold } | null } — what the API and the stock page read.
     *
     * @return array<string, ?array{buy: float, sell: float, hold: float}>
     */
    protected function categoryScores(): Attribute
    {
        return Attribute::get(function () {
            $scores = [];

            foreach (self::CATEGORIES as $category) {
                $scores[$category] = $this->{"{$category}_buy"} === null ? null : [
                    'buy' => $this->{"{$category}_buy"},
                    'sell' => $this->{"{$category}_sell"},
                    'hold' => $this->{"{$category}_hold"},
                ];
            }

            return $scores;
        });
    }

    /** @return BelongsTo<Stock, $this> */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
