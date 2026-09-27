<?php

namespace App\Services\DataSources\NepalStock;

use App\Events\ScrapeFinished;
use App\Models\Dividend;
use App\Models\Stock;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches dividend declarations from the official nepalstock.com API — this
 * app's only dividend source. (ShareSansar is used again elsewhere, for full
 * price history specifically — see SharesansarHistoryService — but not for
 * dividends/right-shares/sector, which stay on this official API.)
 *
 * Only covers the `dividends` table: NEPSE's dividend-application endpoint
 * gives clean cash-dividend/bonus-share percentages per fiscal year, but has
 * no equivalent for the right-share-specific fields (ratio, issue price,
 * open/close dates, issue manager) the `right_shares` table expects — no
 * official NEPSE endpoint for that data has been found, so `right_shares`
 * stays whatever was captured historically and won't get new rows.
 */
class NepalStockCorporateActionsService
{
    private const DIVIDEND_PATH = '/api/nots/application/dividend/%d';

    private const SOURCE_NAME = 'nepalstock.com/dividend';

    public function __construct(
        private readonly NepalStockClient $client,
        private readonly NepalStockSecurityResolver $resolver,
    ) {}

    /**
     * @return array{dividends: int}
     */
    public function fetchDividends(Stock $stock): array
    {
        try {
            $securityId = $this->resolver->resolve($stock);
            $response = $this->client->get(sprintf(self::DIVIDEND_PATH, $securityId));

            $response->throw();

            $count = $this->persist($stock, $response->json() ?? []);

            ScrapeFinished::dispatch(
                source: self::SOURCE_NAME,
                succeeded: true,
                recordsProcessed: $count,
                message: "{$stock->symbol}: {$count} dividend row(s) imported from nepalstock.com.",
            );

            return ['dividends' => $count];
        } catch (Throwable $e) {
            Log::warning('NEPSE official dividend fetch failed', ['symbol' => $stock->symbol, 'error' => $e->getMessage()]);

            ScrapeFinished::dispatch(
                source: self::SOURCE_NAME,
                succeeded: false,
                recordsProcessed: 0,
                message: "{$stock->symbol}: {$e->getMessage()}",
            );

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

            // Same payload also carries NEPSE's own listing tier (A/B/N —
            // based on paid-up capital, listing age, profit history and
            // credit rating) — a cheap, real "is this an established
            // company" signal, captured here rather than a separate fetch.
            $shareGroup = $application['companyNews']['security']['shareGroupId']['name'] ?? null;
            if ($shareGroup !== null && $stock->share_group === null) {
                $stock->update(['share_group' => $shareGroup]);
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
