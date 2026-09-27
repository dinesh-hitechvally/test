<?php

namespace App\Services\Analysis\Patterns;

use App\Models\DailyPrice;
use App\Models\Stock;

/**
 * Market-wide candlestick pattern scan. Deliberately lighter than the
 * per-stock detector on the Technical Analysis report: one batched query
 * for the last ~10 days of every stock rather than hundreds of separate
 * full histories, and only checks the most recent candle (plus the one
 * before it) — a market-wide scan run on every page load needs to stay cheap.
 */
class CandlestickPatternScanner
{
    /** @return list<array{stock_id: int, symbol: string, company_name: ?string, trade_date: string, pattern: string, signal: string}> */
    public function scan(): array
    {
        $since = now()->subDays(15)->toDateString();

        $rows = DailyPrice::where('trade_date', '>=', $since)
            ->orderBy('trade_date')
            ->get(['stock_id', 'trade_date', 'open_price', 'high_price', 'low_price', 'close_price'])
            ->groupBy('stock_id');

        $stocks = Stock::whereIn('id', $rows->keys())->get(['id', 'symbol', 'company_name'])->keyBy('id');
        $matches = [];

        foreach ($rows as $stockId => $candles) {
            $candles = $candles->values();
            $stock = $stocks->get($stockId);

            if ($candles->count() < 2 || ! $stock) {
                continue;
            }

            $pattern = $this->detect($this->candle($candles->last()), $this->candle($candles[$candles->count() - 2]));

            if ($pattern !== null) {
                $matches[] = [
                    'stock_id' => $stockId,
                    'symbol' => $stock->symbol,
                    'company_name' => $stock->company_name,
                    'trade_date' => $candles->last()->trade_date->toDateString(),
                    'pattern' => $pattern['name'],
                    'signal' => $pattern['signal'],
                ];
            }
        }

        return $matches;
    }

    private function candle(DailyPrice $row): array
    {
        $o = (float) $row->open_price;
        $h = (float) $row->high_price;
        $l = (float) $row->low_price;
        $c = (float) $row->close_price;

        return [
            'o' => $o, 'h' => $h, 'l' => $l, 'c' => $c,
            'body' => abs($c - $o),
            'range' => max($h - $l, 0.0001),
            'upper_wick' => $h - max($o, $c),
            'lower_wick' => min($o, $c) - $l,
            'bullish' => $c > $o,
        ];
    }

    private function detect(array $today, array $prev): ?array
    {
        if ($today['body'] <= 0.1 * $today['range']) {
            return ['name' => 'Doji', 'signal' => 'neutral'];
        }

        if ($today['lower_wick'] >= 2 * $today['body'] && $today['upper_wick'] <= 0.3 * $today['range']) {
            return ['name' => 'Hammer', 'signal' => 'bullish'];
        }

        if ($today['upper_wick'] >= 2 * $today['body'] && $today['lower_wick'] <= 0.3 * $today['range']) {
            return ['name' => 'Shooting Star', 'signal' => 'bearish'];
        }

        if (! $prev['bullish'] && $today['bullish'] && $today['o'] <= $prev['c'] && $today['c'] >= $prev['o']) {
            return ['name' => 'Bullish Engulfing', 'signal' => 'bullish'];
        }

        if ($prev['bullish'] && ! $today['bullish'] && $today['o'] >= $prev['c'] && $today['c'] <= $prev['o']) {
            return ['name' => 'Bearish Engulfing', 'signal' => 'bearish'];
        }

        return null;
    }
}
