<?php

namespace App\Services\MarketData;

use App\Models\Stock;
use App\Models\TechnicalIndicator;

class IndicatorRecalculationService
{
    public function __construct(private readonly TechnicalAnalysisService $ta) {}

    /**
     * Recompute and upsert every technical_indicators row for a stock's full
     * price history. Cheap enough to run in full each time given the dataset
     * size (a few thousand rows per stock at most).
     */
    public function recalculate(Stock $stock): int
    {
        $prices = $stock->dailyPrices()->orderBy('trade_date')->get();

        if ($prices->isEmpty()) {
            return 0;
        }

        $dates = $prices->pluck('trade_date')->map(fn ($d) => $d->toDateString())->all();
        $closes = $prices->pluck('close_price')->map(fn ($v) => (float) $v)->all();
        $highs = $prices->pluck('high_price')->map(fn ($v) => (float) $v)->all();
        $lows = $prices->pluck('low_price')->map(fn ($v) => (float) $v)->all();

        $sma20 = $this->ta->sma($closes, 20);
        $sma50 = $this->ta->sma($closes, 50);
        $sma100 = $this->ta->sma($closes, 100);
        $sma200 = $this->ta->sma($closes, 200);
        $ema12 = $this->ta->ema($closes, 12);
        $ema26 = $this->ta->ema($closes, 26);
        $rsi14 = $this->ta->rsi($closes, 14);
        $macd = $this->ta->macd($closes);
        $bb = $this->ta->bollingerBands($closes, 20, 2.0);
        $atr14 = $this->ta->atr($highs, $lows, $closes, 14);

        $rows = [];
        foreach ($dates as $i => $date) {
            $rows[] = [
                'stock_id' => $stock->id,
                'trade_date' => $date,
                'sma_20' => $sma20[$i],
                'sma_50' => $sma50[$i],
                'sma_100' => $sma100[$i],
                'sma_200' => $sma200[$i],
                'ema_12' => $ema12[$i],
                'ema_26' => $ema26[$i],
                'rsi_14' => $rsi14[$i],
                'macd' => $macd['macd'][$i],
                'macd_signal' => $macd['signal'][$i],
                'macd_histogram' => $macd['histogram'][$i],
                'bb_upper' => $bb['upper'][$i],
                'bb_middle' => $bb['middle'][$i],
                'bb_lower' => $bb['lower'][$i],
                'atr_14' => $atr14[$i],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Chunked — a stock with years of history times 17 columns/row can
        // exceed MySQL's ~65535 placeholder limit for a single statement
        // (confirmed hitting this for stocks listed since the mid-2000s).
        foreach (array_chunk($rows, 500) as $chunk) {
            TechnicalIndicator::upsert(
                $chunk,
                uniqueBy: ['stock_id', 'trade_date'],
                update: [
                    'sma_20', 'sma_50', 'sma_100', 'sma_200', 'ema_12', 'ema_26', 'rsi_14',
                    'macd', 'macd_signal', 'macd_histogram',
                    'bb_upper', 'bb_middle', 'bb_lower', 'atr_14', 'updated_at',
                ]
            );
        }

        return count($rows);
    }
}
