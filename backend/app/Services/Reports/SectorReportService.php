<?php

namespace App\Services\Reports;

use App\Models\Sector;
use App\Models\Stock;
use Illuminate\Support\Collection;

/** The Sector report pages: the sector list, and one sector's breakdown. */
class SectorReportService
{
    public function __construct(private readonly PriceStatisticsService $prices) {}

    /** Sectors that have at least one stock, largest first. */
    public function sectors(): Collection
    {
        return Sector::withCount('stocks')
            ->having('stocks_count', '>', 0)
            ->orderByDesc('stocks_count')
            ->get()
            ->map(fn ($sector) => ['sector' => $sector->name, 'stock_count' => $sector->stocks_count])
            ->values();
    }

    /** One sector's stocks, breadth, signal mix, movers and trend — null if it has no stocks. */
    public function sector(string $name): ?array
    {
        $stocks = Stock::whereHas('sector', fn ($q) => $q->where('name', $name))
            ->with(['sector', 'latestPrice', 'latestSignal'])
            ->orderBy('symbol')
            ->get();

        if ($stocks->isEmpty()) {
            return null;
        }

        $this->prices->withChangePct($stocks);
        $withPct = $stocks->filter(fn ($s) => $s->change_pct !== null);

        $signalCounts = ['strong_buy' => 0, 'buy' => 0, 'hold' => 0, 'sell' => 0, 'strong_sell' => 0];
        foreach ($stocks as $stock) {
            if ($stock->latestSignal) {
                $signalCounts[$stock->latestSignal->signal]++;
            }
        }

        $ranked = $withPct->sortByDesc('change_pct')->values();

        return [
            'sector' => $name,
            'totals' => [
                'stock_count' => $stocks->count(),
                'advancing' => $withPct->where('change_pct', '>', 0)->count(),
                'declining' => $withPct->where('change_pct', '<', 0)->count(),
            ],
            'signal_counts' => $signalCounts,
            'avg_change_pct' => $withPct->isNotEmpty() ? round($withPct->avg('change_pct'), 2) : null,
            'stocks' => $stocks->values(),
            'top_gainers' => $ranked->take(5)->values(),
            'top_losers' => $ranked->reverse()->take(5)->values(),
            'trend' => $this->prices->trend(30, $name),
        ];
    }
}
