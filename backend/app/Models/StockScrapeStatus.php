<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per stock, holding every external-fetch pipeline's status —
 * relocated off the `stocks` table so a stock's own row only ever describes
 * the stock itself, not scraping-pipeline bookkeeping. Each source (history,
 * sector, dividend) gets its own error/error_at columns rather than one
 * shared pair, since a stock can legitimately be failing on one source while
 * succeeding on another, and each has different "success" semantics (see
 * the migration that created this table).
 */
#[Fillable([
    'stock_id',
    'history_fetched_at', 'history_error', 'history_error_at',
    'sector_error', 'sector_error_at',
    'dividend_fetched_at', 'dividend_error', 'dividend_error_at',
])]
class StockScrapeStatus extends Model
{
    protected function casts(): array
    {
        return [
            'history_fetched_at' => 'datetime',
            'history_error_at' => 'datetime',
            'sector_error_at' => 'datetime',
            'dividend_fetched_at' => 'datetime',
            'dividend_error_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Stock, $this> */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function markHistoryFetched(): void
    {
        $this->update(['history_fetched_at' => now(), 'history_error' => null, 'history_error_at' => null]);
    }

    public function flagHistoryError(string $message): void
    {
        $this->update(['history_error' => $message, 'history_error_at' => now()]);
    }

    public function clearSectorError(): void
    {
        if ($this->sector_error !== null) {
            $this->update(['sector_error' => null, 'sector_error_at' => null]);
        }
    }

    public function flagSectorError(string $message): void
    {
        $this->update(['sector_error' => $message, 'sector_error_at' => now()]);
    }

    public function markDividendFetched(): void
    {
        $this->update(['dividend_fetched_at' => now(), 'dividend_error' => null, 'dividend_error_at' => null]);
    }

    public function flagDividendError(string $message): void
    {
        $this->update(['dividend_error' => $message, 'dividend_error_at' => now()]);
    }
}
