<?php

namespace App\Services\Reports;

use App\Models\ScrapeLog;
use App\Models\Stock;

/**
 * The Dashboard and Market report pages: market totals, breadth, movers,
 * signal mix and trend.
 */
class MarketReportService
{
    public function __construct(private readonly PriceStatisticsService $prices) {}

    /** The Market report page: dashboard totals plus sector performance and a 30-day trend. */
    public function marketOverview(): array
    {
        $summary = $this->dashboardSummary();

        return [
            'totals' => $summary['totals'],
            'signal_counts' => $summary['signal_counts'],
            'breadth' => $summary['breadth'],
            'movers' => $summary['movers'],
            'sector_performance' => $this->prices->sectorPerformance(),
            'trend' => $this->prices->trend(30),
        ];
    }

    public function dashboardSummary(): array
    {
        $changes = $this->prices->priceChanges();

        $stocks = Stock::with('latestSignal')->get();

        $signalCounts = [
            'buy' => 0, 'hold' => 0, 'sell' => 0,
        ];
        $stocksWithSignals = 0;

        foreach ($stocks as $stock) {
            if ($stock->latestSignal) {
                $signalCounts[$stock->latestSignal->signal]++;
                $stocksWithSignals++;
            }
        }

        $advancing = $changes->where('change_pct', '>', 0)->count();
        $declining = $changes->where('change_pct', '<', 0)->count();
        $unchanged = $changes->filter(fn ($c) => $c['change_pct'] === 0.0)->count();

        $withSymbols = $changes->map(function ($c) use ($stocks) {
            $stock = $stocks->firstWhere('id', $c['stock_id']);

            return $stock ? array_merge($c, [
                'symbol' => $stock->symbol,
                'company_name' => $stock->company_name,
            ]) : null;
        })->filter()->values();

        $ranked = $withSymbols->filter(fn ($c) => $c['change_pct'] !== null)->sortByDesc('change_pct')->values();

        return [
            'totals' => [
                'stocks' => $stocks->count(),
                'with_signals' => $stocksWithSignals,
            ],
            'last_scrape' => ScrapeLog::latest('created_at')->first(),
            'signal_counts' => $signalCounts,
            'breadth' => [
                'advancing' => $advancing,
                'declining' => $declining,
                'unchanged' => $unchanged,
            ],
            // Today's real per-sector move (avg % change, advancing/declining
            // counts) — same data as the Market Report's sector table, not a
            // stock-count tally, so this actually changes day to day instead
            // of sitting nearly static until a stock gets added/removed.
            'sector_breakdown' => $this->prices->sectorPerformance(),
            'movers' => [
                'gainers' => $ranked->take(5)->values(),
                'losers' => $ranked->reverse()->take(5)->values(),
            ],
        ];
    }
}
