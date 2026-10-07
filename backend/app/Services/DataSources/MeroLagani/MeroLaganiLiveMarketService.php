<?php

namespace App\Services\DataSources\MeroLagani;

use App\Services\DataSources\NepalStock\NepalStockClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Every stock's live prices from MeroLagani's Live Trading page — the last-resort fallback when nepalstock.com
 * and ShareSansar can't be read.
 *
 * Two things are worked out because the page does not say them:
 *
 *   market open  not shown on the page; judged from the clock (Nepal time, Sunday-Thursday, ~11:00-15:00) AND the
 *                page's own "As of" time being fresh (within 20 minutes) — a stale page is never taken for live.
 * The page's column headings do not match its cells, so the cells are read in the order the rest of the site uses
 * (LTP, % change, high, low, open, quantity). The open in particular is not always the day's first trade — for some
 * stocks it is the previous close — so open / high / low from here are approximate, and are forced to be consistent
 * (low <= open <= high, and the last price inside the range). That is acceptable for a last-resort source: the
 * closed-market sync replaces the row with ShareSansar's final prices when they can be read.
 *
 * No turnover on the page: estimated as volume x last price.
 */
class MeroLaganiLiveMarketService
{
    private const PAGE = 'https://merolagani.com/LatestMarket.aspx';

    private const TIMEZONE = 'Asia/Kathmandu';

    /**
     * @return array{date: string, as_of: string, open: bool, rows: list<array{symbol: string, open: float, high: float, low: float, close: float, volume: int, turnover: float, company_name: ?string}>}
     */
    public function snapshot(): array
    {
        $response = Http::withHeaders(['User-Agent' => NepalStockClient::USER_AGENT])->timeout(30)->get(self::PAGE);
        $response->throw();
        $html = $response->body();

        if (! preg_match('/As of\s+(\d{4})\/(\d{2})\/(\d{2})\s+(\d{2}:\d{2}:\d{2})/', $html, $m)) {
            throw new RuntimeException('MeroLagani live-trading page has no "As of" time — its layout may have changed.');
        }

        $asOf = Carbon::parse("{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}", self::TIMEZONE);

        return [
            'date' => "{$m[1]}-{$m[2]}-{$m[3]}",
            'as_of' => $asOf->format('Y-m-d H:i:s'),
            'open' => $this->isOpen($asOf),
            'rows' => $this->rows($html),
        ];
    }

    public function isOpen(Carbon $asOf, ?Carbon $now = null): bool
    {
        $now = ($now ?? Carbon::now())->setTimezone(self::TIMEZONE);
        $minutes = $now->hour * 60 + $now->minute;

        // NEPSE trades Sunday to Thursday, 11:00-15:00 (a few minutes' slack either side).
        $tradingDay = in_array($now->dayOfWeek, [Carbon::SUNDAY, Carbon::MONDAY, Carbon::TUESDAY, Carbon::WEDNESDAY, Carbon::THURSDAY], true);
        $tradingHours = $minutes >= 10 * 60 + 55 && $minutes <= 15 * 60 + 10;

        return $tradingDay && $tradingHours && abs($now->diffInMinutes($asOf, false)) <= 20;
    }

    private function rows(string $html): array
    {
        $start = strpos($html, 'ctl00_ContentPlaceHolder1_LiveTrading');

        if ($start === false) {
            throw new RuntimeException('MeroLagani live-trading table not found — its layout may have changed.');
        }

        $table = substr($html, $start, (int) (strpos($html, '</table>', $start) - $start));
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $table, $trs);

        $rows = [];
        foreach ($trs[1] as $tr) {
            preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $tr, $tds);
            $cells = array_map(fn ($c) => trim(html_entity_decode(strip_tags($c))), $tds[1]);

            // symbol, LTP, % change, high, low, open, quantity, then icon cells
            if (count($cells) < 7 || $cells[0] === '') {
                continue;
            }

            $close = $this->number($cells[1]);
            $volume = (int) $this->number($cells[6]);
            [$high, $low, $open] = [$this->number($cells[3]), $this->number($cells[4]), $this->number($cells[5])];
            $high = max($high, $low, $open, $close);
            $low = min($high, $low, $open, $close);

            $rows[] = [
                'symbol' => strtoupper($cells[0]),
                'open' => min(max($open, $low), $high),
                'high' => $high,
                'low' => $low,
                'close' => $close,
                'volume' => $volume,
                'turnover' => round($close * $volume, 2),
                'company_name' => null,
            ];
        }

        return $rows;
    }

    private function number(string $raw): float
    {
        $clean = str_replace([',', ' ', '%'], '', $raw);

        return is_numeric($clean) ? (float) $clean : 0.0;
    }
}
