<?php

namespace App\Services\Reports;

use App\Models\Stock;
use Illuminate\Support\Collection;

/** The Market pages: the full screener table and the 52-week high/low list. */
class ScreenerService
{
    public function __construct(private readonly PriceStatisticsService $prices) {}

    public function screener(): Collection
    {
        return $this->prices->withChangePct(
            Stock::with(['sector', 'latestPrice', 'latestSignal', 'latestIndicator'])->orderBy('symbol')->get()
        );
    }

    /** Every stock with a current price, and where it sits in its 52-week range. */
    public function fiftyTwoWeek(): Collection
    {
        $ranges = $this->prices->fiftyTwoWeekRange();

        return Stock::with('sector')->orderBy('symbol')->get()
            ->map(function ($stock) use ($ranges) {
                $range = $ranges->get($stock->id);

                if (! $range || $range['current_price'] === null) {
                    return null;
                }

                return [
                    'stock_id' => $stock->id,
                    'symbol' => $stock->symbol,
                    'company_name' => $stock->company_name,
                    'sector' => $stock->sector?->name,
                    'current_price' => $range['current_price'],
                    'high_52w' => $range['high_52w'],
                    'low_52w' => $range['low_52w'],
                    'pct_from_high' => $range['pct_from_high'],
                    'pct_from_low' => $range['pct_from_low'],
                ];
            })
            ->filter()
            ->values();
    }
}
