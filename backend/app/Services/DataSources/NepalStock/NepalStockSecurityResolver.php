<?php

namespace App\Services\DataSources\NepalStock;

use App\Models\Sector;
use App\Models\Stock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Resolves a stock's nepalstock.com internal numeric security ID, needed by
 * every official-API endpoint that's scoped to one security (sector lookup
 * here, dividends in NepalStockCorporateActionsService) — one place, so the
 * securities-list cache key and matching logic can't drift between them.
 */
class NepalStockSecurityResolver
{
    private const SECURITIES_PATH = '/api/nots/security?nonDelisted=true';

    /** Every listed company with its sector (`sectorName`) and `instrumentType`, in one call. Matches stocks by symbol. */
    private const COMPANIES_PATH = '/api/nots/company/list';

    public function __construct(private readonly NepalStockClient $client) {}

    public function resolve(Stock $stock): int
    {
        if ($stock->nepse_security_id !== null) {
            return $stock->nepse_security_id;
        }

        $securities = Cache::remember('nepse_securities_list', now()->addHours(6), fn () => $this->fetchSecuritiesList());

        foreach ($securities as $security) {
            if (strtoupper((string) ($security['symbol'] ?? '')) === $stock->symbol) {
                $id = (int) $security['id'];
                $stock->update(['nepse_security_id' => $id]);

                return $id;
            }
        }

        throw new RuntimeException("Could not find [{$stock->symbol}] in nepalstock.com's securities list.");
    }

    /**
     * Creates a `stocks` row for every security nepalstock.com knows about —
     * not just the ones that happen to trade on a given day. The market sync
     * (DailyPriceSyncService) only ever creates a stock as a byproduct of
     * seeing it trade today, so an illiquid, suspended, or brand-new listing
     * that hasn't traded yet would otherwise never appear in this app at
     * all. Always fetches live (bypasses resolve()'s 6h cache — this is the
     * one place freshness actually matters, since the whole point is
     * noticing new listings promptly).
     *
     * Also takes two things from the companies list (one extra call, not one per stock): a sector for every
     * stock that has none yet (a stock that already has a sector is left alone) — and each
     * stock's instrument type (Equity, Mutual Fund…), kept in step with the list. If the companies list
     * can't be fetched the stock list sync still succeeds.
     *
     * Promoter / preference shares (symbols like ACLBSLP, BFCPO) are separate securities that are not in the
     * companies list; they take their sector from the parent company (see parentSector()). `sectors_inferred`
     * counts those — they are a naming-based guess, the one sector that is not read straight from NEPSE.
     *
     * @return array{total: int, created: int, existing: int, sectors_filled: int, sectors_inferred: int, types_set: int}
     */
    public function syncAllSecurities(): array
    {
        $securities = $this->fetchSecuritiesList();
        $companies = $this->fetchCompaniesBySymbol();
        $sectorIds = [];
        $created = 0;
        $existing = 0;
        $sectorsFilled = 0;
        $sectorsInferred = 0;
        $typesSet = 0;

        foreach ($securities as $security) {
            $symbol = strtoupper((string) ($security['symbol'] ?? ''));

            if ($symbol === '') {
                continue;
            }

            $stock = Stock::firstOrNew(['symbol' => $symbol]);
            $isNew = ! $stock->exists;

            if ($isNew) {
                $stock->company_name = $security['securityName'] ?? null;
                $stock->is_active = ($security['activeStatus'] ?? 'A') === 'A';
            }

            if (isset($security['id']) && $stock->nepse_security_id === null) {
                $stock->nepse_security_id = (int) $security['id'];
            }

            $sectorName = $companies[$symbol]['sector'] ?? null;
            $instrumentType = $companies[$symbol]['instrument_type'] ?? null;

            $inferred = false;

            // Not a company at all (so no sector of its own): a promoter / preference share takes its parent's.
            if ($stock->sector_id === null && ! isset($companies[$symbol])) {
                $sectorName = $this->parentSector($symbol, $companies);
                $inferred = $sectorName !== null;
            }

            if ($stock->sector_id === null && $sectorName !== null) {
                $stock->sector_id = $sectorIds[$sectorName] ??= Sector::firstOrCreate(['name' => $sectorName])->id;
                $sectorsFilled++;

                if ($inferred) {
                    $sectorsInferred++;
                }
            }

            if ($instrumentType !== null && $stock->instrument_type !== $instrumentType) {
                $stock->instrument_type = $instrumentType;
                $typesSet++;
            }

            if ($isNew || $stock->isDirty()) {
                $stock->save();
            }

            $isNew ? $created++ : $existing++;
        }

        // The cache resolve() reads is now stale the moment a new stock is
        // created above (it wouldn't have this symbol yet) — clear it so
        // the very next resolve() call re-fetches instead of missing it
        // for up to 6 hours.
        Cache::forget('nepse_securities_list');

        return [
            'total' => count($securities),
            'created' => $created,
            'existing' => $existing,
            'sectors_filled' => $sectorsFilled,
            'sectors_inferred' => $sectorsInferred,
            'types_set' => $typesSet,
        ];
    }

    /**
     * The sector of the company a promoter / preference share belongs to: its symbol is the company's
     * plus "P" or "PO" (ACLBSLP -> ACLBSL, BFCPO -> BFC). Null when no company matches, so it never
     * guesses for anything that is not clearly such a share.
     *
     * @param  array<string, array{sector: ?string, instrument_type: ?string}>  $companies
     */
    private function parentSector(string $symbol, array $companies): ?string
    {
        foreach (['PO', 'P'] as $suffix) {
            if (strlen($symbol) > strlen($suffix) + 1 && str_ends_with($symbol, $suffix)) {
                $parent = substr($symbol, 0, -strlen($suffix));

                if (isset($companies[$parent]) && $companies[$parent]['sector'] !== null) {
                    return $companies[$parent]['sector'];
                }
            }
        }

        return null;
    }

    /**
     * Symbol => ['sector' => ?string, 'instrument_type' => ?string] for every company nepalstock.com lists.
     * Empty (and logged) if the call fails: this is a bonus for the stock list sync, not a reason to fail it.
     *
     * @return array<string, array{sector: ?string, instrument_type: ?string}>
     */
    private function fetchCompaniesBySymbol(): array
    {
        try {
            $response = $this->client->get(self::COMPANIES_PATH);
            $response->throw();

            $map = [];

            foreach ($response->json() ?? [] as $company) {
                $symbol = strtoupper(trim((string) ($company['symbol'] ?? '')));
                // Trimmed: stray whitespace would create a near-duplicate sector instead of matching the existing one.
                $sector = trim((string) ($company['sectorName'] ?? ''));
                $type = trim((string) ($company['instrumentType'] ?? ''));

                if ($symbol !== '') {
                    $map[$symbol] = ['sector' => $sector !== '' ? $sector : null, 'instrument_type' => $type !== '' ? $type : null];
                }
            }

            return $map;
        } catch (Throwable $e) {
            Log::warning('NEPSE company list (sectors) could not be fetched', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /** @return list<array<string, mixed>> */
    private function fetchSecuritiesList(): array
    {
        $response = $this->client->get(self::SECURITIES_PATH);

        $response->throw();

        return $response->json() ?? [];
    }
}
