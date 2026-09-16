<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['symbol', 'nepse_security_id', 'company_name', 'sector', 'share_group', 'face_value', 'is_active', 'history_fetched_at', 'scrape_error', 'scrape_error_source', 'scrape_error_at'])]
class Stock extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'face_value' => 'decimal:2',
            'history_fetched_at' => 'datetime',
            'scrape_error_at' => 'datetime',
        ];
    }

    /**
     * Marks this stock as currently having a data issue — set on a failed
     * per-stock fetch, cleared by clearScrapeError() the next time that
     * kind of fetch succeeds. Not a retry mechanism, just a visible flag
     * instead of a failure sitting silently in a log file.
     */
    public function flagScrapeError(string $source, string $message): void
    {
        $this->update([
            'scrape_error' => $message,
            'scrape_error_source' => $source,
            'scrape_error_at' => now(),
        ]);
    }

    public function clearScrapeError(): void
    {
        if ($this->scrape_error !== null) {
            $this->update(['scrape_error' => null, 'scrape_error_source' => null, 'scrape_error_at' => null]);
        }
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
