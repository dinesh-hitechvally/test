<?php

namespace App\Services\MarketData;

use App\Models\DailyPrice;
use App\Models\ScrapeLog;
use App\Models\Stock;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Scrapes today's NEPSE price snapshot from ShareSansar's public,
 * no-auth "Today Share Price" page (a single server-rendered HTML
 * table, no pagination). NEPSE itself has no stable public API.
 *
 * Manually triggered (button click), not scheduled — keeps load and
 * ToS exposure minimal.
 */
class SharesansarScraperService
{
    private const SOURCE_URL = 'https://www.sharesansar.com/today-share-price';

    private const SOURCE_NAME = 'sharesansar.com';

    /**
     * @return array{created_stocks: int, updated_prices: int, affected_stock_ids: int[]}
     */
    public function scrape(): array
    {
        try {
            $html = $this->fetchHtml();
            $rows = $this->parseTable($html);

            if ($rows === []) {
                throw new RuntimeException('No rows parsed from the source page — its layout may have changed.');
            }

            $result = $this->persist($rows);

            ScrapeLog::create([
                'source' => self::SOURCE_NAME,
                'status' => 'success',
                'records_processed' => count($rows),
                'message' => sprintf(
                    '%d stocks created, %d price rows upserted.',
                    $result['created_stocks'],
                    $result['updated_prices']
                ),
            ]);

            return $result;
        } catch (Throwable $e) {
            Log::warning('NEPSE scrape failed', ['error' => $e->getMessage()]);

            ScrapeLog::create([
                'source' => self::SOURCE_NAME,
                'status' => 'failed',
                'records_processed' => 0,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function fetchHtml(): string
    {
        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome Safari',
        ])->timeout(20)->get(self::SOURCE_URL);

        $response->throw();

        return $response->body();
    }

    /**
     * @return list<array{symbol: string, company_name: ?string, open: float, high: float, low: float, close: float, volume: int, turnover: ?float}>
     */
    private function parseTable(string $html): array
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML($html);
        libxml_clear_errors();

        $xpath = new DOMXPath($doc);
        $table = $xpath->query('//table[@id="headFixed"]')->item(0);

        if ($table === null) {
            throw new RuntimeException('Could not find the price table on the source page — its layout may have changed.');
        }

        $headers = [];
        foreach ($xpath->query('.//thead//th', $table) as $i => $th) {
            $headers[trim($th->textContent)] = $i;
        }

        $required = ['Symbol', 'Open', 'High', 'Low', 'Close', 'Vol', 'Turnover'];
        foreach ($required as $col) {
            if (! array_key_exists($col, $headers)) {
                throw new RuntimeException("Expected column [$col] not found — the source page layout may have changed.");
            }
        }

        $rows = [];
        foreach ($xpath->query('.//tbody/tr', $table) as $tr) {
            $cells = $xpath->query('./td', $tr);

            if ($cells->length === 0) {
                continue;
            }

            $symbolCell = $cells->item($headers['Symbol']);
            $link = $xpath->query('.//a', $symbolCell)->item(0);
            $symbol = trim($link?->textContent ?? $symbolCell->textContent);

            if ($symbol === '') {
                continue;
            }

            $rows[] = [
                'symbol' => strtoupper($symbol),
                'company_name' => $link?->getAttribute('title') ?: null,
                'open' => $this->number($cells->item($headers['Open'])->textContent),
                'high' => $this->number($cells->item($headers['High'])->textContent),
                'low' => $this->number($cells->item($headers['Low'])->textContent),
                'close' => $this->number($cells->item($headers['Close'])->textContent),
                'volume' => (int) $this->number($cells->item($headers['Vol'])->textContent),
                'turnover' => $this->number($cells->item($headers['Turnover'])->textContent),
            ];
        }

        return $rows;
    }

    private function number(string $raw): float
    {
        $clean = trim(str_replace([',', ' '], '', $raw));

        return $clean === '' ? 0.0 : (float) $clean;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{created_stocks: int, updated_prices: int, affected_stock_ids: int[]}
     */
    private function persist(array $rows): array
    {
        $today = Carbon::today()->toDateString();
        $createdStocks = 0;
        $affectedStockIds = [];
        $priceRows = [];

        foreach ($rows as $row) {
            $stock = Stock::firstOrNew(['symbol' => $row['symbol']]);
            $isNew = ! $stock->exists;

            if ($isNew) {
                $stock->company_name = $row['company_name'];
                $stock->is_active = true;
                $stock->save();
                $createdStocks++;
            }

            $affectedStockIds[] = $stock->id;

            $priceRows[] = [
                'stock_id' => $stock->id,
                'trade_date' => $today,
                'open_price' => $row['open'],
                'high_price' => $row['high'],
                'low_price' => $row['low'],
                'close_price' => $row['close'],
                'volume' => $row['volume'],
                'turnover' => $row['turnover'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DailyPrice::upsert(
            $priceRows,
            uniqueBy: ['stock_id', 'trade_date'],
            update: ['open_price', 'high_price', 'low_price', 'close_price', 'volume', 'turnover', 'updated_at']
        );

        return [
            'created_stocks' => $createdStocks,
            'updated_prices' => count($priceRows),
            'affected_stock_ids' => $affectedStockIds,
        ];
    }
}
