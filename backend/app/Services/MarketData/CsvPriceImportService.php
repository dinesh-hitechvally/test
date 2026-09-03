<?php

namespace App\Services\MarketData;

use App\Models\DailyPrice;
use App\Models\Stock;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Imports historical OHLCV data from a CSV file. Tolerant of common header
 * naming variations from NEPSE/Merolagani/ShareSansar-style exports.
 */
class CsvPriceImportService
{
    private const ALIASES = [
        'symbol' => ['symbol', 'ticker', 'scrip'],
        'date' => ['date', 'tradedate', 'trade_date'],
        'open' => ['open', 'openprice', 'open_price'],
        'high' => ['high', 'highprice', 'high_price'],
        'low' => ['low', 'lowprice', 'low_price'],
        'close' => ['close', 'closeprice', 'close_price', 'ltp', 'closingprice'],
        'volume' => ['volume', 'vol', 'qty', 'quantity', 'sharetraded'],
    ];

    /**
     * @return array{stocks: int, prices: int, affected_stock_ids: int[]}
     */
    public function import(UploadedFile $file, ?string $symbolOverride = null): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            throw new RuntimeException('Could not read the uploaded file.');
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);
            throw new RuntimeException('The CSV file appears to be empty.');
        }

        $columnMap = $this->mapColumns($header);

        if (! isset($columnMap['date'], $columnMap['open'], $columnMap['high'], $columnMap['low'], $columnMap['close'])) {
            fclose($handle);
            throw new RuntimeException('CSV must contain Date, Open, High, Low, and Close columns.');
        }

        if (! isset($columnMap['symbol']) && $symbolOverride === null) {
            fclose($handle);
            throw new RuntimeException('CSV has no Symbol column — provide a symbol to import this file as.');
        }

        $stockCache = [];
        $priceRows = [];
        $createdStocks = 0;

        while (($line = fgetcsv($handle)) !== false) {
            if (count(array_filter($line, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $symbol = strtoupper(trim(isset($columnMap['symbol']) ? (string) $line[$columnMap['symbol']] : $symbolOverride));

            if ($symbol === '') {
                continue;
            }

            if (! isset($stockCache[$symbol])) {
                $stock = Stock::firstOrNew(['symbol' => $symbol]);
                if (! $stock->exists) {
                    $stock->is_active = true;
                    $stock->save();
                    $createdStocks++;
                }
                $stockCache[$symbol] = $stock;
            }

            $date = $this->parseDate((string) $line[$columnMap['date']]);

            if ($date === null) {
                continue;
            }

            $priceRows[] = [
                'stock_id' => $stockCache[$symbol]->id,
                'trade_date' => $date,
                'open_price' => $this->number($line[$columnMap['open']]),
                'high_price' => $this->number($line[$columnMap['high']]),
                'low_price' => $this->number($line[$columnMap['low']]),
                'close_price' => $this->number($line[$columnMap['close']]),
                'volume' => isset($columnMap['volume']) ? (int) $this->number($line[$columnMap['volume']]) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        fclose($handle);

        if ($priceRows === []) {
            throw new RuntimeException('No valid price rows found in the CSV.');
        }

        foreach (array_chunk($priceRows, 500) as $chunk) {
            DailyPrice::upsert(
                $chunk,
                uniqueBy: ['stock_id', 'trade_date'],
                update: ['open_price', 'high_price', 'low_price', 'close_price', 'volume', 'updated_at']
            );
        }

        return [
            'stocks' => $createdStocks,
            'prices' => count($priceRows),
            'affected_stock_ids' => array_values(array_unique(array_map(fn ($s) => $s->id, $stockCache))),
        ];
    }

    /**
     * @param  string[]  $header
     * @return array<string, int>
     */
    private function mapColumns(array $header): array
    {
        $normalized = array_map(
            fn ($h) => strtolower(preg_replace('/[^a-z0-9]/i', '', trim((string) $h))),
            $header
        );

        $map = [];
        foreach (self::ALIASES as $field => $aliases) {
            foreach ($normalized as $index => $col) {
                if (in_array($col, $aliases, true)) {
                    $map[$field] = $index;
                    break;
                }
            }
        }

        return $map;
    }

    private function parseDate(string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function number(mixed $raw): float
    {
        $clean = trim(str_replace([',', ' '], '', (string) $raw));

        return $clean === '' ? 0.0 : (float) $clean;
    }
}
