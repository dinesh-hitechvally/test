<?php

namespace App\Services\MarketData;

use App\Models\Dividend;
use App\Models\ScrapeLog;
use App\Models\Stock;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches dividend declarations from the official nepalstock.com API —
 * used as a fallback source when ShareSansar's dividend/right-share AJAX
 * endpoints are blocked (they've returned empty results persistently).
 *
 * Only covers the `dividends` table: NEPSE's dividend-application endpoint
 * gives clean cash-dividend/bonus-share percentages per fiscal year, but
 * none of the right-share-specific fields (ratio, issue price, open/close
 * dates, issue manager) that the `right_shares` table expects — those
 * remain ShareSansar-only for now.
 */
class NepalStockCorporateActionsService
{
    private const BASE_URL = 'https://www.nepalstock.com';

    private const DIVIDEND_PATH = '/api/nots/application/dividend/%d';

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome Safari';

    private const SOURCE_NAME = 'nepalstock.com/dividend';

    public function __construct(
        private readonly NepalStockTokenService $tokens,
        private readonly NepalStockSecurityResolver $resolver,
    ) {}

    /**
     * @return array{dividends: int}
     */
    public function fetchDividends(Stock $stock): array
    {
        try {
            $securityId = $this->resolver->resolve($stock);
            $token = $this->tokens->getAccessToken();

            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Referer' => self::BASE_URL.'/',
                'Authorization' => 'Salter '.$token,
            ])->timeout(20)->get(self::BASE_URL.sprintf(self::DIVIDEND_PATH, $securityId));

            $response->throw();

            $count = $this->persist($stock, $response->json() ?? []);

            ScrapeLog::create([
                'source' => self::SOURCE_NAME,
                'status' => 'success',
                'records_processed' => $count,
                'message' => "{$stock->symbol}: {$count} dividend row(s) imported from nepalstock.com.",
            ]);

            return ['dividends' => $count];
        } catch (Throwable $e) {
            Log::warning('NEPSE official dividend fetch failed', ['symbol' => $stock->symbol, 'error' => $e->getMessage()]);

            ScrapeLog::create([
                'source' => self::SOURCE_NAME,
                'status' => 'failed',
                'records_processed' => 0,
                'message' => "{$stock->symbol}: {$e->getMessage()}",
            ]);

            throw $e;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $applications
     */
    private function persist(Stock $stock, array $applications): int
    {
        $rows = [];

        foreach ($applications as $application) {
            $notice = $application['companyNews']['dividendsNotice'] ?? null;
            $fiscalYear = $notice['financialYear']['fyNameNepali'] ?? null;

            // Every application response embeds the security's face value —
            // not always Rs. 100 (mutual fund units are commonly Rs. 10), and
            // dividend/bonus % is declared against that, not market price.
            // Captured here since it's already in a payload we're fetching
            // anyway, no extra request needed.
            $faceValue = $application['companyNews']['security']['faceValue'] ?? null;
            if ($faceValue !== null && $stock->face_value === null) {
                $stock->update(['face_value' => (float) $faceValue]);
            }

            if ($notice === null || $fiscalYear === null) {
                continue;
            }

            $cash = $notice['cashDividend'] !== null ? (float) $notice['cashDividend'] : null;
            $bonus = $notice['bonusShare'] !== null ? (float) $notice['bonusShare'] : null;
            $announced = $application['companyNews']['boardMeetingDate'] ?? $application['companyNews']['addedDate'] ?? null;

            $rows[str_replace('-', '/', $fiscalYear)] = [
                'stock_id' => $stock->id,
                'fiscal_year' => str_replace('-', '/', $fiscalYear),
                'bonus_share_pct' => $bonus,
                'cash_dividend_pct' => $cash,
                'total_dividend_pct' => $cash !== null || $bonus !== null ? ($cash ?? 0) + ($bonus ?? 0) : null,
                'announcement_date' => $announced ? substr($announced, 0, 10) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows === []) {
            return 0;
        }

        $rows = array_values($rows);

        Dividend::upsert(
            $rows,
            uniqueBy: ['stock_id', 'fiscal_year'],
            update: ['bonus_share_pct', 'cash_dividend_pct', 'total_dividend_pct', 'announcement_date', 'updated_at']
        );

        return count($rows);
    }
}
