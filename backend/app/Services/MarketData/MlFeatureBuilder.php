<?php

namespace App\Services\MarketData;

use App\Models\Stock;

/**
 * Turns a stock's price/indicator history into feature vectors for the ML
 * direction predictor. Used identically at training time and at prediction
 * time so features can never silently drift between the two.
 */
class MlFeatureBuilder
{
    public const FEATURE_NAMES = [
        'rsi_14', 'macd', 'macd_signal', 'macd_histogram',
        'close_sma20_ratio', 'close_sma50_ratio', 'close_sma200_ratio',
        'bb_position', 'return_1d', 'return_5d', 'return_10d',
        'volume_ratio', 'atr_pct',
    ];

    /**
     * Every day a stock has enough warm-up history for a valid feature
     * vector (gated on sma_200 existing, since every other indicator here
     * needs a shorter warm-up window than that).
     *
     * @return list<array{date: string, index: int, features: float[], close: float}>
     */
    public function buildFeatureRows(Stock $stock): array
    {
        $prices = $stock->dailyPrices()->orderBy('trade_date')->get();
        $indicatorsByDate = $stock->technicalIndicators()->orderBy('trade_date')->get()
            ->keyBy(fn ($i) => $i->trade_date->toDateString());

        $closes = $prices->pluck('close_price')->map(fn ($v) => (float) $v)->values()->all();
        $volumes = $prices->pluck('volume')->map(fn ($v) => (float) ($v ?? 0))->values()->all();
        $dates = $prices->pluck('trade_date')->map(fn ($d) => $d->toDateString())->values()->all();

        $rows = [];
        $n = count($closes);

        for ($i = 10; $i < $n; $i++) {
            $indicator = $indicatorsByDate->get($dates[$i]);

            if (! $indicator || $indicator->sma_200 === null) {
                continue;
            }

            $close = $closes[$i];
            $return1d = $closes[$i - 1] != 0.0 ? ($close - $closes[$i - 1]) / $closes[$i - 1] : 0.0;
            $return5d = $closes[$i - 5] != 0.0 ? ($close - $closes[$i - 5]) / $closes[$i - 5] : 0.0;
            $return10d = $closes[$i - 10] != 0.0 ? ($close - $closes[$i - 10]) / $closes[$i - 10] : 0.0;

            $volWindow = array_slice($volumes, $i - 19, 20);
            $avgVol20 = array_sum($volWindow) / count($volWindow);
            $volumeRatio = $avgVol20 > 0 ? $volumes[$i] / $avgVol20 : 1.0;

            $bbUpper = (float) $indicator->bb_upper;
            $bbLower = (float) $indicator->bb_lower;
            $bbRange = $bbUpper - $bbLower;
            $bbPosition = $bbRange > 0 ? ($close - $bbLower) / $bbRange : 0.5;

            $rows[] = [
                'date' => $dates[$i],
                'index' => $i,
                'close' => $close,
                'features' => [
                    (float) $indicator->rsi_14,
                    (float) $indicator->macd,
                    (float) $indicator->macd_signal,
                    (float) $indicator->macd_histogram,
                    $close / max((float) $indicator->sma_20, 0.0001),
                    $close / max((float) $indicator->sma_50, 0.0001),
                    $close / max((float) $indicator->sma_200, 0.0001),
                    $bbPosition,
                    $return1d,
                    $return5d,
                    $return10d,
                    $volumeRatio,
                    ((float) $indicator->atr_14) / max($close, 0.0001),
                ],
            ];
        }

        return $rows;
    }

    /**
     * @return array{date: string, features: float[], close: float}|null
     */
    public function latestFeatureRow(Stock $stock): ?array
    {
        $rows = $this->buildFeatureRows($stock);

        return $rows === [] ? null : $rows[array_key_last($rows)];
    }

    /**
     * All rows plus the closes array, so a caller can look ahead by N days
     * to build a training label without re-querying the DB.
     *
     * @return array{rows: list<array{date: string, index: int, features: float[], close: float}>, closes: float[]}
     */
    public function buildTrainingData(Stock $stock): array
    {
        $rows = $this->buildFeatureRows($stock);
        $closes = $stock->dailyPrices()->orderBy('trade_date')->pluck('close_price')->map(fn ($v) => (float) $v)->values()->all();

        return ['rows' => $rows, 'closes' => $closes];
    }
}
