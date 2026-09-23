<?php

namespace App\Services\MarketData;

use App\Models\ScrapeLog;
use App\Models\Stock;
use App\Models\StockFundamental;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Fetches per-stock fundamentals (EPS, P/E, Book Value, PBV, Market Cap,
 * Shares Outstanding) from merolagani.com's company detail page — the one
 * source among the ones already used by this app (ShareSansar, the official
 * NEPSE API) that actually publishes these. Neither of those carries EPS/PE/
 * book value at all (confirmed by inspecting their live pages before
 * building this).
 *
 * These numbers move slowly (EPS is only restated quarterly; P/E and market
 * cap drift with price but not meaningfully day to day), so this is a
 * separate, much-less-frequent sync than the daily price scrape — see
 * CronController::syncFundamentals() for the weekly cadence.
 */
class MeroLaganiFundamentalsService
{
    private const COMPANY_PAGE = 'https://merolagani.com/CompanyDetail.aspx?symbol=%s';

    private const SOURCE_NAME = 'merolagani.com/fundamentals';

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome Safari';

    /**
     * Fetches and parses one stock's fundamentals page — does not persist.
     *
     * @return array{eps: ?float, eps_fiscal_year: ?string, pe_ratio: ?float, book_value: ?float, pbv: ?float, market_cap: ?float, shares_outstanding: ?float, one_year_yield_pct: ?float}
     */
    public function fetch(Stock $stock): array
    {
        $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
            ->timeout(20)
            ->get(sprintf(self::COMPANY_PAGE, strtoupper($stock->symbol)));

        if ($response->status() === 404) {
            throw new RuntimeException("No merolagani.com page found for symbol [{$stock->symbol}].");
        }

        $response->throw();
        $html = $response->body();

        $epsRaw = $this->extractField($html, 'EPS');
        [$eps, $epsFiscalYear] = $this->splitValueAndParenthetical($epsRaw);

        $data = [
            'eps' => $eps,
            'eps_fiscal_year' => $epsFiscalYear,
            'pe_ratio' => $this->parseNumber($this->extractField($html, 'P/E Ratio')),
            'book_value' => $this->parseNumber($this->extractField($html, 'Book Value')),
            'pbv' => $this->parseNumber($this->extractField($html, 'PBV')),
            'market_cap' => $this->parseNumber($this->extractField($html, 'Market Capitalization')),
            'shares_outstanding' => $this->parseNumber($this->extractField($html, 'Shares Outstanding')),
            'one_year_yield_pct' => $this->parseNumber($this->extractField($html, '1 Year Yield')),
        ];

        if ($data['eps'] === null && $data['pe_ratio'] === null && $data['market_cap'] === null) {
            throw new RuntimeException('No recognizable fundamentals fields found — the source page layout may have changed.');
        }

        return $data;
    }

    /**
     * Fetches, persists, and logs one stock — the unit of work the cron
     * endpoint calls per stock in its batch.
     */
    public function syncOne(Stock $stock): StockFundamental
    {
        try {
            $data = $this->fetch($stock);

            $fundamental = StockFundamental::updateOrCreate(
                ['stock_id' => $stock->id],
                [...$data, 'fetched_at' => now()]
            );

            ScrapeLog::create([
                'source' => self::SOURCE_NAME,
                'status' => 'success',
                'records_processed' => 1,
                'message' => sprintf(
                    '%s: EPS=%s PE=%s BookValue=%s',
                    $stock->symbol,
                    $data['eps'] ?? 'n/a',
                    $data['pe_ratio'] ?? 'n/a',
                    $data['book_value'] ?? 'n/a'
                ),
            ]);

            return $fundamental;
        } catch (Throwable $e) {
            Log::warning('merolagani fundamentals fetch failed', ['symbol' => $stock->symbol, 'error' => $e->getMessage()]);

            ScrapeLog::create([
                'source' => self::SOURCE_NAME,
                'status' => 'failed',
                'records_processed' => 0,
                'message' => $this->truncatedMessage($stock->symbol, $e),
            ]);

            throw $e;
        }
    }

    /**
     * Pulls the raw inner text of the <td> paired with a given <th> label,
     * e.g. extractField($html, 'P/E Ratio') on:
     *   <th>P/E Ratio</th><td>19.92</td>
     * Tolerant of trailing whitespace/tabs after the label (the live page has
     * at least one field with a literal trailing tab) and of the value being
     * wrapped in <span>/<strong> (several fields render that way).
     */
    private function extractField(string $html, string $label): ?string
    {
        $pattern = '/<th[^>]*>\s*'.preg_quote($label, '/').'[\s\t]*(?:<a[^>]*>)?\s*<\/th>\s*<td[^>]*>(.*?)<\/td>/is';

        if (! preg_match($pattern, $html, $matches)) {
            return null;
        }

        $text = trim(preg_replace('/\s+/', ' ', strip_tags($matches[1])));

        return $text === '' ? null : $text;
    }

    /**
     * EPS's cell reads like "28.36 (FY:082-083, Q:4)" — splits the leading
     * number from the parenthetical fiscal-year label.
     *
     * @return array{0: ?float, 1: ?string}
     */
    private function splitValueAndParenthetical(?string $raw): array
    {
        if ($raw === null) {
            return [null, null];
        }

        if (preg_match('/^([\-0-9,.]+)\s*(?:\((.*)\))?/', $raw, $m)) {
            return [$this->parseNumber($m[1]), isset($m[2]) && $m[2] !== '' ? $m[2] : null];
        }

        return [$this->parseNumber($raw), null];
    }

    private function parseNumber(?string $raw): ?float
    {
        if ($raw === null) {
            return null;
        }

        $numeric = preg_replace('/[^0-9.\-]/', '', $raw);

        return $numeric === '' || $numeric === '-' ? null : (float) $numeric;
    }

    private function truncatedMessage(string $symbol, Throwable $e): string
    {
        $message = "{$symbol}: {$e->getMessage()}";

        return mb_strlen($message) > 2000 ? mb_substr($message, 0, 2000).'… (truncated)' : $message;
    }
}
