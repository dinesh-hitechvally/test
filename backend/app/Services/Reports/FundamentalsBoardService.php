<?php

namespace App\Services\Reports;

use App\Models\StockFundamental;
use Illuminate\Support\Collection;

/**
 * The Fundamental Analysis page: every stock that has fundamentals (EPS, P/E, book value, P/BV, market cap, one-year
 * yield from MeroLagani) with return on equity worked out and the latest price beside them. The page filters it.
 */
class FundamentalsBoardService
{
    /** @return Collection<int, array<string, mixed>> */
    public function all(): Collection
    {
        return StockFundamental::query()
            ->with(['stock.sector', 'stock.latestPrice'])
            ->get()
            ->filter(fn (StockFundamental $f) => $f->stock !== null)
            ->map(function (StockFundamental $f) {
                $eps = $f->eps !== null ? (float) $f->eps : null;
                $book = $f->book_value !== null ? (float) $f->book_value : null;

                return [
                    'stock_id' => $f->stock_id,
                    'symbol' => $f->stock->symbol,
                    'company_name' => $f->stock->company_name,
                    'sector' => $f->stock->sector?->name,
                    'close' => $f->stock->latestPrice?->close_price,
                    'eps' => $eps,
                    'eps_fiscal_year' => $f->eps_fiscal_year,
                    'pe_ratio' => $f->pe_ratio !== null ? (float) $f->pe_ratio : null,
                    'book_value' => $book,
                    'pbv' => $f->pbv !== null ? (float) $f->pbv : null,
                    // Return on equity: what a year's earnings are, as a % of what the company is worth on its books.
                    'roe_pct' => $eps !== null && $book !== null && $book > 0 ? round($eps / $book * 100, 2) : null,
                    'market_cap' => $f->market_cap !== null ? (float) $f->market_cap : null,
                    'one_year_yield_pct' => $f->one_year_yield_pct !== null ? (float) $f->one_year_yield_pct : null,
                    'fetched_at' => $f->fetched_at?->toDateTimeString(),
                ];
            })
            ->sortBy('symbol')
            ->values();
    }
}
