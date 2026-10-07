<?php

namespace App\Services\DataSources\ShareSansar;

use App\Services\DataSources\NepalStock\NepalStockClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * What nepalstock.com's market-status, live-market and index endpoints give, read from ShareSansar instead —
 * the fallback when nepalstock.com can't be reached.
 *
 *   live-trading  the market status ("Market Open" button), the "As of" time and every stock's live prices
 *   market        the NEPSE index and sub-indices for the day
 *
 * Live rows carry no turnover, so it is estimated as volume x last price; the closed-market sync (ShareSansar's
 * final prices) replaces the row with the real figure.
 */
class SharesansarLiveMarketService
{
    private const LIVE = 'https://www.sharesansar.com/live-trading';

    private const MARKET = 'https://www.sharesansar.com/market';

    /**
     * @return array{date: string, as_of: string, open: bool, rows: list<array{symbol: string, open: float, high: float, low: float, close: float, volume: int, turnover: float, company_name: ?string}>}
     */
    public function snapshot(): array
    {
        $html = $this->get(self::LIVE);

        if (! preg_match('/id="dDate"[^>]*>\s*(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2}:\d{2})/', $html, $asOf)) {
            throw new RuntimeException('ShareSansar live-trading page has no "As of" time — its layout may have changed.');
        }

        // "Market Open" while trading; any other label ("Market Close" ...) means closed.
        $open = (bool) preg_match('/<button[^>]*>\s*Market\s+Open\s*<\/button>/i', $html);

        return [
            'date' => $asOf[1],
            'as_of' => "{$asOf[1]} {$asOf[2]}",
            'open' => $open,
            'rows' => $this->rows($html),
        ];
    }

    /**
     * The NEPSE index and sub-indices, in IndexSnapshot's field names (names as ShareSansar prints them).
     *
     * @return list<array{index: string, open: float, high: float, low: float, close: float, change: float, change_pct: float, previous_close: float}>
     */
    public function indices(): array
    {
        $html = $this->get(self::MARKET);

        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $html, $trs);
        $indices = [];

        foreach ($trs[1] as $tr) {
            preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $tr, $tds);
            $cells = array_map(fn ($c) => trim(html_entity_decode(strip_tags($c))), $tds[1]);

            // "NEPSE Index | open | high | low | close | point change | % change | turnover"
            if (count($cells) < 8 || ! preg_match('/index$/i', $cells[0]) || ! is_numeric(str_replace(',', '', $cells[4]))) {
                continue;
            }

            $close = $this->number($cells[4]);
            $change = $this->number($cells[5]);

            $indices[] = [
                'index' => $cells[0],
                'open' => $this->number($cells[1]),
                'high' => $this->number($cells[2]),
                'low' => $this->number($cells[3]),
                'close' => $close,
                'change' => $change,
                'change_pct' => $this->number($cells[6]),
                'previous_close' => round($close - $change, 2),
            ];
        }

        if ($indices === []) {
            throw new RuntimeException('ShareSansar market page has no index rows — its layout may have changed.');
        }

        return $indices;
    }

    private function rows(string $html): array
    {
        preg_match('/<thead>(.*?)<\/thead>/s', $html, $head);
        preg_match_all('/<th[^>]*>(.*?)<\/th>/s', $head[1] ?? '', $headers);
        $index = array_flip(array_map(fn ($h) => trim(html_entity_decode(strip_tags($h))), $headers[1]));

        foreach (['Symbol', 'LTP', 'Open', 'High', 'Low', 'Volume'] as $column) {
            if (! isset($index[$column])) {
                throw new RuntimeException("ShareSansar's live-trading table has no \"{$column}\" column — its layout may have changed.");
            }
        }

        preg_match('/<tbody>(.*?)<\/tbody>/s', $html, $body);
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $body[1] ?? '', $trs);

        $rows = [];
        foreach ($trs[1] as $tr) {
            preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $tr, $tds);
            $cells = array_map(fn ($c) => trim(html_entity_decode(strip_tags($c))), $tds[1]);

            if (count($cells) <= max($index)) {
                continue;
            }

            $symbol = strtoupper($cells[$index['Symbol']]);
            if ($symbol === '') {
                continue;
            }

            $close = $this->number($cells[$index['LTP']]);
            $volume = (int) $this->number($cells[$index['Volume']]);

            $rows[] = [
                'symbol' => $symbol,
                'open' => $this->number($cells[$index['Open']]),
                'high' => $this->number($cells[$index['High']]),
                'low' => $this->number($cells[$index['Low']]),
                'close' => $close,
                'volume' => $volume,
                'turnover' => round($close * $volume, 2),
                'company_name' => null,
            ];
        }

        return $rows;
    }

    private function get(string $url): string
    {
        $response = Http::withHeaders(['User-Agent' => NepalStockClient::USER_AGENT])->timeout(30)->get($url);
        $response->throw();

        return $response->body();
    }

    private function number(string $raw): float
    {
        $clean = str_replace([',', ' ', '%'], '', $raw);

        return is_numeric($clean) ? (float) $clean : 0.0;
    }
}
