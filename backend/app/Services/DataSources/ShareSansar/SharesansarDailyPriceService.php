<?php

namespace App\Services\DataSources\ShareSansar;

use App\Services\DataSources\NepalStock\NepalStockClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Every stock's FINAL prices for one trading date, from ShareSansar's
 * "Today's Share Price" page (the same request its date picker makes).
 * One request covers the whole market, and it works for past dates — unlike
 * NEPSE's live feed, which only has the current session and is emptied
 * overnight.
 *
 * A date the market was closed returns no rows (never a neighbouring day's
 * prices), and the page's own "As of" date is checked against the one asked
 * for, so a wrong day can't be saved.
 */
class SharesansarDailyPriceService
{
    private const PAGE = 'https://www.sharesansar.com/today-share-price';

    private const ENDPOINT = 'https://www.sharesansar.com/ajaxtodayshareprice';

    /** Header text on ShareSansar's table → our field. Matched by name, so a reordered table still parses. */
    private const COLUMNS = [
        'Symbol' => 'symbol', 'Open' => 'open', 'High' => 'high', 'Low' => 'low',
        'Close' => 'close', 'Vol' => 'volume', 'Turnover' => 'turnover',
    ];

    /**
     * Empty if the market didn't trade that day.
     *
     * @param  string  $date  Y-m-d
     * @return list<array{symbol: string, open: float, high: float, low: float, close: float, volume: int, turnover: float}>
     */
    public function pricesFor(string $date): array
    {
        $page = Http::withHeaders(['User-Agent' => NepalStockClient::USER_AGENT])->timeout(30)->get(self::PAGE);
        $page->throw();

        if (! preg_match('/name="_token"\s+content="([^"]+)"/', $page->body(), $token)) {
            throw new RuntimeException('ShareSansar page has no CSRF token — its layout may have changed.');
        }

        $response = Http::withHeaders([
            'User-Agent' => NepalStockClient::USER_AGENT,
            'X-Requested-With' => 'XMLHttpRequest',
            'Referer' => self::PAGE,
        ])
            ->withOptions(['cookies' => $page->cookies()])
            ->asForm()
            ->timeout(30)
            ->post(self::ENDPOINT, ['_token' => $token[1], 'sector' => 'all_sec', 'date' => $date]);
        $response->throw();

        return $this->parse($response->body(), $date);
    }

    private function parse(string $html, string $date): array
    {
        if (preg_match('/As of\s*:\s*(\d{4}-\d{2}-\d{2})/', strip_tags($html), $asOf) && $asOf[1] !== $date) {
            throw new RuntimeException("ShareSansar returned prices as of {$asOf[1]}, not {$date}.");
        }

        preg_match('/<thead>(.*?)<\/thead>/s', $html, $head);
        preg_match_all('/<th[^>]*>(.*?)<\/th>/s', $head[1] ?? '', $headers);
        $index = array_flip(array_map(fn ($h) => trim(html_entity_decode(strip_tags($h))), $headers[1]));

        foreach (array_keys(self::COLUMNS) as $column) {
            if (! isset($index[$column])) {
                throw new RuntimeException("ShareSansar's price table has no \"{$column}\" column — its layout may have changed.");
            }
        }

        preg_match('/<tbody>(.*?)<\/tbody>/s', $html, $body);
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $body[1] ?? '', $trs);

        $rows = [];
        foreach ($trs[1] as $tr) {
            preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $tr, $tds);
            $cells = array_map(fn ($c) => trim(html_entity_decode(strip_tags($c))), $tds[1]);

            // A closed day's single "no record" row has too few cells.
            if (count($cells) < count($index)) {
                continue;
            }

            $symbol = strtoupper($cells[$index['Symbol']]);
            if ($symbol === '') {
                continue;
            }

            $rows[] = [
                'symbol' => $symbol,
                'open' => $this->number($cells[$index['Open']]),
                'high' => $this->number($cells[$index['High']]),
                'low' => $this->number($cells[$index['Low']]),
                'close' => $this->number($cells[$index['Close']]),
                'volume' => (int) $this->number($cells[$index['Vol']]),
                'turnover' => $this->number($cells[$index['Turnover']]),
            ];
        }

        return $rows;
    }

    private function number(string $raw): float
    {
        $clean = str_replace([',', ' '], '', $raw);

        return is_numeric($clean) ? (float) $clean : 0.0;
    }
}
