<?php

namespace App\Services\Reports;

use App\Models\Dividend;
use Illuminate\Support\Collection;

/**
 * The Market > Dividends page: every dividend / bonus declaration on record, across all stocks, newest first. The
 * page filters it (fiscal year, sector, cash / bonus, minimum %); the yield ranking lives in DividendReportService.
 */
class DividendListService
{
    /** @return Collection<int, array<string, mixed>> */
    public function all(): Collection
    {
        return Dividend::query()
            ->with('stock.sector')
            ->get()
            ->filter(fn (Dividend $d) => $d->stock !== null)
            ->map(fn (Dividend $d) => [
                'stock_id' => $d->stock_id,
                'symbol' => $d->stock->symbol,
                'company_name' => $d->stock->company_name,
                'sector' => $d->stock->sector?->name,
                'fiscal_year' => $d->fiscal_year,
                'bonus_share_pct' => $d->bonus_share_pct !== null ? (float) $d->bonus_share_pct : null,
                'cash_dividend_pct' => $d->cash_dividend_pct !== null ? (float) $d->cash_dividend_pct : null,
                'total_dividend_pct' => $d->total_dividend_pct !== null ? (float) $d->total_dividend_pct : null,
                'announcement_date' => $d->announcement_date?->toDateString(),
                'book_closure_date' => $d->book_closure_date,
                'distribution_date' => $d->distribution_date?->toDateString(),
                'bonus_listing_date' => $d->bonus_listing_date?->toDateString(),
            ])
            // Newest financial year first, then the most recent announcement, then the biggest payout.
            ->sortBy([
                ['fiscal_year', 'desc'],
                ['announcement_date', 'desc'],
                ['total_dividend_pct', 'desc'],
            ])
            ->values();
    }
}
