<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['symbol', 'nepse_security_id', 'company_name', 'sector', 'share_group', 'face_value', 'is_active'])]
class Stock extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'face_value' => 'decimal:2',
        ];
    }

    /** @return HasOne<StockScrapeStatus, $this> */
    public function scrapeStatus(): HasOne
    {
        return $this->hasOne(StockScrapeStatus::class);
    }

    /**
     * Every fetch-status call site needs a row to write to, but most stocks
     * never had a scrape attempt yet — this creates it on first write
     * instead of every caller repeating firstOrCreate() themselves.
     */
    public function ensureScrapeStatus(): StockScrapeStatus
    {
        return $this->scrapeStatus()->firstOrCreate([]);
    }

    /**
     * Deliberately unordered — callers must specify direction explicitly.
     * Chaining a second orderBy('trade_date', ...) on top of one already
     * baked in here would silently no-op (same column, no ties to break),
     * so ordering only ever lives at the call site.
     *
     * @return HasMany<DailyPrice, $this>
     */
    public function dailyPrices(): HasMany
    {
        return $this->hasMany(DailyPrice::class);
    }

    /** @return HasMany<TechnicalIndicator, $this> */
    public function technicalIndicators(): HasMany
    {
        return $this->hasMany(TechnicalIndicator::class);
    }

    /** @return HasMany<Signal, $this> */
    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class);
    }

    /** @return HasMany<Dividend, $this> */
    public function dividends(): HasMany
    {
        return $this->hasMany(Dividend::class);
    }

    /** @return HasMany<RightShare, $this> */
    public function rightShares(): HasMany
    {
        return $this->hasMany(RightShare::class);
    }

    /** @return HasOne<Signal, $this> */
    public function latestSignal(): HasOne
    {
        return $this->hasOne(Signal::class)->latestOfMany('trade_date');
    }

    /** @return HasOne<DailyPrice, $this> */
    public function latestPrice(): HasOne
    {
        return $this->hasOne(DailyPrice::class)->latestOfMany('trade_date');
    }

    /** @return HasOne<TechnicalIndicator, $this> */
    public function latestIndicator(): HasOne
    {
        return $this->hasOne(TechnicalIndicator::class)->latestOfMany('trade_date');
    }
}
