<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Services\MarketData\MarketReportService;

class MarketController extends Controller
{
    public function screener(MarketReportService $reports)
    {
        $stocks = Stock::with(['sector', 'latestPrice', 'latestSignal', 'latestIndicator'])->orderBy('symbol')->get();
        $changes = $reports->priceChanges();

        $stocks->each(function ($stock) use ($changes) {
            $stock->change_pct = $changes->get($stock->id)['change_pct'] ?? null;
        });

        return response()->json($stocks);
    }

    public function fiftyTwoWeek(MarketReportService $reports)
    {
        $stocks = Stock::with('sector')->orderBy('symbol')->get();
        $ranges = $reports->fiftyTwoWeekRange();

        $result = $stocks->map(function ($stock) use ($ranges) {
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
        })->filter()->values();

        return response()->json($result);
    }
}
