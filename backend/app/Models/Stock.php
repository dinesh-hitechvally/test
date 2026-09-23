<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['symbol', 'nepse_security_id', 'company_name', 'sector_id', 'share_group', 'is_active'])]
class Stock extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Sector, $this> */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    /**
     * Every API response has always sent `sector` as a plain name string —
     * flatten the relation back into that shape here rather than making
     * every controller/frontend consumer switch to a nested {id, name}
     * object just because storage moved to a normalized FK.
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        $array['sector'] = $this->sector?->name;

        return $array;
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

    /** @return HasOne<AiStockOpinion, $this> */
    public function aiOpinion(): HasOne
    {
        return $this->hasOne(AiStockOpinion::class);
    }

    /** @return HasMany<Forecast, $this> */
    public function forecasts(): HasMany
    {
        return $this->hasMany(Forecast::class);
    }

    /** @return HasOne<Forecast, $this> */
    public function latestForecast(): HasOne
    {
        return $this->hasOne(Forecast::class)->latestOfMany('trade_date');
    }

    /** @return HasOne<StockFundamental, $this> */
    public function fundamental(): HasOne
    {
        return $this->hasOne(StockFundamental::class);
    }
}
