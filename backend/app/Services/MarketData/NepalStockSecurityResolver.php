<?php

namespace App\Services\MarketData;

use App\Models\Stock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Resolves a stock's nepalstock.com internal numeric security ID, needed by
 * every official-API endpoint that's scoped to one security. Shared by
 * NepalStockHistoryService and NepalStockCorporateActionsService so the
 * securities-list cache key and matching logic can't drift between them.
 */
class NepalStockSecurityResolver
{
    private const BASE_URL = 'https://www.nepalstock.com';

    private const SECURITIES_PATH = '/api/nots/security?nonDelisted=true';

    private const SECURITY_DETAIL_PATH = '/api/nots/security/%d';

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome Safari';

    public function __construct(private readonly NepalStockTokenService $tokens) {}

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
     * The plain securities list has no sector field — only the richer
     * per-security detail endpoint carries it (as `securityData.sector`),
     * confirmed live against the real API.
     */
    public function fetchSector(Stock $stock): ?string
    {
        $securityId = $this->resolve($stock);
        $token = $this->tokens->getAccessToken();

        $response = Http::withHeaders([
            'User-Agent' => self::USER_AGENT,
            'Referer' => self::BASE_URL.'/',
            'Authorization' => 'Salter '.$token,
        ])->timeout(20)->get(self::BASE_URL.sprintf(self::SECURITY_DETAIL_PATH, $securityId));

        $response->throw();

        // Trimmed here, once, at the source — every call site matches this
        // against Sector.name via firstOrCreate(), and stray leading/trailing
        // whitespace from the API would otherwise create a near-duplicate
        // sector row instead of matching the existing one.
        $sector = trim((string) $response->json('securityData.sector'));

        return $sector !== '' ? $sector : null;
    }

    /**
     * Creates a `stocks` row for every security nepalstock.com knows about —
     * not just the ones that happen to trade on a given day. market:sync
     * (NepalStockScraperService) only ever creates a stock as a byproduct of
     * seeing it trade today, so an illiquid, suspended, or brand-new listing
     * that hasn't traded yet would otherwise never appear in this app at
     * all. Always fetches live (bypasses resolve()'s 6h cache — this is the
     * one place freshness actually matters, since the whole point is
     * noticing new listings promptly).
     *
     * @return array{total: int, created: int, existing: int}
     */
    public function syncAllSecurities(): array
    {
        $securities = $this->fetchSecuritiesList();
        $created = 0;
        $existing = 0;

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
        ];
    }

    /** @return list<array<string, mixed>> */
    private function fetchSecuritiesList(): array
    {
        $token = $this->tokens->getAccessToken();

        $response = Http::withHeaders([
            'User-Agent' => self::USER_AGENT,
            'Referer' => self::BASE_URL.'/',
            'Authorization' => 'Salter '.$token,
        ])->timeout(20)->get(self::BASE_URL.self::SECURITIES_PATH);

        $response->throw();

        return $response->json() ?? [];
    }
}
