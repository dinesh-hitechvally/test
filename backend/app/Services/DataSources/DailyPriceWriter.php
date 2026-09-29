<?php

namespace App\Services\DataSources;

use App\Models\DailyPrice;
use App\Models\Stock;

/**
 * Saves one trading date's prices, whatever source they came from, and only
 * touches what actually needs it: a missing row is inserted, a row whose
 * numbers differ (e.g. captured mid-session, now final) is updated, and an
 * identical row is left alone. The caller gets back which stocks changed,
 * so only those get recalculated.
 */
class DailyPriceWriter
{
    private const PRICE_FIELDS = ['open_price', 'high_price', 'low_price', 'close_price', 'volume', 'turnover'];

    /**
     * @param  list<array{symbol: string, open: float, high: float, low: float, close: float, volume: int, turnover: float, company_name?: ?string, nepse_security_id?: ?int}>  $rows
     * @param  bool  $createMissingStocks  add a stocks row for an unknown symbol (only for sources whose symbols are authoritative)
     * @return array{inserted: int, updated: int, unchanged: int, skipped: int, created_stocks: int, changed_stock_ids: int[]}
     */
    public function write(string $date, array $rows, bool $createMissingStocks): array
    {
        $stocks = Stock::whereIn('symbol', array_column($rows, 'symbol'))->get()->keyBy('symbol');
        $existing = DailyPrice::where('trade_date', $date)->whereIn('stock_id', $stocks->pluck('id'))->get()->keyBy('stock_id');

        $counts = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'created_stocks' => 0];
        $toSave = [];

        foreach ($rows as $row) {
            $stock = $stocks->get($row['symbol']) ?? ($createMissingStocks ? $this->createStock($row, $counts) : null);

            if (! $stock) {
                $counts['skipped']++;

                continue;
            }

            $this->fillSecurityId($stock, $row);

            $prices = [
                'open_price' => $row['open'],
                'high_price' => $row['high'],
                'low_price' => $row['low'],
                'close_price' => $row['close'],
                'volume' => $row['volume'],
                'turnover' => $row['turnover'],
            ];
            $current = $existing->get($stock->id);

            if ($current && $this->same($current, $prices)) {
                $counts['unchanged']++;

                continue;
            }

            $counts[$current ? 'updated' : 'inserted']++;
            $toSave[] = ['stock_id' => $stock->id, 'trade_date' => $date, ...$prices, 'created_at' => now(), 'updated_at' => now()];
        }

        foreach (array_chunk($toSave, 500) as $chunk) {
            DailyPrice::upsert($chunk, uniqueBy: ['stock_id', 'trade_date'], update: [...self::PRICE_FIELDS, 'updated_at']);
        }

        return [...$counts, 'changed_stock_ids' => array_column($toSave, 'stock_id')];
    }

    private function createStock(array $row, array &$counts): Stock
    {
        $counts['created_stocks']++;

        return Stock::create([
            'symbol' => $row['symbol'],
            'company_name' => $row['company_name'] ?? null,
            'is_active' => true,
        ]);
    }

    private function fillSecurityId(Stock $stock, array $row): void
    {
        if (! empty($row['nepse_security_id']) && $stock->nepse_security_id === null) {
            $stock->update(['nepse_security_id' => (int) $row['nepse_security_id']]);
        }
    }

    /** Compared at the column's stored precision (4 decimals), so float noise isn't a "change". */
    private function same(DailyPrice $current, array $prices): bool
    {
        foreach ($prices as $field => $value) {
            if (round((float) $current->{$field}, 4) !== round((float) $value, 4)) {
                return false;
            }
        }

        return true;
    }
}
