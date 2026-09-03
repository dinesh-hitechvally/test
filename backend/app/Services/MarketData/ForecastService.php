<?php

namespace App\Services\MarketData;

use App\Models\Forecast;
use App\Models\Stock;
use Carbon\Carbon;

/**
 * Statistical price forecast using Holt's linear trend method (see
 * HoltForecastService) — a real, established forecasting technique, chosen
 * and validated over a naive straight-line fit. Its actual measured
 * out-of-sample accuracy (MAPE, directional accuracy) comes from
 * `forecast:backtest` / ForecastModel, not a hardcoded claim — this is a
 * statistical trend estimate, not investment advice, regardless of method.
 */
class ForecastService
{
    /** ~1 calendar month of NEPSE trading days (Mon-Fri, ~5 sessions/week). */
    private const TRADING_DAYS_AHEAD = 22;

    public function __construct(private readonly HoltForecastService $holt) {}

    public function generate(Stock $stock): int
    {
        $prices = $stock->dailyPrices()
            ->orderByDesc('trade_date')
            ->limit(HoltForecastService::DEFAULT_LOOKBACK)
            ->get()
            ->reverse()
            ->values();

        if ($prices->count() < 5) {
            return 0;
        }

        $closes = $prices->pluck('close_price')->map(fn ($v) => (float) $v)->all();
        $forecastValues = $this->holt->forecast($closes, self::TRADING_DAYS_AHEAD);

        $generatedDate = $prices->last()->trade_date;

        $rows = [];
        $cursor = Carbon::parse($generatedDate);

        foreach ($forecastValues as $predicted) {
            $cursor = $this->nextTradingDay($cursor);

            $rows[] = [
                'stock_id' => $stock->id,
                'generated_date' => $generatedDate->toDateString(),
                'target_date' => $cursor->toDateString(),
                'predicted_close' => round($predicted, 4),
                'method' => 'holt_linear_trend',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        Forecast::where('stock_id', $stock->id)
            ->where('generated_date', $generatedDate->toDateString())
            ->delete();

        Forecast::insert($rows);

        return count($rows);
    }

    /** NEPSE is closed Saturday and Sunday. */
    private function nextTradingDay(Carbon $from): Carbon
    {
        $next = $from->copy()->addDay();

        while ($next->isSaturday() || $next->isSunday()) {
            $next->addDay();
        }

        return $next;
    }
}
